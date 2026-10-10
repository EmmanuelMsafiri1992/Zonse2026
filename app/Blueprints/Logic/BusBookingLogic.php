<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Bus & coach seats: each seat on a trip is sold once, seat numbers run from 1 to the bus's seat
 * count, and tickets are only sold on trips that have not left. A ticket costs the trip's fare and
 * the trip counts its sold seats and takings from its tickets. Passengers board once the trip is
 * boarding and the ticket is paid; when the bus departs, paid passengers who did not board become
 * no-shows and unpaid bookings are released. Cancelling a trip cancels its tickets.
 */
class BusBookingLogic extends AppLogic
{
    /**
     * Tickets that hold their seat.
     *
     * @var list<string>
     */
    protected const HOLDING = ['booked', 'paid', 'boarded'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'trips') {
            if ($existing && (int) ($data['seats'] ?? 0) < $this->sold($existing)->count()) {
                $errors['data.seats'] = $this->sold($existing)->count().' seats are already sold.';
            }

            return $errors;
        }

        $trip = filled($data['trip'] ?? null) ? $this->records('trips')->find($data['trip']) : null;
        if (! $trip || ! in_array($payload['status'], self::HOLDING, true)) {
            return $errors;
        }
        $moving = ! $existing || (int) $existing->value('trip') !== $trip->id || ! in_array($existing->status, self::HOLDING, true);
        if ($moving && ! in_array($trip->status, ['scheduled', 'boarding'], true)) {
            $errors['data.trip'] = 'The '.$trip->title.' trip has '.$trip->status.'.';
        }
        $seat = trim((string) ($data['seat_number'] ?? ''));
        if (ctype_digit($seat) && ((int) $seat < 1 || (int) $seat > (int) $trip->value('seats'))) {
            $errors['data.seat_number'] = 'Seats on this bus run from 1 to '.(int) $trip->value('seats').'.';
        } elseif ($taken = $this->sold($trip, $existing?->id)->first(fn (Record $ticket) => strcasecmp(trim((string) $ticket->value('seat_number')), $seat) === 0)) {
            $errors['data.seat_number'] = 'Seat '.$seat.' is taken by '.$taken->title.'.';
        } elseif ($moving && $this->sold($trip, $existing?->id)->count() >= (int) $trip->value('seats')) {
            $errors['data.trip'] = 'The trip is full.';
        }
        if ($payload['status'] === 'boarded' && $trip->status !== 'boarding') {
            $errors['status'] = 'Passengers board once the trip is boarding.';
        }

