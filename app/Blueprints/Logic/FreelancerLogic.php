<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Freelancer workspace: work starts only once the contract is signed, a paid gig has an invoice
 * number (never reused), and time is only logged on gigs under way. Hourly and daily gigs are
 * valued from the time logged (8 hours to a day); "Mark paid" records the invoice and marks the
 * gig's time billed.
 */
class FreelancerLogic extends AppLogic
{
    public const SIGNED_STAGES = ['contracted', 'in_progress', 'delivered', 'paid'];

    public const HOURS_PER_DAY = 8;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'gigs') {
            if (in_array($payload['status'], self::SIGNED_STAGES, true) && empty($data['contract_signed'])) {
                $errors['data.contract_signed'] = 'Get the contract signed before starting work.';
            }
            if ($payload['status'] === 'paid' && blank($data['invoice_number'] ?? null)) {
                $errors['data.invoice_number'] = 'Record the invoice that was paid.';
            }
            if (filled($data['invoice_number'] ?? null) && $this->records('gigs')->where('data->invoice_number', $data['invoice_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.invoice_number'] = 'Invoice '.$data['invoice_number'].' is already used on another gig.';
            }
            if ($payload['status'] === 'proposal_sent' && blank($data['proposal'] ?? null)) {
                $errors['data.proposal'] = 'Add the proposal you sent.';
            }

            return $errors;
        }

        $gig = ! empty($data['gig']) ? $this->records('gigs')->find($data['gig']) : null;
        if ($gig && ! in_array($gig->status, ['contracted', 'in_progress', 'delivered'], true) && (! $existing || (int) $existing->value('gig') !== $gig->id)) {
            $errors['data.gig'] = 'Time can only be logged on gigs under way ('.$gig->title.' is '.str_replace('_', ' ', $gig->status).').';
        }
        $hours = (float) ($data['hours'] ?? 0);
        if ($hours <= 0 || $hours > 24) {
            $errors['data.hours'] = 'Log between 0 and 24 hours.';
        }
        if ($existing?->status === 'billed' && (float) $existing->value('hours') !== $hours) {
            $errors['data.hours'] = 'This time is already billed.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'time') {
            $record->occurs_on ??= today();

            return;
        }

        $logs = $record->exists ? $this->linked('time', 'gig', $record)->get() : collect();
        $hours = (float) $logs->sum(fn (Record $log) => $this->number($log, 'hours'));
        $this->put($record, ['_hours' => $hours, '_unbilled_hours' => (float) $logs->where('status', 'unbilled')->sum(fn (Record $log) => $this->number($log, 'hours'))]);

        $rate = $this->number($record, 'rate');
        $record->amount = match ($record->value('rate_type')) {
            'hourly' => $logs->isNotEmpty() ? round($hours * $rate, 2) : $record->amount,
            'daily' => $logs->isNotEmpty() ? round($hours / self::HOURS_PER_DAY * $rate, 2) : $record->amount,
            default => $record->amount ?? ($rate > 0 ? $rate : null),
        };
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'time') {
            $this->recalculate($this->parent($record, 'gig'));
            $this->recalculate($this->previousParent($record, 'gig'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'time') {
            $this->recalculate($this->parent($record, 'gig'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'gigs' && $record->status === 'delivered') {
            return ['mark_paid' => ['label' => 'Mark paid', 'icon' => 'banknote', 'fields' => [['name' => 'invoice_number', 'label' => 'Invoice number', 'type' => 'text']]]];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $invoice = $request->validate(['invoice_number' => ['required', 'string', 'max:50']])['invoice_number'];
        if ($this->records('gigs')->where('data->invoice_number', $invoice)->whereKeyNot($record->id)->exists()) {
            throw ValidationException::withMessages(['invoice_number' => 'Invoice '.$invoice.' is already used on another gig.']);
        }

        foreach ($this->linked('time', 'gig', $record)->where('status', 'unbilled')->get() as $log) {
            $log->update(['status' => 'billed']);
        }
        $record->refresh();
        $record->update(['status' => 'paid', 'data' => [...(array) $record->data, 'invoice_number' => $invoice]]);

        return $record->title.' is paid ('.$this->money($record->amount).').';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'gigs') {
            return [];
        }

        $hours = (float) $record->value('_hours');
        $effective = $hours > 0 && $record->amount ? $this->money($record->amount / $hours).' / hour' : '—';

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Gig', 'icon' => 'laptop', 'stats' => [
            ['label' => 'Hours logged', 'value' => (string) $hours],
            ['label' => 'Unbilled hours', 'value' => (string) (float) $record->value('_unbilled_hours')],
            ['label' => 'Value', 'value' => $record->amount !== null ? $this->money($record->amount) : '—'],
            ['label' => 'Effective rate', 'value' => $effective],
        ]]]];
    }

    public function homeCards(): array
    {
        $gigs = $this->records('gigs')->with('contact')->get();
        $pipeline = $gigs->whereIn('status', ['lead', 'proposal_sent']);
        $active = $gigs->whereIn('status', ['contracted', 'in_progress']);
        $unpaid = $gigs->where('status', 'delivered');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Work', 'icon' => 'laptop', 'stats' => [
                ['label' => 'In the pipeline', 'value' => $this->money($pipeline->sum('amount'))],
                ['label' => 'Active gigs', 'value' => (string) $active->count()],
                ['label' => 'Delivered, unpaid', 'value' => $this->money($unpaid->sum('amount')), 'tone' => $unpaid->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Deadlines', 'icon' => 'calendar-clock', 'empty' => 'No deadlines coming up.',
                'rows' => $active->filter(fn (Record $gig) => $gig->due_on !== null)->sortBy('due_on')->map(fn (Record $gig) => [
                    'label' => $gig->title, 'sub' => $gig->contact?->name, 'value' => $gig->due_on->format('d M'), 'href' => $gig->url(),
                    'tone' => $gig->due_on->lt(today()) ? 'danger' : ($gig->due_on->lte(today()->addDays(3)) ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $gigs = $this->records('gigs')->with('contact')->get();
        $paid = $gigs->where('status', 'paid');

        $clients = $gigs->groupBy(fn (Record $gig) => $gig->contact?->name ?? 'No client')->sortKeys()->map(fn ($group, $client) => [
            $client, $group->count(), $this->money($group->where('status', 'paid')->sum('amount')), $this->money($group->whereIn('status', ['contracted', 'in_progress', 'delivered'])->sum('amount')),
        ])->values()->all();

        $logs = $this->dated('time', $from, $to)->get();
        $titles = $gigs->pluck('title', 'id');
        $hours = $logs->groupBy(fn (Record $log) => $titles[$log->value('gig')] ?? 'Unknown gig')->sortKeys()->map(fn ($group, $gig) => [
            $gig, (float) $group->sum(fn (Record $log) => $this->number($log, 'hours')), (float) $group->where('status', 'unbilled')->sum(fn (Record $log) => $this->number($log, 'hours')),
        ])->values()->all();

        return [
            ['title' => 'Income by client', 'columns' => ['Client', 'Gigs', 'Paid', 'Contracted, not paid'], 'rows' => $clients, 'note' => 'Paid in total: '.$this->money($paid->sum('amount')).'.'],
            ['title' => 'Hours by gig', 'columns' => ['Gig', 'Hours', 'Unbilled'], 'rows' => $hours],
        ];
    }
}
