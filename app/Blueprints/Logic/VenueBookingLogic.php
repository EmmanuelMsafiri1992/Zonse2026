<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Venue booking: a facility is booked by the hour, never twice for the same time, and only while
 * it is open. A booking's charge comes from the hours booked and the facility's hourly rate unless
 * priced by hand, a booking is completed only once it has happened, and each facility shows its
 * bookings, hours and takings for the month and what is coming up.
 */
class VenueBookingLogic extends AppLogic
{
    public const BLOCKING = ['requested', 'confirmed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'facilities') {
            if (filled($data['hourly_rate'] ?? null) && (float) $data['hourly_rate'] < 0) {
                $errors['data.hourly_rate'] = 'The hourly rate cannot be negative.';
            }
            if (filled($data['capacity'] ?? null) && (int) $data['capacity'] < 0) {
                $errors['data.capacity'] = 'Capacity cannot be negative.';
            }
            if ($payload['status'] === 'closed' && $existing && $existing->status !== 'closed' && ($upcoming = $this->linked('bookings', 'facility', $existing)->whereIn('status', self::BLOCKING)->whereDate('occurs_on', '>=', today())->count()) > 0) {
                $errors['status'] = $upcoming.' bookings are still to come; cancel or move them first.';
            }

            return $errors;
        }

        $facility = ! empty($data['facility']) ? $this->records('facilities')->find($data['facility']) : null;
        $blocking = in_array($payload['status'], self::BLOCKING, true);
        if ($facility && $blocking && $facility->status !== 'available' && (! $existing || (int) $existing->value('facility') !== $facility->id)) {
            $errors['data.facility'] = $facility->title.' is closed.';
        }
        $start = self::minutes($data['start_time'] ?? null);
        $end = self::minutes($data['end_time'] ?? null);
        if ($start !== null && $end !== null && $end <= $start) {
            $errors['data.end_time'] = 'The booking ends before it starts.';
        }
        $day = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        if ($facility && $blocking && $start !== null && $end !== null && $end > $start && ($clash = $this->clash($facility, $day, $start, $end, $existing))) {
            $errors['data.start_time'] = $facility->title.' is already booked '.$clash->value('start_time').'–'.$clash->value('end_time').' that day by '.$clash->title.'.';
        }
        if ($payload['status'] === 'completed' && $day->gt(today())) {
            $errors['status'] = 'A booking is completed only once it has happened.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The charge cannot be negative.';
        }