        return $errors;
    }

    /**
     * Tickets holding seats on a trip.
     *
     * @return Collection<int, Record>
     */
    protected function sold(Record $trip, ?int $except = null): Collection
    {
        return $this->linked('tickets', 'trip', $trip)->whereIn('status', self::HOLDING)->when($except, fn ($query) => $query->whereKeyNot($except))->get();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'trips') {
            $sold = $record->exists ? $this->sold($record) : collect();
            $this->put($record, [
                'seats_sold' => $sold->count(),
                '_seats_left' => max(0, (int) $record->value('seats') - $sold->count()),
                '_takings' => round((float) $sold->whereIn('status', ['paid', 'boarded'])->sum('amount'), 2),
            ]);

            return;
        }

        $trip = $this->parent($record, 'trip');
        if ($record->amount === null && $trip) {
            $record->amount = $trip->value('fare');
        }
        $this->put($record, ['seat_number' => trim((string) $record->value('seat_number'))]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'tickets') {
            $this->recount($this->parent($record, 'trip'));
            $this->recount($this->previousParent($record, 'trip'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'tickets') {
            $this->recount($this->parent($record, 'trip'));
        }
    }

    protected function recount(?Record $trip): void
    {
        $trip?->save();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'trips') {
            return match ($record->status) {
                'scheduled' => ['board' => ['label' => 'Start boarding', 'icon' => 'door-open'], 'cancel' => ['label' => 'Cancel trip', 'icon' => 'x', 'confirm' => 'Cancel the trip and every ticket on it?']],
                'boarding' => ['depart' => ['label' => 'Depart', 'icon' => 'bus', 'confirm' => 'Close boarding? Paid passengers not on board become no-shows.']],
                'departed' => ['arrive' => ['label' => 'Arrived', 'icon' => 'flag']],
                default => [],
            };
        }

        return match ($record->status) {
            'booked' => ['pay' => ['label' => 'Take payment', 'icon' => 'banknote'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'paid' => ['board' => ['label' => 'Boarded', 'icon' => 'check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        return $record->entity === 'trips' ? $this->tripAction($action, $record) : $this->ticketAction($action, $record);
    }

    protected function tripAction(string $action, Record $trip): string
    {
        switch ($action) {
            case 'board':
                $trip->update(['status' => 'boarding']);

                return 'Boarding '.$trip->title.': '.$trip->value('seats_sold').' passenger(s) booked.';
            case 'depart':
                $tickets = $this->sold($trip)->groupBy('status');
                $onBoard = $tickets->get('boarded', collect())->count();
                $noShows = $tickets->get('paid', collect())->each(fn (Record $ticket) => $ticket->update(['status' => 'no_show']))->count();
                $released = $tickets->get('booked', collect())->each(fn (Record $ticket) => $ticket->update(['status' => 'cancelled']))->count();
                $trip->fresh()->update(['status' => 'departed', 'data' => [...$trip->fresh()->data, '_departed_at' => now()->toDateTimeString(), '_on_board' => $onBoard]]);

                return $trip->title.' departed with '.$onBoard.' on board; '.$noShows.' no-show(s), '.$released.' unpaid booking(s) released.';
            case 'arrive':
                $trip->update(['status' => 'arrived']);

                return $trip->title.' arrived.';
            default:
                $tickets = $this->sold($trip);
                $refund = $tickets->whereIn('status', ['paid', 'boarded'])->sum('amount');
                $tickets->each(fn (Record $ticket) => $ticket->update(['status' => 'cancelled']));
                $trip->fresh()->update(['status' => 'cancelled']);

                return $trip->title.' cancelled; '.$tickets->count().' ticket(s) cancelled, '.$this->money($refund).' to refund.';
        }
    }

    protected function ticketAction(string $action, Record $ticket): string
    {
        $trip = $this->parent($ticket, 'trip');
        if ($action === 'board') {
            if ($trip?->status !== 'boarding') {
                throw ValidationException::withMessages(['status' => 'The bus is not boarding yet.']);
            }
            $ticket->update(['status' => 'boarded']);

            return $ticket->title.' boarded, seat '.$ticket->value('seat_number').'.';
        }
        if ($action === 'pay') {
            $ticket->update(['status' => 'paid']);

            return 'Seat '.$ticket->value('seat_number').' on '.$trip?->title.' paid: '.$this->money($ticket->amount).'.';
        }
        $ticket->update(['status' => 'cancelled']);

        return 'Ticket for '.$ticket->title.' cancelled; seat '.$ticket->value('seat_number').' is free.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'trips') {
            return [];
        }
        $tickets = $this->sold($record)->sortBy(fn (Record $ticket) => str_pad((string) $ticket->value('seat_number'), 4, '0', STR_PAD_LEFT));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Load', 'icon' => 'bus', 'stats' => [
                ['label' => 'Sold', 'value' => $record->value('seats_sold').' / '.(int) $record->value('seats')],
                ['label' => 'Left', 'value' => (string) $record->value('_seats_left'), 'tone' => (int) $record->value('_seats_left') === 0 ? 'success' : null],
                ['label' => 'Boarded', 'value' => (string) $tickets->where('status', 'boarded')->count()],
                ['label' => 'Takings', 'value' => $this->money($record->value('_takings'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Passenger list', 'icon' => 'clipboard-list', 'empty' => 'No seats sold.',
                'rows' => $tickets->map(fn (Record $ticket) => ['label' => 'Seat '.$ticket->value('seat_number').' · '.$ticket->title, 'sub' => trim(($ticket->value('phone') ?? '').' · '.(int) $ticket->value('luggage').' bag(s)', ' ·'), 'value' => ucfirst($ticket->status), 'href' => $ticket->url(), 'tone' => $ticket->status === 'boarded' ? 'success' : ($ticket->status === 'booked' ? 'warning' : null)])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $trips = $this->records('trips')->whereDate('occurs_on', today())->whereIn('status', ['scheduled', 'boarding', 'departed'])->get()->sortBy(fn (Record $trip) => $trip->value('departure_time'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => "Today's trips", 'icon' => 'bus', 'empty' => 'No trips today.',
            'rows' => $trips->map(fn (Record $trip) => ['label' => substr((string) $trip->value('departure_time'), 0, 5).' · '.$trip->title, 'sub' => $trip->value('bus').' · '.ucfirst($trip->status), 'value' => $trip->value('seats_sold').'/'.(int) $trip->value('seats'), 'href' => $trip->url(), 'tone' => (int) $trip->value('_seats_left') === 0 ? 'success' : null])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $trips = $this->dated('trips', $from, $to)->where('status', '!=', 'cancelled')->get();
        $tickets = $this->records('tickets')->get()->groupBy(fn (Record $ticket) => (int) $ticket->value('trip'));

        $byRoute = $trips->groupBy('title')->sortKeys()->map(function (Collection $group, string $route) use ($tickets) {
            $seats = $group->sum(fn (Record $trip) => (int) $trip->value('seats'));
            $all = $group->flatMap(fn (Record $trip) => $tickets->get($trip->id, collect()));
            $carried = $all->where('status', 'boarded')->count();
            $paid = $all->whereIn('status', ['paid', 'boarded', 'no_show']);

            return [$route, $group->count(), $carried, $seats ? (int) round($carried / $seats * 100).'%' : '—', $paid->count() ? (int) round($all->where('status', 'no_show')->count() / $paid->count() * 100).'%' : '—', $this->money($paid->sum('amount'))];
        })->values()->all();

        return [
            ['title' => 'Load by route', 'columns' => ['Route', 'Trips', 'Passengers carried', 'Load factor', 'No-show rate', 'Takings'], 'rows' => $byRoute],
        ];
    }
}
