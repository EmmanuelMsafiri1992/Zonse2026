<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Hotel & lodge: a room is never booked twice for the same night, never holds more guests than it
 * sleeps and is never sold while out of order. The stay is priced from the room's nightly rate and
 * the balance after the deposit is kept. Checking in fills the room, checking out leaves it dirty and
 * books a checkout clean, and finishing the clean makes the room ready to sell again. Confirmed
 * guests who never arrive are marked no-shows the day after their check-in date.
 */
class HotelLogic extends AppLogic
{
    /**
     * Reservations that hold their room.
     *
     * @var list<string>
     */
    protected const HOLDING = ['enquiry', 'confirmed', 'checked_in'];

    /**
     * Reservations that are finished and can no longer change status.
     *
     * @var list<string>
     */
    protected const CLOSED = ['checked_out', 'cancelled', 'no_show'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'rooms') {
            if ($existing && $payload['status'] === 'out_of_order' && $this->inHouse($existing)) {
                $errors['status'] = $this->inHouse($existing)->title.' is staying in this room.';
            }

            return $errors;
        }

        if ($entity->key === 'housekeeping') {
            if ($existing && $payload['status'] === 'inspected' && ! in_array($existing->status, ['done', 'inspected'], true)) {
                $errors['status'] = 'Finish the task before it is inspected.';
            }

            return $errors;
        }

        if ($existing && in_array($existing->status, self::CLOSED, true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This reservation is '.str_replace('_', ' ', $existing->status).' and closed.';
        }
        $checkIn = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        $checkOut = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : $checkIn->copy()->addDay();
        if ($checkOut->lte($checkIn)) {
            $errors['due_on'] = 'Check-out must be after check-in.';

            return $errors;
        }
        if ($payload['status'] === 'checked_in' && blank($data['room'] ?? null)) {
            $errors['data.room'] = 'Choose a room to check the guest into.';
        }
        $room = filled($data['room'] ?? null) ? $this->records('rooms')->find($data['room']) : null;
        if ($room && in_array($payload['status'], self::HOLDING, true)) {
            if ($room->status === 'out_of_order') {
                $errors['data.room'] = $room->title.' is out of order.';
            } elseif ((int) $room->value('max_guests') > 0 && (int) ($data['guests'] ?? 1) > (int) $room->value('max_guests')) {
                $errors['data.guests'] = $room->title.' sleeps '.(int) $room->value('max_guests').'.';
            } elseif ($clash = $this->clash($room, $checkIn, $checkOut, $existing?->id)) {
                $errors['data.room'] = $room->title.' is booked for '.$clash->title.' from '.$clash->occurs_on->format('d M').' to '.$clash->due_on->format('d M').'.';
            }
        }
        if (filled($payload['amount'] ?? null) && filled($data['deposit'] ?? null) && (float) $data['deposit'] > (float) $payload['amount']) {
            $errors['data.deposit'] = 'The deposit is more than the stay costs.';
        }

        return $errors;
    }

    /**
     * Another reservation holding the room on any of these nights.
     */
    protected function clash(Record $room, Carbon $checkIn, Carbon $checkOut, ?int $except = null): ?Record
    {
        return $this->linked('reservations', 'room', $room)->whereIn('status', self::HOLDING)
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->where('occurs_on', '<', $checkOut->copy()->startOfDay())->where('due_on', '>', $checkIn->copy()->startOfDay())
            ->first();
    }

