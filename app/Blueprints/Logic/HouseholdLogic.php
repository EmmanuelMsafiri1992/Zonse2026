<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Family & household: finishing a repeating chore puts the next one on the list for the same person,
 * and the pocket-money reward is earned when it is done. Family events in the past are marked done each
 * night. Buying a shopping item records its price, and the home page shows the list grouped by shop.
 */
class HouseholdLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'chores' && (float) ($payload['data']['reward'] ?? 0) < 0) {
            return ['data.reward' => 'The reward cannot be negative.'];
        }
        if ($entity->key === 'shopping' && (float) ($payload['amount'] ?? 0) < 0) {
            return ['amount' => 'The price cannot be negative.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'chores') {
            $record->due_on ??= today();
            $this->put($record, ['_earned' => $record->status === 'done' ? $this->number($record, 'reward') : 0]);
        }
    }

    /**
     * When a repeating chore comes round again.
     */
    protected function nextDue(Record $chore): ?Carbon
    {
        $from = ($chore->due_on ?? today())->copy();

        return match ($chore->value('frequency')) {
            'daily' => $from->addDay(),
            'weekly' => $from->addWeek(),
            'monthly' => $from->addMonthNoOverflow(),
            default => null,
        };
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'chores' && $record->status === 'to_do' => ['done' => ['label' => 'Done', 'icon' => 'check'], 'skip' => ['label' => 'Skip', 'icon' => 'skip-forward']],
            $record->entity === 'shopping' && $record->status === 'needed' => ['bought' => ['label' => 'Bought', 'icon' => 'shopping-basket', 'fields' => [['name' => 'amount', 'label' => 'Price paid', 'type' => 'number', 'value' => $record->amount]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'bought') {
            $price = $request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount;
            $record->update(['status' => 'bought', 'amount' => $price]);

            return 'Bought '.$record->title.($price !== null ? ' for '.$this->money($price) : '').'.';
        }
        $record->update(['status' => $action === 'done' ? 'done' : 'skipped']);
        $message = $record->title.($action === 'done' ? ' done by '.$record->value('family_member').($this->number($record, 'reward') > 0 ? ', earning '.$this->money($this->number($record, 'reward')) : '') : ' skipped');
        if ($next = $this->nextDue($record)) {
            Record::create([
                'workspace_id' => $record->workspace_id, 'branch_id' => $record->branch_id, 'blueprint' => $record->blueprint, 'entity' => 'chores',
                'title' => $record->title, 'status' => 'to_do', 'due_on' => $next, 'data' => array_intersect_key($record->data, array_flip(['family_member', 'frequency', 'reward'])),
                'created_by' => $request->user()->id,
            ]);
            $message .= '; next due '.$next->format('D d M');
        }

        return $message.'.';
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('events')->where('status', 'upcoming')->whereNotNull('occurs_on')->whereDate('occurs_on', '<', today()->toDateString())->get()
            ->each(fn (Record $event) => $event->update(['status' => 'done']))->count();
    }

    public function homeCards(): array
    {
        $chores = $this->records('chores')->where('status', 'to_do')->whereDate('due_on', '<=', today()->toDateString())->orderBy('due_on')->get();
        $events = $this->records('events')->where('status', 'upcoming')->whereDate('occurs_on', '>=', today()->toDateString())->whereDate('occurs_on', '<=', today()->addDays(7)->toDateString())->orderBy('occurs_on')->get();
        $shopping = $this->records('shopping')->where('status', 'needed')->get()->sortBy(fn (Record $item) => mb_strtolower((string) $item->value('shop')).' '.$item->title);

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Chores for today', 'icon' => 'brush-cleaning', 'empty' => 'No chores due.',
                'rows' => $chores->map(fn (Record $chore) => ['label' => $chore->title, 'sub' => $chore->value('family_member'), 'value' => $chore->due_on->isToday() ? 'today' : $chore->due_on->format('d M'), 'href' => $chore->url(), 'tone' => $chore->due_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'This week', 'icon' => 'calendar-heart', 'empty' => 'Nothing on the family calendar this week.',
                'rows' => $events->map(fn (Record $event) => ['label' => $event->title, 'sub' => collect([$event->value('who'), $event->value('place')])->filter()->implode(' · '), 'value' => $event->occurs_on->format('D d M').($event->value('time') ? ' '.$event->value('time') : ''), 'href' => $event->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Shopping list ('.$shopping->count().')', 'icon' => 'shopping-basket', 'empty' => 'The shopping list is empty.',
                'rows' => $shopping->map(fn (Record $item) => ['label' => $item->title, 'sub' => $item->value('shop') ?: 'Any shop', 'value' => $item->value('quantity'), 'href' => $item->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $chores = $this->records('chores')->whereNotNull('due_on')->whereDate('due_on', '>=', $from->toDateString())->whereDate('due_on', '<=', $to->toDateString())->get();
        $bought = $this->records('shopping')->where('status', 'bought')->whereDate('updated_at', '>=', $from->toDateString())->whereDate('updated_at', '<=', $to->toDateString())->get();

        return [
            ['title' => 'Chores by family member', 'columns' => ['Who', 'Done', 'Skipped', 'Still to do', 'Pocket money earned'], 'rows' => $chores
                ->groupBy(fn (Record $chore) => (string) $chore->value('family_member'))->sortKeys()
                ->map(fn ($group, string $who) => [$who, $group->where('status', 'done')->count(), $group->where('status', 'skipped')->count(), $group->where('status', 'to_do')->count(), $this->money($group->sum(fn (Record $chore) => $this->number($chore, '_earned')))])
                ->values()->all()],
            ['title' => 'Shopping by shop', 'columns' => ['Shop', 'Items bought', 'Spent'], 'rows' => $bought
                ->groupBy(fn (Record $item) => (string) ($item->value('shop') ?: 'Any shop'))->sortKeys()
                ->map(fn ($group, string $shop) => [$shop, $group->count(), $this->money($group->sum(fn (Record $item) => (float) $item->amount))])
                ->values()->all()],
        ];
    }
}
