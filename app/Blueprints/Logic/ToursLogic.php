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
 * Tours & travel: a departure counts its seats from the bookings on it and never sells more than
 * the tour's maximum group, and a booking is priced at the tour's price per person. Bookings are
 * only taken on scheduled or confirmed departures of active tours, and a guide is never on two
 * departures that overlap. Cancelling a departure cancels its bookings. Each day, confirmed
 * departures that leave today start running and running ones that have returned are completed.
 */
class ToursLogic extends AppLogic
{
    /**
     * Departures that still take bookings.
     *
     * @var list<string>
     */
    protected const SELLING = ['scheduled', 'confirmed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'departures') {
            $start = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : null;
            $end = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : $start;
            if ($start && $end && $end->lt($start)) {
                $errors['due_on'] = 'The return cannot be before the departure.';
            }
            $tour = filled($data['tour'] ?? null) ? $this->records('tours')->find($data['tour']) : null;
            if ($tour && $tour->status === 'retired' && ! $existing) {
                $errors['data.tour'] = $tour->title.' is retired.';
            }
            if ($start && filled($data['guide'] ?? null) && $payload['status'] !== 'cancelled' && ($clash = $this->guideClash((int) $data['guide'], $start, $end ?? $start, $existing?->id))) {
                $errors['data.guide'] = 'The guide is on '.$clash->title.' from '.$clash->occurs_on->format('d M').'.';
            }
            if ($existing && $tour && (int) $tour->value('max_group') > 0 && $this->travellers($existing) > (int) $tour->value('max_group')) {
                $errors['data.tour'] = $tour->title.' takes '.(int) $tour->value('max_group').' and '.$this->travellers($existing).' are booked.';
            }

            return $errors;
        }

        if ($entity->key !== 'bookings') {
            return $errors;
        }

        $departure = filled($data['departure'] ?? null) ? $this->records('departures')->find($data['departure']) : null;
        if (! $departure || $payload['status'] === 'cancelled') {
            return $errors;
        }
        $moving = ! $existing || (int) $existing->value('departure') !== $departure->id;
        if ($moving && ! in_array($departure->status, self::SELLING, true)) {
            $errors['data.departure'] = $departure->title.' is '.$departure->status.' and not taking bookings.';
        }
        $max = (int) $this->parent($departure, 'tour')?->value('max_group');
        $others = $this->travellers($departure, $existing?->id);
        if ($max > 0 && $others + (int) ($data['travellers'] ?? 0) > $max) {
            $errors['data.travellers'] = 'Only '.max(0, $max - $others).' seat(s) left on '.$departure->title.'.';
        }