        return $errors;
    }

    /** Minutes since midnight for a time such as "09:30", or null when it is blank or unreadable. */
    public static function minutes(?string $time): ?int
    {
        if (! $time || ! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $match)) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }

    protected function clash(Record $facility, Carbon $day, int $start, int $end, ?Record $except): ?Record
    {
        return $this->linked('bookings', 'facility', $facility)->whereIn('status', self::BLOCKING)->whereDate('occurs_on', $day)->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->first(function (Record $booking) use ($start, $end) {
                $otherStart = self::minutes($booking->value('start_time'));
                $otherEnd = self::minutes($booking->value('end_time'));

                return $otherStart !== null && $otherEnd !== null && $otherStart < $end && $otherEnd > $start;
            });
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $record->occurs_on ??= today();
            $facility = $this->parent($record, 'facility');
            $start = self::minutes($record->value('start_time'));
            $end = self::minutes($record->value('end_time'));
            $hours = $start !== null && $end !== null && $end > $start ? round(($end - $start) / 60, 2) : 0.0;
            $rate = (float) ($facility?->value('hourly_rate') ?? 0);
            if ((float) $record->amount <= 0 && $rate > 0 && $hours > 0 && $record->status !== 'cancelled') {
                $record->amount = round($hours * $rate, 2);
            }
            $this->put($record, ['_hours' => $hours, '_rate' => $rate]);

            return;
        }

        $bookings = $record->exists ? $this->linked('bookings', 'facility', $record)->get() : collect();
        $month = $bookings->filter(fn (Record $booking) => in_array($booking->status, ['confirmed', 'completed'], true) && $booking->occurs_on?->isCurrentMonth());
        $next = $bookings->filter(fn (Record $booking) => in_array($booking->status, self::BLOCKING, true) && $booking->occurs_on?->gte(today()))->sortBy(fn (Record $booking) => $booking->occurs_on->toDateString().' '.$booking->value('start_time'))->first();
        $this->put($record, [
            '_bookings_month' => $month->count(),
            '_hours_month' => round($month->sum(fn (Record $booking) => (float) $booking->value('_hours')), 2),
            '_revenue_month' => round($month->sum('amount'), 2),
            '_requested' => $bookings->where('status', 'requested')->count(),
            '_next_booking' => $next ? $next->occurs_on->format('d M').' '.$next->value('start_time') : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $this->recalculate($this->parent($record, 'facility'));
            $this->recalculate($this->previousParent($record, 'facility'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $this->recalculate($this->parent($record, 'facility'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'facilities') {
            return $record->status === 'available'
                ? [
                    'book' => ['label' => 'Book', 'icon' => 'calendar-plus', 'fields' => [
                        ['name' => 'booked_by', 'label' => 'Booked by', 'type' => 'text'],
                        ['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->toDateString()],
                        ['name' => 'start_time', 'label' => 'Start', 'type' => 'time', 'value' => '09:00'],
                        ['name' => 'end_time', 'label' => 'End', 'type' => 'time', 'value' => '11:00'],
                        ['name' => 'purpose', 'label' => 'Purpose', 'type' => 'text'],
                    ]],
                    'close' => ['label' => 'Close', 'icon' => 'lock', 'confirm' => 'Close '.$record->title.' to bookings?'],
                ]
                : ['reopen' => ['label' => 'Reopen', 'icon' => 'unlock']];
        }

        return match ($record->status) {
            'requested' => ['confirm' => ['label' => 'Confirm', 'icon' => 'check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking?']],
            'confirmed' => ['complete' => ['label' => 'Completed', 'icon' => 'check-circle'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'book':
                $input = $request->validate(['booked_by' => ['required', 'string'], 'occurs_on' => ['required', 'date', 'after_or_equal:today'], 'start_time' => ['required', 'string'], 'end_time' => ['required', 'string'], 'purpose' => ['nullable', 'string']]);
                $start = self::minutes($input['start_time']);
                $end = self::minutes($input['end_time']);
                if ($start === null || $end === null || $end <= $start) {
                    throw ValidationException::withMessages(['end_time' => 'The booking ends before it starts.']);
                }
                $day = Carbon::parse($input['occurs_on']);
                if ($clash = $this->clash($record, $day, $start, $end, null)) {
                    throw ValidationException::withMessages(['start_time' => $record->title.' is already booked '.$clash->value('start_time').'–'.$clash->value('end_time').' that day by '.$clash->title.'.']);
                }
                $booking = Record::create([
                    'workspace_id' => $record->workspace_id, 'branch_id' => $record->branch_id, 'blueprint' => $record->blueprint, 'entity' => 'bookings',
                    'title' => $input['booked_by'], 'status' => 'confirmed', 'occurs_on' => $day, 'currency' => $record->currency,
                    'data' => ['facility' => $record->id, 'start_time' => $input['start_time'], 'end_time' => $input['end_time'], 'purpose' => $input['purpose'] ?? null],
                ]);

                return $record->title.' booked for '.$input['booked_by'].' on '.$day->format('d M Y').' '.$input['start_time'].'–'.$input['end_time'].' ('.$booking->value('_hours').' hours).';
            case 'close':
                $upcoming = $this->linked('bookings', 'facility', $record)->whereIn('status', self::BLOCKING)->whereDate('occurs_on', '>=', today())->count();
                if ($upcoming > 0) {
                    throw ValidationException::withMessages(['status' => $upcoming.' bookings are still to come; cancel or move them first.']);
                }
                $record->update(['status' => 'closed']);

                return $record->title.' is closed.';
            case 'reopen':
                $record->update(['status' => 'available']);

                return $record->title.' is open for bookings.';
            case 'confirm':
                $facility = $this->parent($record, 'facility');
                $start = self::minutes($record->value('start_time'));
                $end = self::minutes($record->value('end_time'));
                if ($facility && $start !== null && $end !== null && ($clash = $this->clash($facility, $record->occurs_on ?? today(), $start, $end, $record)) && $clash->status === 'confirmed') {
                    throw ValidationException::withMessages(['data.start_time' => $facility->title.' is already booked '.$clash->value('start_time').'–'.$clash->value('end_time').' that day by '.$clash->title.'.']);
                }
                $record->update(['status' => 'confirmed']);

                return 'Booking confirmed for '.$record->occurs_on?->format('d M Y').' '.$record->value('start_time').'–'.$record->value('end_time').'.';
            case 'complete':
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'A booking is completed only once it has happened.']);
                }
                $record->update(['status' => 'completed']);

                return 'Booking completed.';
        }

        $record->update(['status' => 'cancelled']);

        return 'Booking cancelled.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'facilities') {
            return [];
        }

        $upcoming = $this->linked('bookings', 'facility', $record)->whereIn('status', self::BLOCKING)->whereDate('occurs_on', '>=', today())->get()->sortBy(fn (Record $booking) => $booking->occurs_on->toDateString().' '.$booking->value('start_time'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Facility', 'icon' => 'building', 'stats' => [
                ['label' => 'Hourly rate', 'value' => $this->money($this->number($record, 'hourly_rate'))],
                ['label' => 'Bookings this month', 'value' => (string) (int) $record->value('_bookings_month')],
                ['label' => 'Hours this month', 'value' => (string) $this->number($record, '_hours_month')],
                ['label' => 'Takings this month', 'value' => $this->money($this->number($record, '_revenue_month'))],
                ['label' => 'Awaiting confirmation', 'value' => (string) (int) $record->value('_requested'), 'tone' => (int) $record->value('_requested') > 0 ? 'warning' : null],
                ['label' => 'Next booking', 'value' => $record->value('_next_booking') ?: 'None'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming bookings', 'icon' => 'calendar-range', 'empty' => 'Nothing booked ahead.',
                'rows' => $upcoming->take(10)->map(fn (Record $booking) => [
                    'label' => $booking->title, 'sub' => ($booking->value('purpose') ?: 'No purpose given').' · '.$booking->value('start_time').'–'.$booking->value('end_time'), 'value' => $booking->occurs_on->format('D d M'), 'href' => $booking->url(), 'tone' => $booking->status === 'requested' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $facilities = $this->records('facilities')->get();
        $bookings = $this->records('bookings')->get();
        $today = $bookings->filter(fn (Record $booking) => in_array($booking->status, self::BLOCKING, true) && $booking->occurs_on?->isToday())->sortBy(fn (Record $booking) => $booking->value('start_time'));
        $month = $bookings->filter(fn (Record $booking) => in_array($booking->status, ['confirmed', 'completed'], true) && $booking->occurs_on?->isCurrentMonth());
        $requested = $bookings->where('status', 'requested');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Bookings', 'icon' => 'calendar-range', 'stats' => [
                ['label' => 'Facilities open', 'value' => $facilities->where('status', 'available')->count().' of '.$facilities->count()],
                ['label' => 'Today', 'value' => (string) $today->count()],
                ['label' => 'This month', 'value' => (string) $month->count()],
                ['label' => 'Hours this month', 'value' => (string) round($month->sum(fn (Record $booking) => (float) $booking->value('_hours')), 1)],
                ['label' => 'Takings this month', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'To confirm', 'value' => (string) $requested->count(), 'tone' => $requested->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's bookings", 'icon' => 'clock', 'empty' => 'Nothing booked today.',
                'rows' => $today->take(10)->map(fn (Record $booking) => [
                    'label' => $booking->title, 'sub' => $facilities->firstWhere('id', (int) $booking->value('facility'))?->title.($booking->value('purpose') ? ' · '.$booking->value('purpose') : ''), 'value' => $booking->value('start_time').'–'.$booking->value('end_time'), 'href' => $booking->url(), 'tone' => $booking->status === 'requested' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $facilities = $this->records('facilities')->get();
        $bookings = $this->dated('bookings', $from, $to)->get()->whereIn('status', ['confirmed', 'completed']);
        $types = $this->app->entities['facilities']->field('type')?->options ?? [];

        $facilityRows = $facilities->sortBy('title')->map(function (Record $facility) use ($bookings, $types) {
            $own = $bookings->where('data.facility', $facility->id);

            return [$facility->title, $types[$facility->value('type')] ?? ucfirst((string) $facility->value('type')), ucfirst($facility->status), $own->count(), round($own->sum(fn (Record $booking) => (float) $booking->value('_hours')), 1), $this->money($own->sum('amount'))];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($bookings) {
            $group = $bookings->filter(fn (Record $booking) => $booking->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), round($group->sum(fn (Record $booking) => (float) $booking->value('_hours')), 1), $this->money($group->sum('amount'))];
        })->values()->all();

        $byType = collect($types)->map(function (string $label, string $type) use ($facilities, $bookings) {
            $ids = $facilities->where('data.type', $type)->pluck('id')->all();
            $own = $bookings->whereIn('data.facility', $ids);

            return [$label, count($ids), $own->count(), round($own->sum(fn (Record $booking) => (float) $booking->value('_hours')), 1), $this->money($own->sum('amount'))];
        })->filter(fn (array $row) => $row[1] > 0)->values()->all();

        return [
            ['title' => 'Facilities', 'columns' => ['Facility', 'Type', 'Status', 'Bookings', 'Hours', 'Takings'], 'rows' => $facilityRows],
            ['title' => 'Bookings by month', 'columns' => ['Month', 'Bookings', 'Hours', 'Takings'], 'rows' => $byMonth],
            ['title' => 'Bookings by type', 'columns' => ['Type', 'Facilities', 'Bookings', 'Hours', 'Takings'], 'rows' => $byType],
        ];
    }
}
