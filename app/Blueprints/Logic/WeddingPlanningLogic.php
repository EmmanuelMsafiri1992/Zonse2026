<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Wedding & event planning: a client event carries its budget, guests and planner fee, adds up what
 * its vendors cost and what they have been paid, and tracks the checklist. Vendors are booked only
 * on live events with a deposit no bigger than their cost, checklist items fall due before the
 * event, and an event is completed only once it has happened with its checklist done.
 */
class WeddingPlanningLogic extends AppLogic
{
    public const LIVE = ['enquiry', 'contracted', 'planning'];

    public const BOOKED = ['booked', 'deposit_paid', 'paid'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'events') {
            if (filled($data['guests'] ?? null) && (int) $data['guests'] < 0) {
                $errors['data.guests'] = 'Guests cannot be negative.';
            }
            if ((float) ($data['budget'] ?? 0) < 0) {
                $errors['data.budget'] = 'The budget cannot be negative.';
            }
            if ($payload['status'] === 'completed' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['status'] = 'The event has not happened yet.';
            }
            if (in_array($payload['status'], ['contracted', 'planning'], true) && blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Set the event date to contract it.';
            }

            return $errors;
        }

        $event = ! empty($data['event']) ? $this->records('events')->find($data['event']) : null;
        $joining = $event && (! $existing || (int) $existing->value('event') !== $event->id);
        if ($joining && ! in_array($event->status, self::LIVE, true)) {
            $errors['data.event'] = $event->title.' is '.$event->status.'.';
        }

        if ($entity->key === 'vendors') {
            $cost = (float) ($payload['amount'] ?? 0);
            $deposit = (float) ($data['deposit'] ?? 0);
            if ($cost < 0) {
                $errors['amount'] = 'The cost cannot be negative.';
            }
            if ($deposit < 0) {
                $errors['data.deposit'] = 'The deposit cannot be negative.';
            } elseif ($deposit > $cost) {
                $errors['data.deposit'] = 'The deposit is more than the cost.';
            } elseif ($payload['status'] === 'deposit_paid' && $deposit <= 0) {
                $errors['data.deposit'] = 'Enter the deposit paid.';
            }
            if ($event && filled($payload['due_on'] ?? null) && $event->occurs_on && Carbon::parse($payload['due_on'])->gt($event->occurs_on)) {
                $errors['due_on'] = 'The balance is due by the event date, '.$event->occurs_on->format('d M Y').'.';
            }

            return $errors;
        }

        if ($event && filled($payload['due_on'] ?? null) && $event->occurs_on && Carbon::parse($payload['due_on'])->gt($event->occurs_on)) {
            $errors['due_on'] = 'The task is due before the event on '.$event->occurs_on->format('d M Y').'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'vendors') {
            $cost = (float) $record->amount;
            $paid = match ($record->status) {
                'paid' => $cost,
                'deposit_paid' => min($cost, (float) $record->value('deposit')),
                default => 0.0,
            };
            $this->put($record, ['_paid' => round($paid, 2), '_balance' => round($cost - $paid, 2)]);

            return;
        }

        if ($record->entity === 'checklist') {
            $this->put($record, ['_done_on' => $record->status === 'done' ? ($record->value('_done_on') ?? today()->toDateString()) : null]);

            return;
        }

