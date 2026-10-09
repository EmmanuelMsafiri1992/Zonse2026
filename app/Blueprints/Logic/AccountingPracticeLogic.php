<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Accounting practice: client filings need their submission reference to be filed and go
 * overdue on their own after the deadline; a filed return rolls forward into next period's
 * filing in one click; signed engagement letters expire on their renewal date.
 */
class AccountingPracticeLogic extends AppLogic
{
    /** Filings that come round every month; everything else is yearly. */
    public const MONTHLY = ['vat_return', 'paye'];

    public const OPEN = ['upcoming', 'in_progress', 'overdue'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'deadlines' && $payload['status'] === 'filed' && blank($payload['data']['submission_reference'] ?? null)) {
            return ['data.submission_reference' => 'Enter the submission reference to mark this filing as filed.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'deadlines' && in_array($record->status, ['upcoming', 'in_progress'], true) && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'overdue';
        }

        if ($record->entity === 'deadlines' && $record->status === 'filed' && ! $record->value('_filed_on')) {
            $this->put($record, ['_filed_on' => today()->toDateString()]);
        }

        if ($record->entity === 'engagements' && $record->status === 'signed' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $late = $this->records('deadlines')->whereIn('status', ['upcoming', 'in_progress'])->whereDate('due_on', '<', today())->get();
        $expired = $this->records('engagements')->where('status', 'signed')->whereDate('due_on', '<', today())->get();
        $late->concat($expired)->each(fn (Record $record) => $record->save());

        return $late->count() + $expired->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'deadlines' && $record->status === 'filed' && $record->due_on && ! $record->value('_next')) {
            return ['roll_forward' => ['label' => 'Set up next period', 'icon' => 'calendar-plus', 'confirm' => 'Create the next '.$this->typeLabel($record).' filing, due '.$this->nextDue($record)->format('d M Y').'?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $next = Record::create([
            'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'deadlines',
            'title' => $record->title, 'status' => 'upcoming', 'assignee_id' => $record->assignee_id, 'due_on' => $this->nextDue($record),
            'data' => ['client' => $record->value('client'), 'type' => $record->value('type')],
        ]);
        $record->update(['data' => array_merge((array) $record->data, ['_next' => $next->id])]);

        return 'Filing '.$next->number.' set up, due '.$next->due_on->format('d M Y').'.';
    }

    public function nextDue(Record $filing): Carbon
    {
        return in_array($filing->value('type'), self::MONTHLY, true) ? $filing->due_on->copy()->addMonthNoOverflow() : $filing->due_on->copy()->addYearNoOverflow();
    }

    protected function typeLabel(Record $filing): string
    {
        return str_replace('_', ' ', (string) $filing->value('type'));
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'clients') {
            return [];
        }

        $filings = $this->linked('deadlines', 'client', $record)->orderBy('due_on')->get();
        $open = $filings->whereIn('status', self::OPEN);
        $engagement = $this->linked('engagements', 'client', $record)->latest('id')->first();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Client', 'icon' => 'briefcase', 'stats' => [
                ['label' => 'Monthly fee', 'value' => $this->money($record->amount)],
                ['label' => 'Open filings', 'value' => (string) $open->count()],
                ['label' => 'Overdue', 'value' => (string) $open->where('status', 'overdue')->count(), 'tone' => $open->contains('status', 'overdue') ? 'danger' : null],
                ['label' => 'Engagement letter', 'value' => $engagement ? ucfirst($engagement->status) : 'None', 'tone' => ! $engagement || $engagement->status !== 'signed' ? 'warning' : 'success'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Filings due', 'icon' => 'calendar-clock', 'empty' => 'Nothing due for this client.',
                'rows' => $open->map(fn (Record $filing) => [
                    'label' => ucfirst($this->typeLabel($filing)).($filing->value('period') ? ' · '.$filing->value('period') : ''), 'sub' => $filing->number,
                    'value' => $filing->due_on?->format('d M Y') ?? '—', 'href' => $filing->url(), 'tone' => $filing->status === 'overdue' ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $names = $this->records('clients')->pluck('title', 'id');
        $due = $this->records('deadlines')->whereIn('status', self::OPEN)
            ->where(fn ($query) => $query->where('status', 'overdue')->orWhereDate('due_on', '<=', today()->addDays(14)))->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Filings due in the next 14 days', 'icon' => 'calendar-clock', 'empty' => 'No filings due in the next two weeks.',
            'rows' => $due->take(15)->map(fn (Record $filing) => [
                'label' => $names[$filing->value('client')] ?? $filing->title, 'sub' => ucfirst($this->typeLabel($filing)),
                'value' => $filing->status === 'overdue' ? 'Overdue' : ($filing->due_on?->format('d M') ?? '—'), 'href' => $filing->url(),
                'tone' => $filing->status === 'overdue' ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $filings = $this->records('deadlines')->whereBetween('due_on', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $byType = $filings->groupBy(fn (Record $filing) => (string) $filing->value('type'))->sortKeys()->map(function ($group, $type) {
            $filed = $group->where('status', 'filed');
            $onTime = $filed->filter(fn (Record $filing) => $filing->value('_filed_on') && $filing->due_on && Carbon::parse($filing->value('_filed_on'))->lte($filing->due_on));

            return [ucfirst(str_replace('_', ' ', $type)), $group->count(), $filed->count(), $group->where('status', 'overdue')->count(), $filed->count() ? round($onTime->count() / $filed->count() * 100).'%' : '—'];
        })->values()->all();

        $clients = $this->records('clients')->where('status', 'active')->orderBy('title')->get()
            ->map(fn (Record $client) => [$client->title, str_replace('_', ' ', (string) $client->value('entity_type')), $this->money($client->amount), $this->money((float) $client->amount * 12)])->all();

        return [
            ['title' => 'Filings by type', 'columns' => ['Type', 'Due in period', 'Filed', 'Overdue', 'Filed on time'], 'rows' => $byType],
            ['title' => 'Fee book', 'columns' => ['Client', 'Entity type', 'Monthly fee', 'A year'], 'rows' => $clients],
        ];
    }
}
