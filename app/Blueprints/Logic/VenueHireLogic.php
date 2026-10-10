<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Venues & banquets: a space is never booked twice for overlapping hours on the same day (a booking
 * with no times takes the whole day), never takes more guests than its capacity and can't be booked
 * while under maintenance. A booking is priced at the space's day rate unless a total is given, and
 * the balance after the deposit is kept. Provisional bookings hold the space until they are confirmed
 * with a deposit. Each day, provisional bookings whose date has passed are released and confirmed
 * ones are completed.
 */
class VenueHireLogic extends AppLogic
{
    /**
     * Bookings that hold the space.
     *
     * @var list<string>
     */
    protected const HOLDING = ['provisional', 'confirmed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'spaces') {
            if ($existing && $payload['status'] === 'maintenance' && $existing->status !== 'maintenance'
                && ($next = $this->linked('bookings', 'space', $existing)->where('status', 'confirmed')->where('occurs_on', '>=', today()->startOfDay())->orderBy('occurs_on')->first())) {
                $errors['status'] = $existing->title.' is booked for '.$next->title.' on '.$next->occurs_on->format('d M Y').'.';
            }

            return $errors;
        }

        if ($existing && in_array($existing->status, ['completed', 'cancelled'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This booking is '.$existing->status.'.';
        }
        $start = $data['start_time'] ?? null;
        $end = $data['end_time'] ?? null;
        if (filled($start) && filled($end) && $end <= $start) {
            $errors['data.end_time'] = 'The booking must end after it starts.';
        }
        if (filled($data['deposit'] ?? null) && filled($payload['amount'] ?? null) && (float) $data['deposit'] > (float) $payload['amount']) {
            $errors['data.deposit'] = 'The deposit cannot be more than the total.';
        }

        $space = filled($data['space'] ?? null) ? $this->records('spaces')->find($data['space']) : null;
        if (! $space || ! in_array($payload['status'], self::HOLDING, true)) {
            return $errors;
        }
        if ($space->status === 'maintenance') {
            $errors['data.space'] = $space->title.' is under maintenance.';
        }
        $capacity = (int) $space->value('capacity');
        if ($capacity > 0 && (int) ($data['guests'] ?? 0) > $capacity) {
            $errors['data.guests'] = $space->title.' holds '.$capacity.' guests.';
        }
        $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : null;
        if ($date && ($clash = $this->clash($space, $date, $start, $end, $existing?->id))) {
            $errors['data.space'] = $space->title.' is '.($clash->status === 'provisional' ? 'held' : 'booked').' for '.$clash->title.($clash->value('start_time') ? ' from '.$clash->value('start_time').' to '.($clash->value('end_time') ?: 'close') : ' all day').'.';
        }

        return $errors;
    }

    /**
     * Another booking holding the space for overlapping hours that day.
     */
    protected function clash(Record $space, Carbon $date, ?string $start, ?string $end, ?int $except): ?Record
    {
        $from = $start ?: '00:00';
        $to = $end ?: '23:59';

        return $this->linked('bookings', 'space', $space)->whereIn('status', self::HOLDING)->whereDate('occurs_on', $date)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $booking) => ($booking->value('start_time') ?: '00:00') < $to && ($booking->value('end_time') ?: '23:59') > $from);
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'bookings') {
            return;
        }
        $rate = (float) $this->parent($record, 'space')?->value('day_rate');
        if ($record->amount === null && $rate > 0) {
            $record->amount = $rate;
        }
        $this->put($record, ['_balance' => round(max(0, (float) $record->amount - $this->number($record, 'deposit')), 2)]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'bookings') {
            return [];
        }

        return match ($record->status) {
            'provisional' => [
                'confirm' => ['label' => 'Confirm', 'icon' => 'check', 'fields' => [['name' => 'deposit', 'label' => 'Deposit received', 'type' => 'number', 'value' => $record->value('deposit')]]],
                'cancel' => ['label' => 'Release', 'icon' => 'x'],
            ],
            'confirmed' => [
                'complete' => ['label' => 'Event held', 'icon' => 'party-popper'],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this booking?'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'confirm':
                $deposit = (float) $request->validate(['deposit' => ['required', 'numeric', 'gt:0']])['deposit'];
                if ($record->amount !== null && $deposit > (float) $record->amount) {
                    throw ValidationException::withMessages(['deposit' => 'The deposit cannot be more than the total.']);
                }
                $record->update(['status' => 'confirmed', 'data' => [...$record->data, 'deposit' => $deposit]]);

                return $record->title.' confirmed with a '.$this->money($deposit).' deposit; '.$this->money($record->value('_balance')).' to pay.';
            case 'complete':
                $record->update(['status' => 'completed']);

                return $record->title.' held.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled; '.($this->parent($record, 'space')?->title ?? 'the space').' is free again.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $past = $this->records('bookings')->whereIn('status', self::HOLDING)->where('occurs_on', '<', today()->startOfDay())->get();
        $past->each(fn (Record $booking) => $booking->update(['status' => $booking->status === 'confirmed' ? 'completed' : 'cancelled']));

        return $past->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'spaces') {
            return [];
        }
        $coming = $this->linked('bookings', 'space', $record)->whereIn('status', self::HOLDING)->where('occurs_on', '>=', today()->startOfDay())->orderBy('occurs_on')->limit(15)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Diary', 'icon' => 'calendar-days', 'empty' => 'Nothing booked.',
            'rows' => $coming->map(fn (Record $booking) => $this->row($booking))->values()->all(),
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    protected function row(Record $booking, bool $withSpace = false): array
    {
        $hours = $booking->value('start_time') ? $booking->value('start_time').'–'.($booking->value('end_time') ?: 'close') : 'All day';

        return [
            'label' => $booking->title,
            'sub' => $booking->occurs_on?->format('D d M Y').' · '.$hours.($withSpace ? ' · '.($this->parent($booking, 'space')?->title ?? '—') : ''),
            'value' => ucfirst($booking->status),
            'href' => $booking->url(),
            'tone' => $booking->status === 'confirmed' ? 'success' : 'warning',
        ];
    }

    public function homeCards(): array
    {
        $coming = $this->records('bookings')->whereIn('status', self::HOLDING)->whereBetween('occurs_on', [today()->startOfDay(), today()->addDays(13)->endOfDay()])->orderBy('occurs_on')->get();
        $confirmed = $this->records('bookings')->where('status', 'confirmed')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Venues', 'icon' => 'party-popper', 'stats' => [
                ['label' => 'Events in the next 2 weeks', 'value' => (string) $coming->where('status', 'confirmed')->count()],
                ['label' => 'Provisional holds', 'value' => (string) $this->records('bookings')->where('status', 'provisional')->count()],
                ['label' => 'Balances to collect', 'value' => $this->money($confirmed->sum(fn (Record $booking) => (float) $booking->value('_balance')))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Coming up', 'icon' => 'calendar-days', 'empty' => 'Nothing booked in the next two weeks.',
                'rows' => $coming->map(fn (Record $booking) => $this->row($booking, true))->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $bookings = $this->dated('bookings', $from, $to)->whereIn('status', ['confirmed', 'completed'])->get();
        $days = max(1, (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1);

        $bySpace = $this->records('spaces')->orderBy('title')->get()->map(function (Record $space) use ($bookings, $days) {
            $group = $bookings->filter(fn (Record $booking) => (int) $booking->value('space') === $space->id);
            $used = $group->map(fn (Record $booking) => $booking->occurs_on?->toDateString())->filter()->unique()->count();

            return [$space->title, $group->count(), $used, (int) round($used / $days * 100).'%', $group->count() ? (int) round($group->avg(fn (Record $booking) => (int) $booking->value('guests'))) : 0, $this->money($group->sum('amount'))];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(fn (string $label, string $month) => [$label, $bookings->filter(fn (Record $booking) => $booking->occurs_on?->format('Y-m') === $month)->count(), $this->money($this->sumByMonth($bookings)[$month] ?? 0)])->values()->all();

        return [
            ['title' => 'Use by space', 'columns' => ['Space', 'Bookings', 'Days used', 'Utilisation', 'Average guests', 'Revenue'], 'rows' => $bySpace],
            ['title' => 'Bookings by month', 'columns' => ['Month', 'Bookings', 'Revenue'], 'rows' => $byMonth],
        ];
    }
}
