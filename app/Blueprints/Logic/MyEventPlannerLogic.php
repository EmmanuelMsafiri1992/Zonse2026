<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * My wedding / event planner: a guest's party counts as one person when left blank, and only attending guests count
 * towards the headcount. A budget line's deposit can't be more than its cost, it is paid only once the
 * actual cost is known, and it shows how far it went over or under the estimate. The home page tracks
 * headcount, replies still awaited, money still to pay and the next to-dos.
 */
class MyEventPlannerLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'guests' && (float) ($data['party_size'] ?? 0) < 0) {
            $errors['data.party_size'] = 'The party size cannot be negative.';
        }
        if ($entity->key === 'budget') {
            $cost = (float) ($payload['amount'] ?? 0) ?: (float) ($data['estimated'] ?? 0);
            if ((float) ($data['deposit'] ?? 0) > 0 && $cost > 0 && (float) $data['deposit'] > $cost) {
                $errors['data.deposit'] = 'The deposit cannot be more than the cost of '.$this->money($cost).'.';
            }
            if ($payload['status'] === 'paid' && (float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Enter the actual cost before marking it paid.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'guests') {
            $party = max(1, (int) $this->number($record, 'party_size'));
            $this->put($record, ['party_size' => $party, '_coming' => $record->status === 'attending' ? $party : 0]);
        }
        if ($record->entity === 'budget') {
            $cost = (float) $record->amount ?: $this->number($record, 'estimated');
            $this->put($record, [
                '_variance' => (float) $record->amount > 0 && $this->number($record, 'estimated') > 0 ? round((float) $record->amount - $this->number($record, 'estimated'), 2) : null,
                '_to_pay' => $record->status === 'paid' ? 0 : round(max(0, $cost - $this->number($record, 'deposit')), 2),
            ]);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'guests' && in_array($record->status, ['invited', 'no_reply'], true) => ['attending' => ['label' => 'Attending', 'icon' => 'check', 'fields' => [['name' => 'party_size', 'label' => 'How many', 'type' => 'number', 'value' => $record->value('party_size') ?: 1]]], 'declined' => ['label' => 'Declined', 'icon' => 'x']],
            $record->entity === 'budget' && $record->status !== 'paid' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Actual cost', 'type' => 'number', 'value' => $record->amount ?: $record->value('estimated')]]]],
            $record->entity === 'todos' && $record->status === 'to_do' => ['done' => ['label' => 'Done', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'attending':
                $party = (int) ($request->validate(['party_size' => ['nullable', 'integer', 'min:1', 'max:50']])['party_size'] ?? ($record->value('party_size') ?: 1));
                $record->update(['status' => 'attending', 'data' => [...$record->data, 'party_size' => $party]]);

                return $record->title.' is coming'.($party > 1 ? ' with '.($party - 1).' more' : '').'. Headcount: '.$this->headcount().'.';
            case 'declined':
                $record->update(['status' => 'declined']);

                return $record->title.' can\'t make it.';
            case 'pay':
                $cost = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
                if ($this->number($record, 'deposit') > $cost) {
                    throw ValidationException::withMessages(['amount' => 'The deposit already paid is '.$this->money($this->number($record, 'deposit')).'.']);
                }
                $record->update(['status' => 'paid', 'amount' => $cost]);
                $variance = $record->value('_variance');

                return $record->title.' paid: '.$this->money($cost).($variance ? ', '.$this->money(abs($variance)).($variance > 0 ? ' over' : ' under').' the estimate' : '').'.';
            default:
                $record->update(['status' => 'done']);

                return $record->title.' done.';
        }
    }

    protected function headcount(): int
    {
        return (int) $this->records('guests')->where('status', 'attending')->get()->sum(fn (Record $guest) => $this->number($guest, '_coming'));
    }

    public function homeCards(): array
    {
        $guests = $this->records('guests')->get();
        $budget = $this->records('budget')->get();
        $todos = $this->records('todos')->where('status', 'to_do')->orderByRaw('due_on is null')->orderBy('due_on')->limit(8)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'At a glance', 'icon' => 'heart', 'stats' => [
                ['label' => 'Coming', 'value' => $this->headcount()],
                ['label' => 'Awaiting reply', 'value' => $guests->whereIn('status', ['invited', 'no_reply'])->count(), 'tone' => $guests->whereIn('status', ['invited', 'no_reply'])->isNotEmpty() ? 'warning' : null],
                ['label' => 'Budget', 'value' => $this->money($budget->sum(fn (Record $line) => (float) $line->amount ?: $this->number($line, 'estimated')))],
                ['label' => 'Still to pay', 'value' => $this->money($budget->sum(fn (Record $line) => $this->number($line, '_to_pay')))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Next to-dos', 'icon' => 'list-checks', 'empty' => 'Everything is done.',
                'rows' => $todos->map(fn (Record $todo) => ['label' => $todo->title, 'sub' => $todo->value('who'), 'value' => $todo->due_on?->format('d M') ?? '—', 'href' => $todo->url(), 'tone' => $todo->due_on && $todo->due_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $guests = $this->records('guests')->get();
        $budget = $this->records('budget')->get();

        return [
            ['title' => 'Guests by side', 'columns' => ['Side', 'Invited', 'Coming', 'Declined', 'No reply yet'], 'rows' => $guests
                ->groupBy(fn (Record $guest) => ucfirst((string) ($guest->value('side') ?: 'other')))->sortKeys()
                ->map(fn ($group, string $side) => [$side, $group->sum(fn (Record $guest) => $this->number($guest, 'party_size')), $group->sum(fn (Record $guest) => $this->number($guest, '_coming')), $group->where('status', 'declined')->count(), $group->whereIn('status', ['invited', 'no_reply'])->count()])
                ->values()->all()],
            ['title' => 'Budget by category', 'columns' => ['Category', 'Estimated', 'Actual', 'Difference', 'Still to pay'], 'rows' => $budget
                ->groupBy(fn (Record $line) => ucfirst((string) $line->value('category')))->sortKeys()
                ->map(fn ($group, string $category) => [$category, $this->money($group->sum(fn (Record $line) => $this->number($line, 'estimated'))), $this->money($group->sum(fn (Record $line) => (float) $line->amount)),
                    $this->money($group->sum(fn (Record $line) => $this->number($line, '_variance'))), $this->money($group->sum(fn (Record $line) => $this->number($line, '_to_pay')))])
                ->values()->all()],
        ];
    }
}