        $vendors = $record->exists ? $this->linked('vendors', 'event', $record)->get()->whereIn('status', self::BOOKED) : collect();
        $tasks = $record->exists ? $this->linked('checklist', 'event', $record)->get() : collect();
        $budget = (float) $record->value('budget');
        $cost = $vendors->sum('amount');
        $done = $tasks->where('status', 'done')->count();
        $this->put($record, [
            '_vendor_cost' => round($cost, 2),
            '_vendor_paid' => round($vendors->sum(fn (Record $vendor) => (float) $vendor->value('_paid')), 2),
            '_vendor_balance' => round($vendors->sum(fn (Record $vendor) => (float) $vendor->value('_balance')), 2),
            '_budget_left' => round($budget - $cost, 2),
            '_over_budget' => $budget > 0 && $cost > $budget,
            '_tasks' => $tasks->count(),
            '_tasks_done' => $done,
            '_progress' => $tasks->isEmpty() ? 0 : (int) round($done / $tasks->count() * 100),
            '_overdue_tasks' => $tasks->filter(fn (Record $task) => $task->status === 'to_do' && $task->due_on?->lt(today()))->count(),
            '_days_to_go' => $record->occurs_on && in_array($record->status, self::LIVE, true) ? (int) today()->diffInDays($record->occurs_on, false) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'events') {
            $this->recalculate($this->parent($record, 'event'));
            $this->recalculate($this->previousParent($record, 'event'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'events') {
            $this->recalculate($this->parent($record, 'event'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'events') {
            return match ($record->status) {
                'enquiry' => ['contract' => ['label' => 'Contract', 'icon' => 'file-signature', 'fields' => [
                    ['name' => 'occurs_on', 'label' => 'Event date', 'type' => 'date', 'value' => $record->occurs_on?->toDateString()],
                    ['name' => 'amount', 'label' => 'Planner fee', 'type' => 'number', 'value' => $record->amount],
                ]], 'cancel' => ['label' => 'Lost', 'icon' => 'x']],
                'contracted' => ['start_planning' => ['label' => 'Start planning', 'icon' => 'list-checks'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'?']],
                'planning' => ['complete' => ['label' => 'Completed', 'icon' => 'party-popper'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'?']],
                default => [],
            };
        }

        if ($record->entity === 'vendors') {
            return match ($record->status) {
                'shortlisted' => ['book' => ['label' => 'Book', 'icon' => 'check', 'fields' => [['name' => 'amount', 'label' => 'Cost', 'type' => 'number', 'value' => $record->amount]]], 'cancel' => ['label' => 'Drop', 'icon' => 'x']],
                'booked' => ['pay_deposit' => ['label' => 'Deposit paid', 'icon' => 'banknote', 'fields' => [['name' => 'deposit', 'label' => 'Deposit', 'type' => 'number', 'value' => $record->value('deposit')]]], 'pay' => ['label' => 'Paid in full', 'icon' => 'badge-check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking?']],
                'deposit_paid' => ['pay' => ['label' => 'Balance paid', 'icon' => 'badge-check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking? The deposit may be lost.']],
                default => [],
            };
        }

        return $record->status === 'to_do'
            ? ['done' => ['label' => 'Done', 'icon' => 'check']]
            : ['reopen' => ['label' => 'Not done', 'icon' => 'undo']];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'contract':
                $input = $request->validate(['occurs_on' => ['required', 'date', 'after_or_equal:today'], 'amount' => ['nullable', 'numeric', 'min:0']]);
                $record->update(['status' => 'contracted', 'occurs_on' => Carbon::parse($input['occurs_on']), 'amount' => $input['amount'] ?? $record->amount]);

                return $record->title.' contracted for '.Carbon::parse($input['occurs_on'])->format('d M Y').'.';
            case 'start_planning':
                $record->update(['status' => 'planning']);

                return 'Planning '.$record->title.'.';
            case 'complete':
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The event has not happened yet.']);
                }
                $open = $this->linked('checklist', 'event', $record)->where('status', 'to_do')->count();
                if ($open > 0) {
                    throw ValidationException::withMessages(['status' => $open.' checklist items are still to do.']);
                }
                $record->update(['status' => 'completed']);

                return $record->title.' completed.';
            case 'book':
                $amount = $request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount;
                $record->update(['status' => 'booked', 'amount' => $amount]);

                return $record->title.' booked for '.str_replace('_', ' ', (string) $record->value('service')).'.';
            case 'pay_deposit':
                $deposit = (float) $request->validate(['deposit' => ['required', 'numeric', 'min:0.01']])['deposit'];
                if ($deposit > (float) $record->amount) {
                    throw ValidationException::withMessages(['deposit' => 'The deposit is more than the cost.']);
                }
                $record->update(['status' => 'deposit_paid', 'data' => [...$record->data, 'deposit' => $deposit]]);

                return 'Deposit paid to '.$record->title.'.';
            case 'pay':
                $record->update(['status' => 'paid']);

                return $record->title.' paid in full.';
            case 'done':
                $record->update(['status' => 'done']);

                return $record->title.' done.';
            case 'reopen':
                $record->update(['status' => 'to_do']);

                return $record->title.' is back on the list.';
        }

        $record->update(['status' => 'cancelled']);

        return $record->entity === 'events' ? $record->title.' cancelled.' : $record->title.' booking cancelled.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'events') {
            return [];
        }

        $vendors = $this->linked('vendors', 'event', $record)->where('status', '!=', 'cancelled')->orderBy('title')->get();
        $tasks = $this->linked('checklist', 'event', $record)->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Event', 'icon' => 'heart', 'stats' => [
                ['label' => 'Date', 'value' => $record->occurs_on?->format('d M Y') ?? 'Not set'],
                ['label' => 'Days to go', 'value' => $record->value('_days_to_go') === null ? '—' : (string) (int) $record->value('_days_to_go'), 'tone' => $record->value('_days_to_go') !== null && (int) $record->value('_days_to_go') <= 14 ? 'warning' : null],
                ['label' => 'Guests', 'value' => (string) (int) $record->value('guests')],
                ['label' => 'Budget', 'value' => $this->money($this->number($record, 'budget'))],
                ['label' => 'Vendors cost', 'value' => $this->money($this->number($record, '_vendor_cost')), 'tone' => $record->value('_over_budget') ? 'danger' : null],
                ['label' => 'Vendors owed', 'value' => $this->money($this->number($record, '_vendor_balance')), 'tone' => $this->number($record, '_vendor_balance') > 0 ? 'warning' : null],
                ['label' => 'Checklist', 'value' => (int) $record->value('_tasks_done').' of '.(int) $record->value('_tasks').' done ('.(int) $record->value('_progress').'%)', 'tone' => (int) $record->value('_overdue_tasks') > 0 ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Vendors', 'icon' => 'store', 'empty' => 'No vendors yet.',
                'rows' => $vendors->take(15)->map(fn (Record $vendor) => [
                    'label' => $vendor->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $vendor->value('service'))).' · '.ucfirst(str_replace('_', ' ', $vendor->status)), 'value' => $this->money($vendor->amount).' · owes '.$this->money($this->number($vendor, '_balance')), 'href' => $vendor->url(), 'tone' => $vendor->status === 'paid' ? 'success' : ($vendor->status === 'shortlisted' ? null : 'warning'),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Checklist', 'icon' => 'list-checks', 'empty' => 'Nothing on the checklist.',
                'rows' => $tasks->take(20)->map(fn (Record $task) => [
                    'label' => $task->title, 'sub' => $task->due_on ? 'Due '.$task->due_on->format('d M Y') : 'No due date', 'value' => $task->status === 'done' ? 'Done' : 'To do', 'href' => $task->url(), 'tone' => $task->status === 'done' ? 'success' : ($task->due_on?->lt(today()) ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $events = $this->records('events')->get();
        $live = $events->whereIn('status', self::LIVE);
        $upcoming = $live->filter(fn (Record $event) => $event->occurs_on?->gte(today()))->sortBy('occurs_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Events', 'icon' => 'heart', 'stats' => [
                ['label' => 'Enquiries', 'value' => (string) $events->where('status', 'enquiry')->count()],
                ['label' => 'In planning', 'value' => (string) $events->whereIn('status', ['contracted', 'planning'])->count()],
                ['label' => 'Next 30 days', 'value' => (string) $upcoming->filter(fn (Record $event) => $event->occurs_on->lte(today()->addDays(30)))->count()],
                ['label' => 'Planner fees booked', 'value' => $this->money($live->whereIn('status', ['contracted', 'planning'])->sum('amount'))],
                ['label' => 'Vendors owed', 'value' => $this->money($live->sum(fn (Record $event) => (float) $event->value('_vendor_balance')))],
                ['label' => 'Overdue tasks', 'value' => (string) $live->sum(fn (Record $event) => (int) $event->value('_overdue_tasks')), 'tone' => $live->sum(fn (Record $event) => (int) $event->value('_overdue_tasks')) > 0 ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming events', 'icon' => 'calendar-days', 'empty' => 'No upcoming events.',
                'rows' => $upcoming->take(10)->map(fn (Record $event) => [
                    'label' => $event->title, 'sub' => ucfirst((string) $event->value('type')).($event->value('venue') ? ' · '.$event->value('venue') : '').' · '.(int) $event->value('_progress').'% ready', 'value' => $event->occurs_on->format('d M Y'), 'href' => $event->url(), 'tone' => (int) $event->value('_overdue_tasks') > 0 ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $events = $this->dated('events', $from, $to)->get();
        $types = $this->app->entities['events']->field('type')?->options ?? [];
        $eventRows = $events->sortBy('occurs_on')->map(fn (Record $event) => [
            $event->title, $types[$event->value('type')] ?? ucfirst((string) $event->value('type')), $event->occurs_on?->format('d M Y') ?? '—', ucfirst($event->status), (int) $event->value('guests'), $this->money((float) $event->value('budget')), $this->money((float) $event->value('_vendor_cost')), $this->money($event->amount), (int) $event->value('_progress').'%',
        ])->values()->all();

        $vendors = $this->records('vendors')->get()->whereIn('status', self::BOOKED);
        $services = $this->app->entities['vendors']->field('service')?->options ?? [];
        $byService = collect($services)->map(fn (string $label, string $service) => [
            $label, $vendors->where('data.service', $service)->count(), $this->money($vendors->where('data.service', $service)->sum('amount')), $this->money($vendors->where('data.service', $service)->sum(fn (Record $vendor) => (float) $vendor->value('_balance'))),
        ])->filter(fn (array $row) => $row[1] > 0)->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($events) {
            $group = $events->filter(fn (Record $event) => $event->occurs_on?->format('Y-m') === $month && $event->status !== 'cancelled');

            return [$label, $group->count(), $group->sum(fn (Record $event) => (int) $event->value('guests')), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $event) => (float) $event->value('_vendor_cost')))];
        })->values()->all();

        return [
            ['title' => 'Events', 'columns' => ['Event', 'Type', 'Date', 'Status', 'Guests', 'Budget', 'Vendors', 'Planner fee', 'Ready'], 'rows' => $eventRows],
            ['title' => 'Vendors by service', 'columns' => ['Service', 'Bookings', 'Cost', 'Still owed'], 'rows' => $byService],
            ['title' => 'Events by month', 'columns' => ['Month', 'Events', 'Guests', 'Planner fees', 'Vendor spend'], 'rows' => $byMonth],
        ];
    }
}