        return $errors;
    }

    /**
     * Travellers booked on a departure.
     */
    protected function travellers(Record $departure, ?int $except = null): int
    {
        return (int) $this->linked('bookings', 'departure', $departure)->where('status', '!=', 'cancelled')
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()->sum(fn (Record $booking) => (int) $booking->value('travellers'));
    }

    /**
     * Another departure the guide is on during these days.
     */
    protected function guideClash(int $guide, Carbon $start, Carbon $end, ?int $except = null): ?Record
    {
        return $this->records('departures')->whereIn('status', ['scheduled', 'confirmed', 'running'])
            ->when($except, fn ($query) => $query->whereKeyNot($except))
            ->where('occurs_on', '<=', $end->copy()->endOfDay())->get()
            ->first(fn (Record $departure) => (int) $departure->value('guide') === $guide && ($departure->due_on ?? $departure->occurs_on)->gte($start->copy()->startOfDay()));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'departures') {
            $tour = $this->parent($record, 'tour');
            $booked = $record->exists ? $this->travellers($record) : 0;
            $max = (int) $tour?->value('max_group');
            $this->put($record, ['seats_booked' => $booked, '_seats_left' => $max > 0 ? max(0, $max - $booked) : null]);

            return;
        }

        if ($record->entity === 'bookings') {
            $record->occurs_on ??= today();
            $this->put($record, ['travellers' => max(1, (int) $record->value('travellers'))]);
            $departure = $this->parent($record, 'departure');
            $price = (float) ($departure ? $this->parent($departure, 'tour')?->value('price_per_person') : 0);
            if ($record->amount === null && $price > 0) {
                $record->amount = $price * (int) $record->value('travellers');
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $this->recount($this->parent($record, 'departure'));
            $this->recount($this->previousParent($record, 'departure'));
        }
        if ($record->entity === 'departures' && $record->wasChanged('status') && $record->status === 'cancelled') {
            $this->linked('bookings', 'departure', $record)->where('status', '!=', 'cancelled')->get()
                ->each(fn (Record $booking) => $booking->update(['status' => 'cancelled', 'data' => [...$booking->data, '_cancel_reason' => 'Departure cancelled']]));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'bookings') {
            $this->recount($this->parent($record, 'departure'));
        }
    }

    protected function recount(?Record $departure): void
    {
        if ($departure && (int) $departure->value('seats_booked') !== $this->travellers($departure)) {
            $departure->save();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'departures') {
            return match ($record->status) {
                'scheduled' => ['confirm' => ['label' => 'Confirm departure', 'icon' => 'check'], 'cancel' => ['label' => 'Cancel departure', 'icon' => 'x', 'confirm' => 'Cancel the departure and every booking on it?']],
                'confirmed' => ['cancel' => ['label' => 'Cancel departure', 'icon' => 'x', 'confirm' => 'Cancel the departure and every booking on it?']],
                default => [],
            };
        }
        if ($record->entity === 'bookings') {
            return match ($record->status) {
                'enquiry' => ['book' => ['label' => 'Book', 'icon' => 'check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
                'booked' => ['paid' => ['label' => 'Mark paid', 'icon' => 'banknote'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
                default => [],
            };
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'departures') {
            if ($action === 'confirm') {
                if ($this->travellers($record) === 0) {
                    throw ValidationException::withMessages(['status' => 'Nobody is booked on this departure yet.']);
                }
                $record->update(['status' => 'confirmed']);

                return $record->title.' confirmed with '.$this->travellers($record).' traveller(s).';
            }
            $count = $this->linked('bookings', 'departure', $record)->where('status', '!=', 'cancelled')->count();
            $record->update(['status' => 'cancelled']);

            return $record->title.' cancelled; '.$count.' booking(s) cancelled.';
        }

        if ($action === 'cancel') {
            $record->update(['status' => 'cancelled']);

            return 'Booking for '.$record->title.' cancelled.';
        }
        $departure = $this->parent($record, 'departure');
        if ($action === 'book' && $departure && ! in_array($departure->status, self::SELLING, true)) {
            throw ValidationException::withMessages(['status' => $departure->title.' is not taking bookings.']);
        }
        $record->update(['status' => $action === 'book' ? 'booked' : 'paid']);

        return 'Booking for '.$record->title.($action === 'book' ? ' made: '.(int) $record->value('travellers').' traveller(s), '.$this->money($record->amount).'.' : ' paid.');
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        $this->records('departures')->where('status', 'confirmed')->where('occurs_on', '<=', today()->endOfDay())->get()
            ->each(function (Record $departure) use (&$changed) {
                $departure->update(['status' => 'running']);
                $changed++;
            });
        $this->records('departures')->where('status', 'running')->get()
            ->filter(fn (Record $departure) => ($departure->due_on ?? $departure->occurs_on)?->lt(today()))
            ->each(function (Record $departure) use (&$changed) {
                $departure->update(['status' => 'completed']);
                $changed++;
            });

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'departures') {
            return [];
        }
        $bookings = $this->linked('bookings', 'departure', $record)->where('status', '!=', 'cancelled')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Seats', 'icon' => 'users', 'stats' => [
                ['label' => 'Booked', 'value' => (string) $record->value('seats_booked')],
                ['label' => 'Left', 'value' => $record->value('_seats_left') === null ? 'No limit' : (string) $record->value('_seats_left'), 'tone' => $record->value('_seats_left') === 0 ? 'danger' : null],
                ['label' => 'Paid', 'value' => $this->money($bookings->where('status', 'paid')->sum('amount'))],
                ['label' => 'Unpaid', 'value' => $this->money($bookings->where('status', '!=', 'paid')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Manifest', 'icon' => 'clipboard-list', 'empty' => 'No bookings yet.',
                'rows' => $bookings->map(fn (Record $booking) => ['label' => $booking->title, 'sub' => (int) $booking->value('travellers').' traveller(s)'.($booking->value('pickup') ? ' · pick-up '.$booking->value('pickup') : ''), 'value' => ucfirst($booking->status), 'href' => $booking->url(), 'tone' => $booking->status === 'paid' ? 'success' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $upcoming = $this->records('departures')->whereIn('status', self::SELLING)->where('occurs_on', '>=', today()->startOfDay())->orderBy('occurs_on')->limit(10)->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Upcoming departures', 'icon' => 'calendar-days', 'empty' => 'No departures scheduled.',
            'rows' => $upcoming->map(fn (Record $departure) => [
                'label' => $departure->title, 'sub' => $departure->occurs_on->format('d M Y').' · '.ucfirst($departure->status),
                'value' => $departure->value('seats_booked').' booked'.($departure->value('_seats_left') !== null ? ', '.$departure->value('_seats_left').' left' : ''),
                'href' => $departure->url(), 'tone' => $departure->value('_seats_left') === 0 ? 'success' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $departures = $this->dated('departures', $from, $to)->get();
        $bookings = $this->records('bookings')->whereIn('status', ['booked', 'paid'])->get()->groupBy(fn (Record $booking) => (int) $booking->value('departure'));

        $byTour = $departures->where('status', '!=', 'cancelled')->groupBy(fn (Record $departure) => $this->parent($departure, 'tour')?->title ?? '—')->sortKeys()
            ->map(function (Collection $group, string $tour) use ($bookings) {
                $sold = $group->sum(fn (Record $departure) => (int) $departure->value('seats_booked'));
                $capacity = $group->sum(fn (Record $departure) => (int) $departure->value('seats_booked') + (int) $departure->value('_seats_left'));
                $revenue = $group->sum(fn (Record $departure) => $bookings->get($departure->id, collect())->sum('amount'));

                return [$tour, $group->count(), $sold, $capacity ? (int) round($sold / $capacity * 100).'%' : '—', $this->money($revenue)];
            })->values()->all();

        $cancelled = $departures->where('status', 'cancelled')->map(fn (Record $departure) => [$departure->title, $departure->occurs_on?->format('d M Y'), (int) $departure->value('seats_booked')])->values()->all();

        return [
            ['title' => 'Departures by tour', 'columns' => ['Tour', 'Departures', 'Travellers', 'Load', 'Revenue'], 'rows' => $byTour],
            ['title' => 'Cancelled departures', 'columns' => ['Departure', 'Date', 'Travellers affected'], 'rows' => $cancelled],
        ];
    }
}
