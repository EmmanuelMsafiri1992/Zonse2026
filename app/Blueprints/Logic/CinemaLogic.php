<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Cinema & theatre: seats are booked only for shows on sale, never the same seat twice and never
 * beyond the auditorium, and a show counts its seats sold and flips to sold out and back as
 * bookings come and go. A booking is paid before its tickets are collected, and a show is completed
 * only once it has screened.
 */
class CinemaLogic extends AppLogic
{
    public const HELD = ['reserved', 'paid', 'collected'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'shows') {
            $sold = $existing ? $this->seatsTaken($existing, null)->count() : 0;
            if ((int) ($data['seats'] ?? 0) < 1) {
                $errors['data.seats'] = 'Enter the number of seats in '.($data['screen'] ?? 'the auditorium').'.';
            } elseif ((int) $data['seats'] < $sold) {
                $errors['data.seats'] = $sold.' seats are already sold.';
            }
            if ($payload['status'] === 'completed' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['status'] = 'The show has not screened yet.';
            }
            if (in_array($payload['status'], ['on_sale', 'sold_out'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today())) {
                $errors['status'] = 'A show in the past cannot be on sale.';
            }

            return $errors;
        }

        $show = ! empty($data['show']) ? $this->records('shows')->find($data['show']) : null;
        $seats = self::seats($data['seats_booked'] ?? null);
        $holding = in_array($payload['status'], self::HELD, true);
        $new = $show && (! $existing || (int) $existing->value('show') !== $show->id || ! in_array($existing->status, self::HELD, true));
        if ($show && $holding && $new && $show->status !== 'on_sale') {
            $errors['data.show'] = $show->title.' is '.str_replace('_', ' ', $show->status).'.';
        }
        if ($seats === []) {
            $errors['data.seats_booked'] = 'List the seats, for example F7, F8.';
        } elseif ($show && $holding) {
            $taken = $this->seatsTaken($show, $existing)->intersect($seats);
            if ($taken->isNotEmpty()) {
                $errors['data.seats_booked'] = 'Seat '.$taken->implode(', ').' is already taken.';
            } elseif ($this->seatsTaken($show, $existing)->count() + count($seats) > (int) $show->value('seats')) {
                $errors['data.seats_booked'] = 'Only '.max(0, (int) $show->value('seats') - $this->seatsTaken($show, $existing)->count()).' seats are left for '.$show->title.'.';
            }
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The total cannot be negative.';
        }
        if ($payload['status'] === 'collected' && $existing && ! in_array($existing->status, ['paid', 'collected'], true)) {
            $errors['status'] = 'Tickets are collected once the booking is paid.';
        }

        return $errors;
    }

    /**
     * The seats in a booking's list, uppercased and without repeats.
     *
     * @return list<string>
     */
    public static function seats(?string $list): array
    {
        return collect(preg_split('/[\s,;]+/', strtoupper((string) $list)) ?: [])->map(fn (string $seat) => trim($seat))->filter()->unique()->values()->all();
    }

