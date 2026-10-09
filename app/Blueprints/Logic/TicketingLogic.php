<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ticketing: tickets sell only for events on sale and never beyond capacity, each gets a unique
 * code, complimentary tickets are free, and an event counts its own sales, revenue and check-ins,
 * flipping to sold out and back as tickets sell and refund. A ticket is checked in once, by its
 * code at the door, and never for a cancelled event.
 */
class TicketingLogic extends AppLogic
{
    public const LIVE = ['issued', 'checked_in'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'events') {
            $sold = $existing ? $this->linked('tickets', 'event', $existing)->whereIn('status', self::LIVE)->count() : 0;
            if ((int) ($data['capacity'] ?? 0) < 1) {
                $errors['data.capacity'] = 'Capacity is at least one.';
            } elseif ((int) $data['capacity'] < $sold) {
                $errors['data.capacity'] = $sold.' tickets are already sold.';
            }
            if (in_array($payload['status'], ['on_sale', 'sold_out'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today())) {
                $errors['status'] = 'An event in the past cannot be on sale.';
            }
            if ($payload['status'] === 'completed' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['status'] = 'The event has not happened yet.';
            }

            return $errors;
        }

        $event = ! empty($data['event']) ? $this->records('events')->find($data['event']) : null;
        $selling = $event && in_array($payload['status'], self::LIVE, true) && (! $existing || (int) $existing->value('event') !== $event->id || ! in_array($existing->status, self::LIVE, true));
        if ($selling && $event->status !== 'on_sale') {
            $errors['data.event'] = $event->title.' is '.str_replace('_', ' ', $event->status).'.';
        } elseif ($selling && $this->linked('tickets', 'event', $event)->whereIn('status', self::LIVE)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->count() >= (int) $event->value('capacity')) {
            $errors['data.event'] = $event->title.' is sold out.';
        }
        $code = strtoupper(trim((string) ($data['ticket_code'] ?? '')));
        if ($code !== '' && $this->records('tickets')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $ticket) => strtoupper(trim((string) $ticket->value('ticket_code'))) === $code)) {
            $errors['data.ticket_code'] = 'Code '.$code.' is already on another ticket.';
        }
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount < 0) {
            $errors['amount'] = 'The price cannot be negative.';
        } elseif (($data['tier'] ?? null) === 'complimentary' && $amount > 0) {
            $errors['amount'] = 'A complimentary ticket is free.';
        }
        if ($payload['status'] === 'checked_in' && $event && $event->status === 'cancelled') {
            $errors['status'] = $event->title.' is cancelled.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'tickets') {
            $record->occurs_on ??= today();
            $code = strtoupper(trim((string) $record->value('ticket_code')));
            if ($code === '') {
                do {
                    $code = 'TK-'.strtoupper(Str::random(6));
                } while ($this->records('tickets')->where('data->ticket_code', $code)->exists());
            }
            $this->put($record, [
                'ticket_code' => $code,
                'email' => filled($record->value('email')) ? mb_strtolower(trim((string) $record->value('email'))) : null,
                '_checked_in_at' => $record->status === 'checked_in' ? ($record->value('_checked_in_at') ?? now()->toDateTimeString()) : null,
            ]);

            return;
        }

        $tickets = $record->exists ? $this->linked('tickets', 'event', $record)->get() : collect();
        $live = $tickets->whereIn('status', self::LIVE);
        $remaining = max(0, (int) $record->value('capacity') - $live->count());
        if ($record->status === 'on_sale' && $remaining === 0 && (int) $record->value('capacity') > 0) {
            $record->status = 'sold_out';
        } elseif ($record->status === 'sold_out' && $remaining > 0) {
            $record->status = 'on_sale';
        }
        $this->put($record, [
            'tickets_sold' => $live->count(),
            '_remaining' => $remaining,
            '_checked_in' => $tickets->where('status', 'checked_in')->count(),
            '_revenue' => round($live->sum('amount'), 2),
            '_refunded' => round($tickets->where('status', 'refunded')->sum('amount'), 2),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'tickets') {
            $this->recalculate($this->parent($record, 'event'));
            $this->recalculate($this->previousParent($record, 'event'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'tickets') {
            $this->recalculate($this->parent($record, 'event'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'events') {
            $door = ['label' => 'Check in by code', 'icon' => 'scan-line', 'fields' => [
                ['name' => 'code', 'label' => 'Ticket code', 'type' => 'text'],
            ]];

            return match ($record->status) {
                'draft' => ['publish' => ['label' => 'Put on sale', 'icon' => 'tickets']],
                'on_sale', 'sold_out' => ['check_in_code' => $door, 'complete' => ['label' => 'Complete', 'icon' => 'check-circle'], 'cancel' => ['label' => 'Cancel event', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'? Sold tickets will need refunding.']],
                default => [],
            };
        }

        return match ($record->status) {
            'issued' => [
                'check_in' => ['label' => 'Check in', 'icon' => 'door-open'],
                'refund' => ['label' => 'Refund', 'icon' => 'undo', 'confirm' => 'Refund this ticket?'],
                'void' => ['label' => 'Void', 'icon' => 'ban', 'confirm' => 'Void this ticket?'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'publish':
                if ($record->occurs_on?->lt(today())) {
                    throw ValidationException::withMessages(['status' => 'An event in the past cannot be on sale.']);
                }
                $record->update(['status' => 'on_sale']);

                return $record->title.' is on sale: '.(int) $record->value('capacity').' tickets.';
            case 'complete':
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The event has not happened yet.']);
                }
                $record->update(['status' => 'completed']);

                return $record->title.' completed: '.(int) $record->value('_checked_in').' of '.(int) $record->value('tickets_sold').' ticket holders came.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled; '.(int) $record->value('tickets_sold').' tickets to refund.';
            case 'check_in_code':
                $code = strtoupper(trim($request->validate(['code' => ['required', 'string']])['code']));
                $ticket = $this->linked('tickets', 'event', $record)->get()->first(fn (Record $ticket) => strtoupper(trim((string) $ticket->value('ticket_code'))) === $code);
                if (! $ticket) {
                    throw ValidationException::withMessages(['code' => 'No ticket for '.$record->title.' has code '.$code.'.']);
                }
                if ($ticket->status !== 'issued') {
                    throw ValidationException::withMessages(['code' => $ticket->title.' ('.$code.') is already '.str_replace('_', ' ', $ticket->status).'.']);
                }

                return $this->checkIn($ticket);
            case 'refund':
                $record->update(['status' => 'refunded']);

                return 'Ticket '.$record->value('ticket_code').' refunded.';
            case 'void':
                $record->update(['status' => 'void']);

                return 'Ticket '.$record->value('ticket_code').' voided.';
        }

        return $this->checkIn($record);
    }

    protected function checkIn(Record $ticket): string
    {
        $event = $this->parent($ticket, 'event');
        if ($event && $event->status === 'cancelled') {
            throw ValidationException::withMessages(['status' => $event->title.' is cancelled.']);
        }
        $ticket->update(['status' => 'checked_in']);

        return $ticket->title.' checked in ('.ucfirst(str_replace('_', ' ', (string) $ticket->value('tier'))).').';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'events') {
            return [];
        }

        $tickets = $this->linked('tickets', 'event', $record)->get();
        $tiers = $this->app->entities['tickets']->field('tier')?->options ?? [];
        $live = $tickets->whereIn('status', self::LIVE);
        $sold = (int) $record->value('tickets_sold');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Box office', 'icon' => 'tickets', 'stats' => [
                ['label' => 'Sold', 'value' => $sold.' of '.(int) $record->value('capacity'), 'tone' => $record->status === 'sold_out' ? 'success' : null],
                ['label' => 'Remaining', 'value' => (string) (int) $record->value('_remaining'), 'tone' => (int) $record->value('_remaining') === 0 ? 'warning' : null],
                ['label' => 'Checked in', 'value' => (int) $record->value('_checked_in').($sold > 0 ? ' ('.round((int) $record->value('_checked_in') / $sold * 100).'%)' : '')],
                ['label' => 'Revenue', 'value' => $this->money($this->number($record, '_revenue'))],
                ['label' => 'Refunded', 'value' => $this->money($this->number($record, '_refunded')), 'tone' => $this->number($record, '_refunded') > 0 ? 'warning' : null],
                ['label' => 'Doors open', 'value' => $record->value('start_time') ?: '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Sales by tier', 'icon' => 'ticket', 'empty' => 'No tickets sold yet.',
                'rows' => collect($tiers)->map(fn (string $label, string $tier) => [
                    'label' => $label, 'sub' => $live->where('data.tier', $tier)->count().' sold · '.$tickets->where('data.tier', $tier)->where('status', 'checked_in')->count().' in', 'value' => $this->money($live->where('data.tier', $tier)->sum('amount')),
                ])->filter(fn (array $row) => ! str_starts_with($row['sub'], '0 sold'))->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $events = $this->records('events')->get();
        $tickets = $this->records('tickets')->get();
        $upcoming = $events->filter(fn (Record $event) => in_array($event->status, ['on_sale', 'sold_out'], true) && $event->occurs_on?->gte(today()))->sortBy('occurs_on');
        $month = $tickets->filter(fn (Record $ticket) => in_array($ticket->status, self::LIVE, true) && $ticket->occurs_on?->isCurrentMonth());
        $today = $events->filter(fn (Record $event) => $event->occurs_on?->isToday() && ! in_array($event->status, ['draft', 'cancelled'], true));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Ticketing', 'icon' => 'tickets', 'stats' => [
                ['label' => 'On sale', 'value' => (string) $events->where('status', 'on_sale')->count()],
                ['label' => 'Sold out', 'value' => (string) $events->where('status', 'sold_out')->count(), 'tone' => $events->where('status', 'sold_out')->isNotEmpty() ? 'success' : null],
                ['label' => 'Tickets this month', 'value' => (string) $month->count()],
                ['label' => 'Revenue this month', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'Events today', 'value' => (string) $today->count(), 'tone' => $today->isNotEmpty() ? 'warning' : null],
                ['label' => 'At the door today', 'value' => (string) $today->sum(fn (Record $event) => (int) $event->value('tickets_sold') - (int) $event->value('_checked_in'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming events', 'icon' => 'calendar-days', 'empty' => 'Nothing on sale.',
                'rows' => $upcoming->take(10)->map(fn (Record $event) => [
                    'label' => $event->title, 'sub' => $event->value('venue').' · '.$event->occurs_on->format('D d M'), 'value' => (int) $event->value('tickets_sold').' / '.(int) $event->value('capacity'), 'href' => $event->url(), 'tone' => $event->status === 'sold_out' ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $events = $this->dated('events', $from, $to)->get();
        $eventRows = $events->sortBy('occurs_on')->map(fn (Record $event) => [
            $event->title, $event->value('venue'), $event->occurs_on?->format('d M Y') ?? '—', ucfirst(str_replace('_', ' ', $event->status)), (int) $event->value('tickets_sold').' / '.(int) $event->value('capacity'), (int) $event->value('_checked_in'), $this->money((float) $event->value('_revenue')), $this->money((float) $event->value('_refunded')),
        ])->values()->all();

        $tickets = $this->dated('tickets', $from, $to)->get();
        $tiers = $this->app->entities['tickets']->field('tier')?->options ?? [];
        $byTier = collect($tiers)->map(fn (string $label, string $tier) => [
            $label, $tickets->where('data.tier', $tier)->whereIn('status', self::LIVE)->count(), $tickets->where('data.tier', $tier)->where('status', 'checked_in')->count(), $tickets->where('data.tier', $tier)->where('status', 'refunded')->count(), $this->money($tickets->where('data.tier', $tier)->whereIn('status', self::LIVE)->sum('amount')),
        ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($tickets) {
            $group = $tickets->filter(fn (Record $ticket) => $ticket->occurs_on?->format('Y-m') === $month);

            return [$label, $group->whereIn('status', self::LIVE)->count(), $group->where('status', 'refunded')->count(), $this->money($group->whereIn('status', self::LIVE)->sum('amount')), $this->money($group->where('status', 'refunded')->sum('amount'))];
        })->values()->all();

        return [
            ['title' => 'Events', 'columns' => ['Event', 'Venue', 'Date', 'Status', 'Sold', 'Checked in', 'Revenue', 'Refunded'], 'rows' => $eventRows],
            ['title' => 'Sales by tier', 'columns' => ['Tier', 'Sold', 'Checked in', 'Refunded', 'Revenue'], 'rows' => $byTier],
            ['title' => 'Sales by month', 'columns' => ['Month', 'Sold', 'Refunded', 'Revenue', 'Refunds'], 'rows' => $byMonth],
        ];
    }
}