    /**
     * The guest checked into a room, if any.
     */
    protected function inHouse(Record $room): ?Record
    {
        return $this->linked('reservations', 'room', $room)->where('status', 'checked_in')->first();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'housekeeping') {
            $record->occurs_on ??= today();

            return;
        }
        if ($record->entity !== 'reservations') {
            return;
        }

        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDay();
        $nights = max(1, (int) $record->occurs_on->diffInDays($record->due_on));
        $rate = (float) ($this->parent($record, 'room')?->value('rate') ?? 0);
        $priced = $record->amount !== null && abs((float) $record->amount - (int) $record->value('_nights') * (float) $record->value('_rate')) < 0.01;
        if (($record->amount === null || $priced) && $rate > 0) {
            $record->amount = $nights * $rate;
        }
        $this->put($record, [
            'guests' => max(1, (int) $record->value('guests')),
            '_nights' => $nights,
            '_rate' => $rate,
            '_balance' => round((float) $record->amount - $this->number($record, 'deposit'), 2),
        ]);
    }

    public function saved(Record $record): void
    {
        $moved = $record->wasRecentlyCreated || $record->wasChanged('status');

        if ($record->entity === 'reservations' && $moved) {
            $room = $this->parent($record, 'room');
            if (! $room) {
                return;
            }
            if ($record->status === 'checked_in' && $room->status !== 'occupied') {
                $room->update(['status' => 'occupied']);
            }
            if ($record->status === 'checked_out' && $room->status === 'occupied' && ! $this->inHouse($room)) {
                $room->update(['status' => 'vacant_dirty']);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'housekeeping',
                    'title' => 'Checkout clean · '.$room->title, 'status' => 'to_do', 'occurs_on' => today(),
                    'data' => ['room' => $room->id, 'type' => 'checkout_clean'],
                ]);
            }
        }

        if ($record->entity === 'housekeeping' && $moved) {
            $room = $this->parent($record, 'room');
            if (! $room || $room->status === 'occupied') {
                return;
            }
            if ($record->value('type') === 'maintenance' && in_array($record->status, ['to_do', 'in_progress'], true)) {
                $room->update(['status' => 'out_of_order']);
            } elseif (in_array($record->status, ['done', 'inspected'], true) && in_array($room->status, ['vacant_dirty', 'out_of_order'], true)) {
                $room->update(['status' => 'vacant_clean']);
            }
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'housekeeping') {
            return match ($record->status) {
                'to_do', 'in_progress' => ['finish' => ['label' => 'Done', 'icon' => 'check']],
                'done' => ['inspect' => ['label' => 'Inspected', 'icon' => 'clipboard-check']],
                default => [],
            };
        }
        if ($record->entity !== 'reservations') {
            return [];
        }

        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]];
        $rooms = fn () => $this->records('rooms')->where('status', 'vacant_clean')->orderBy('title')->pluck('title', 'id')->all();

        return match ($record->status) {
            'enquiry' => ['confirm' => ['label' => 'Confirm', 'icon' => 'check', 'fields' => [['name' => 'deposit', 'label' => 'Deposit taken', 'type' => 'number', 'value' => $record->value('deposit')]]], ...$cancel],
            'confirmed' => ['check_in' => ['label' => 'Check in', 'icon' => 'log-in', 'fields' => $record->value('room') ? [] : [['name' => 'room', 'label' => 'Room', 'type' => 'select', 'options' => $rooms()]]], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x'], ...$cancel],
            'checked_in' => ['check_out' => ['label' => 'Check out', 'icon' => 'log-out', 'confirm' => 'Check the guest out and send the room to housekeeping?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'housekeeping') {
            $record->update(['status' => $action === 'inspect' ? 'inspected' : 'done']);

            return $record->title.($action === 'inspect' ? ' inspected.' : ' done; the room is ready.');
        }

        switch ($action) {
            case 'confirm':
                $deposit = $request->validate(['deposit' => ['nullable', 'numeric', 'min:0']])['deposit'] ?? $record->value('deposit');
                if ($record->value('room') && ($clash = $this->clash($this->parent($record, 'room'), $record->occurs_on, $record->due_on, $record->id))) {
                    throw ValidationException::withMessages(['room' => 'The room is booked for '.$clash->title.' on these nights.']);
                }
                $record->update(['status' => 'confirmed', 'data' => [...$record->data, 'deposit' => $deposit]]);

                return 'Reservation for '.$record->title.' confirmed: '.$record->value('_nights').' night(s), '.$this->money($record->value('_balance')).' to pay on arrival.';
            case 'check_in':
                $roomId = $record->value('room') ?? $request->validate(['room' => ['required', 'integer']])['room'];
                $room = $this->records('rooms')->find($roomId);
                if (! $room) {
                    throw ValidationException::withMessages(['room' => 'Choose a room.']);
                }
                if ($room->status !== 'vacant_clean') {
                    throw ValidationException::withMessages(['room' => $room->title.' is '.str_replace('_', ' ', $room->status).', not ready for a guest.']);
                }
                if ($record->occurs_on->gt(today())) {
                    throw ValidationException::withMessages(['room' => 'This reservation starts on '.$record->occurs_on->format('d M Y').'.']);
                }
                $record->update(['status' => 'checked_in', 'data' => [...$record->data, 'room' => $room->id]]);

                return $record->title.' checked into '.$room->title.' until '.$record->due_on->format('d M Y').'.';
            case 'check_out':
                $record->update(['status' => 'checked_out', 'due_on' => $record->due_on->gt(today()) ? today() : $record->due_on]);
                $record = $record->fresh();

                return $record->title.' checked out after '.$record->value('_nights').' night(s); '.$this->money($record->value('_balance')).' to settle. Checkout clean booked.';
            case 'no_show':
                $record->update(['status' => 'no_show']);

                return $record->title.' marked as a no-show.';
            default:
                $reason = $request->validate(['reason' => ['required', 'string', 'max:190']])['reason'];
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return 'Reservation for '.$record->title.' cancelled.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $missed = $this->records('reservations')->where('status', 'confirmed')->where('occurs_on', '<', today()->startOfDay())->get();
        $missed->each(fn (Record $reservation) => $reservation->update(['status' => 'no_show']));

        return $missed->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'rooms') {
            $guest = $this->inHouse($record);
            $next = $this->linked('reservations', 'room', $record)->whereIn('status', ['enquiry', 'confirmed'])->where('occurs_on', '>=', today()->startOfDay())->orderBy('occurs_on')->first();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Room', 'icon' => 'door-closed', 'stats' => [
                ['label' => 'In house', 'value' => $guest ? $guest->title.' until '.$guest->due_on->format('d M') : '—'],
                ['label' => 'Next arrival', 'value' => $next ? $next->title.' on '.$next->occurs_on->format('d M') : '—'],
                ['label' => 'Nightly rate', 'value' => $this->money($record->value('rate'))],
            ]]]];
        }
        if ($record->entity !== 'reservations') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Stay', 'icon' => 'bed-double', 'stats' => [
            ['label' => 'Nights', 'value' => (string) $record->value('_nights')],
            ['label' => 'Rate', 'value' => $this->money($record->value('_rate'))],
            ['label' => 'Deposit', 'value' => $this->money($record->value('deposit'))],
            ['label' => 'Balance', 'value' => $this->money($record->value('_balance')), 'tone' => (float) $record->value('_balance') > 0 ? 'warning' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $rooms = $this->records('rooms')->get();
        $sellable = $rooms->where('status', '!=', 'out_of_order')->count();
        $occupied = $rooms->where('status', 'occupied')->count();
        $arrivals = $this->records('reservations')->where('status', 'confirmed')->whereDate('occurs_on', today())->get();
        $departures = $this->records('reservations')->where('status', 'checked_in')->where('due_on', '<=', today()->endOfDay())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tonight', 'icon' => 'bed-double', 'stats' => [
                ['label' => 'Occupancy', 'value' => ($sellable ? (int) round($occupied / $sellable * 100) : 0).'%'],
                ['label' => 'Arrivals', 'value' => (string) $arrivals->count()],
                ['label' => 'Departures', 'value' => (string) $departures->count()],
                ['label' => 'Rooms to clean', 'value' => (string) $rooms->where('status', 'vacant_dirty')->count(), 'tone' => $rooms->where('status', 'vacant_dirty')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Front desk today', 'icon' => 'concierge-bell', 'empty' => 'No arrivals or departures today.',
                'rows' => $arrivals->map(fn (Record $reservation) => ['label' => $reservation->title, 'sub' => 'Arriving · '.($reservation->related('room')?->title ?? 'no room yet').' · '.$reservation->value('_nights').' night(s)', 'value' => 'Arrival', 'href' => $reservation->url(), 'tone' => 'info'])
                    ->concat($departures->map(fn (Record $reservation) => ['label' => $reservation->title, 'sub' => 'Leaving · '.$reservation->related('room')?->title.' · balance '.$this->money($reservation->value('_balance')), 'value' => 'Departure', 'href' => $reservation->url(), 'tone' => 'warning']))
                    ->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $stays = $this->records('reservations')->whereIn('status', ['confirmed', 'checked_in', 'checked_out'])
            ->where('occurs_on', '<=', $to->copy()->endOfDay())->where('due_on', '>', $from->copy()->startOfDay())->get();
        $rooms = $this->records('rooms')->count();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($stays, $rooms) {
            $start = Carbon::parse($month.'-01');
            $end = $start->copy()->addMonth();
            $nights = 0;
            $revenue = 0.0;
            foreach ($stays as $stay) {
                $inMonth = max(0, (int) $stay->occurs_on->max($start)->diffInDays($stay->due_on->min($end), false));
                $nights += $inMonth;
                $revenue += $inMonth * (float) $stay->amount / max(1, (int) $stay->value('_nights'));
            }
            $available = $rooms * $start->daysInMonth;

            return [$label, $nights, $available ? (int) round($nights / $available * 100).'%' : '—', $nights ? $this->money($revenue / $nights) : '—', $this->money($revenue), $available ? $this->money($revenue / $available) : '—'];
        })->values()->all();

        $bySource = $stays->groupBy(fn (Record $stay) => $stay->value('source') ?: 'walk_in')->sortKeys()
            ->map(fn (Collection $group, string $source) => [ucwords(str_replace('_', ' ', $source)), $group->count(), $group->sum(fn (Record $stay) => (int) $stay->value('_nights')), $this->money($group->sum('amount'))])->values()->all();

        $lost = $this->dated('reservations', $from, $to)->whereIn('status', ['cancelled', 'no_show'])->get()
            ->map(fn (Record $stay) => [$stay->number, $stay->title, $stay->occurs_on?->format('d M Y'), $stay->status === 'no_show' ? 'No-show' : 'Cancelled', $this->money($stay->amount)])->values()->all();

        return [
            ['title' => 'Occupancy by month', 'columns' => ['Month', 'Room nights sold', 'Occupancy', 'Average rate', 'Room revenue', 'RevPAR'], 'rows' => $byMonth],
            ['title' => 'Bookings by source', 'columns' => ['Source', 'Reservations', 'Nights', 'Revenue'], 'rows' => $bySource],
            ['title' => 'Cancellations and no-shows', 'columns' => ['Reservation', 'Guest', 'Check-in', 'Outcome', 'Value'], 'rows' => $lost],
        ];
    }
}