    /** Every seat held on a show by its live bookings, leaving out one booking being edited. */
    protected function seatsTaken(Record $show, ?Record $except)
    {
        return $this->linked('bookings', 'show', $show)->whereIn('status', self::HELD)->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->flatMap(fn (Record $booking) => self::seats($booking->value('seats_booked')))->unique()->values();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $record->occurs_on ??= today();
            $seats = self::seats($record->value('seats_booked'));
            $this->put($record, [
                'seats_booked' => implode(', ', $seats),
                '_seat_count' => count($seats),
                '_paid_on' => in_array($record->status, ['paid', 'collected'], true) ? ($record->value('_paid_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $bookings = $record->exists ? $this->linked('bookings', 'show', $record)->get() : collect();
        $held = $bookings->whereIn('status', self::HELD);
        $sold = $held->sum(fn (Record $booking) => count(self::seats($booking->value('seats_booked'))));
        $remaining = max(0, (int) $record->value('seats') - $sold);
        if ($record->status === 'on_sale' && $remaining === 0 && (int) $record->value('seats') > 0) {
            $record->status = 'sold_out';
        } elseif ($record->status === 'sold_out' && $remaining > 0) {
            $record->status = 'on_sale';
        }
        $this->put($record, [
            'seats_sold' => $sold,
            '_remaining' => $remaining,
            '_occupancy' => (int) $record->value('seats') > 0 ? (int) round($sold / (int) $record->value('seats') * 100) : 0,
            '_takings' => round($held->whereIn('status', ['paid', 'collected'])->sum('amount'), 2),
            '_unpaid' => $held->where('status', 'reserved')->count(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $this->recalculate($this->parent($record, 'show'));
            $this->recalculate($this->previousParent($record, 'show'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $this->recalculate($this->parent($record, 'show'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'shows') {
            return match ($record->status) {
                'scheduled' => ['open_sales' => ['label' => 'Put on sale', 'icon' => 'ticket'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'?']],
                'on_sale', 'sold_out' => ['complete' => ['label' => 'Screened', 'icon' => 'check-circle'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel '.$record->title.'? Bookings will need refunding.']],
                default => [],
            };
        }

        return match ($record->status) {
            'reserved' => ['pay' => ['label' => 'Take payment', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Total', 'type' => 'number', 'value' => $record->amount]]], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Release these seats?']],
            'paid' => ['collect' => ['label' => 'Tickets collected', 'icon' => 'ticket'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this paid booking?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'open_sales':
                if ($record->occurs_on?->lt(today())) {
                    throw ValidationException::withMessages(['status' => 'A show in the past cannot be on sale.']);
                }
                $record->update(['status' => 'on_sale']);

                return $record->title.' is on sale: '.(int) $record->value('seats').' seats in '.$record->value('screen').'.';
            case 'complete':
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The show has not screened yet.']);
                }
                $record->update(['status' => 'completed']);

                return $record->title.' screened to '.(int) $record->value('seats_sold').' of '.(int) $record->value('seats').' seats.';
            case 'pay':
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'min:0']])['amount'];
                $record->update(['status' => 'paid', 'amount' => $amount]);

                return 'Booking paid; seats '.$record->value('seats_booked').' held for '.$record->title.'.';
            case 'collect':
                $record->update(['status' => 'collected']);

                return 'Tickets for seats '.$record->value('seats_booked').' collected by '.$record->title.'.';
        }

        $record->update(['status' => 'cancelled']);

        return $record->entity === 'shows'
            ? $record->title.' cancelled; '.(int) $record->value('seats_sold').' seats to refund.'
            : 'Booking cancelled; seats '.$record->value('seats_booked').' released.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'shows') {
            return [];
        }

        $bookings = $this->linked('bookings', 'show', $record)->whereIn('status', self::HELD)->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Box office', 'icon' => 'clapperboard', 'stats' => [
                ['label' => 'Seats sold', 'value' => (int) $record->value('seats_sold').' of '.(int) $record->value('seats'), 'tone' => $record->status === 'sold_out' ? 'success' : null],
                ['label' => 'Occupancy', 'value' => (int) $record->value('_occupancy').'%'],
                ['label' => 'Seats left', 'value' => (string) (int) $record->value('_remaining'), 'tone' => (int) $record->value('_remaining') === 0 ? 'warning' : null],
                ['label' => 'Takings', 'value' => $this->money($this->number($record, '_takings'))],
                ['label' => 'Unpaid reservations', 'value' => (string) (int) $record->value('_unpaid'), 'tone' => (int) $record->value('_unpaid') > 0 ? 'warning' : null],
                ['label' => 'Starts', 'value' => trim(($record->occurs_on?->format('d M') ?? '').' '.$record->value('start_time')) ?: '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Bookings', 'icon' => 'ticket', 'empty' => 'No seats booked yet.',
                'rows' => $bookings->take(20)->map(fn (Record $booking) => [
                    'label' => $booking->title, 'sub' => 'Seats '.$booking->value('seats_booked').($booking->value('concessions') ? ' · '.$booking->value('concessions') : ''), 'value' => ucfirst($booking->status).' · '.$this->money($booking->amount), 'href' => $booking->url(), 'tone' => $booking->status === 'reserved' ? 'warning' : ($booking->status === 'collected' ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $shows = $this->records('shows')->get();
        $bookings = $this->records('bookings')->get();
        $today = $shows->filter(fn (Record $show) => $show->occurs_on?->isToday() && in_array($show->status, ['on_sale', 'sold_out', 'completed'], true))->sortBy(fn (Record $show) => $show->value('start_time'));
        $week = $bookings->filter(fn (Record $booking) => in_array($booking->status, ['paid', 'collected'], true) && $booking->occurs_on?->between(today()->startOfWeek(), today()->endOfWeek()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Cinema', 'icon' => 'clapperboard', 'stats' => [
                ['label' => 'Shows today', 'value' => (string) $today->count()],
                ['label' => 'Seats sold today', 'value' => (string) $today->sum(fn (Record $show) => (int) $show->value('seats_sold'))],
                ['label' => 'On sale', 'value' => (string) $shows->where('status', 'on_sale')->count()],
                ['label' => 'Sold out', 'value' => (string) $shows->where('status', 'sold_out')->count(), 'tone' => $shows->where('status', 'sold_out')->isNotEmpty() ? 'success' : null],
                ['label' => 'Takings this week', 'value' => $this->money($week->sum('amount'))],
                ['label' => 'Unpaid reservations', 'value' => (string) $bookings->where('status', 'reserved')->count(), 'tone' => $bookings->where('status', 'reserved')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's shows", 'icon' => 'calendar-days', 'empty' => 'Nothing is screening today.',
                'rows' => $today->take(12)->map(fn (Record $show) => [
                    'label' => $show->title, 'sub' => $show->value('screen').' · '.$show->value('start_time').($show->value('rating') ? ' · '.$show->value('rating') : ''), 'value' => (int) $show->value('seats_sold').' / '.(int) $show->value('seats'), 'href' => $show->url(), 'tone' => $show->status === 'sold_out' ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $shows = $this->dated('shows', $from, $to)->get();
        $showRows = $shows->sortBy([fn (Record $a, Record $b) => ($a->occurs_on?->toDateString().$a->value('start_time')) <=> ($b->occurs_on?->toDateString().$b->value('start_time'))])->map(fn (Record $show) => [
            $show->title, $show->value('screen'), $show->occurs_on?->format('d M Y').' '.$show->value('start_time'), ucfirst(str_replace('_', ' ', $show->status)), (int) $show->value('seats_sold').' / '.(int) $show->value('seats'), (int) $show->value('_occupancy').'%', $this->money((float) $show->value('_takings')),
        ])->values()->all();

        $byScreen = $shows->groupBy(fn (Record $show) => $show->value('screen') ?: 'No screen')->sortKeys()->map(fn ($group, $screen) => [
            $screen, $group->count(), $group->sum(fn (Record $show) => (int) $show->value('seats_sold')), $group->isEmpty() ? '0%' : round($group->avg(fn (Record $show) => (int) $show->value('_occupancy'))).'%', $this->money($group->sum(fn (Record $show) => (float) $show->value('_takings'))),
        ])->values()->all();

        $bookings = $this->dated('bookings', $from, $to)->get()->whereIn('status', ['paid', 'collected']);
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($bookings) {
            $group = $bookings->filter(fn (Record $booking) => $booking->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->sum(fn (Record $booking) => (int) $booking->value('_seat_count')), $this->money($group->sum('amount'))];
        })->values()->all();

        return [
            ['title' => 'Shows', 'columns' => ['Show', 'Screen', 'When', 'Status', 'Seats', 'Occupancy', 'Takings'], 'rows' => $showRows],
            ['title' => 'Takings by screen', 'columns' => ['Screen', 'Shows', 'Seats sold', 'Average occupancy', 'Takings'], 'rows' => $byScreen],
            ['title' => 'Bookings by month', 'columns' => ['Month', 'Bookings', 'Seats', 'Takings'], 'rows' => $byMonth],
        ];
    }
}
