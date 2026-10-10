<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Fleet & vehicles: registrations are unique however they are typed, and odometers only go forward —
 * a fuel slip can't read less than the vehicle has already done. Each fill works out the distance
 * since the last one and the kilometres per litre. Finishing a service books the next one six months
 * or 15,000 km on, and each morning vehicles whose licence disc or insurance has run out come off the
 * road until it is renewed.
 */
class FleetLogic extends AppLogic
{
    /**
     * Months between services.
     */
    public const SERVICE_MONTHS = 6;

    /**
     * Kilometres between services.
     */
    public const SERVICE_KM = 15000;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return match ($entity->key) {
            'vehicles' => $this->validateVehicle($payload, $existing),
            'fuel' => $this->validateFuel($payload, $existing),
            default => $this->validateService($payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateVehicle(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $registration = $this->plate((string) $payload['title']);
        $twin = $this->records('vehicles')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $vehicle) => $this->plate((string) $vehicle->title) === $registration);
        if ($twin) {
            $errors['title'] = $twin->title.' is already in the fleet.';
        }
        $year = $data['year'] ?? null;
        if (filled($year) && ((int) $year < 1950 || (int) $year > (int) today()->year + 1)) {
            $errors['data.year'] = 'Give a year between 1950 and '.(today()->year + 1).'.';
        }
        if ($existing && (float) ($data['odometer'] ?? 0) < $this->number($existing, 'odometer')) {
            $errors['data.odometer'] = 'The odometer already reads '.number_format($this->number($existing, 'odometer')).' km; it cannot go back.';
        }
        if ($payload['status'] === 'active' && $existing?->status === 'off_road' && ($lapsed = $this->lapsed($data))) {
            $errors['status'] = 'The '.$lapsed.' has expired; renew it before putting the vehicle back on the road.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateFuel(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ((float) ($data['litres'] ?? 0) <= 0) {
            $errors['data.litres'] = 'Give the litres filled.';
        }
        $vehicle = filled($data['vehicle'] ?? null) ? $this->records('vehicles')->find($data['vehicle']) : null;
        if (! $vehicle) {
            return $errors;
        }
        if (! $existing && $vehicle->status === 'sold') {
            $errors['data.vehicle'] = $vehicle->title.' has been sold.';
        }
        $odometer = (float) ($data['odometer'] ?? 0);
        if ($odometer > 0) {
            $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
            $before = $this->linked('fuel', 'vehicle', $vehicle)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                ->whereDate('occurs_on', '<=', $date->toDateString())->get()->max(fn (Record $slip) => $this->number($slip, 'odometer'));
            if ($before && $odometer < $before) {
                $errors['data.odometer'] = $vehicle->title.' had already done '.number_format($before).' km by then.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateService(array $payload): array
    {
        return $payload['status'] === 'done' && (float) ($payload['data']['odometer'] ?? 0) <= 0
            ? ['data.odometer' => 'Record the odometer reading when the service is done.'] : [];
    }

    /**
     * A registration with spaces and dashes removed, upper-cased, for comparing.
     */
    public function plate(string $registration): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($registration));
    }

    /**
     * Which of a vehicle's papers has run out, if any.
     *
     * @param  array<string, mixed>  $data
     */
    protected function lapsed(array $data): ?string
    {
        foreach (['licence_expiry' => 'licence disc', 'insurance_expiry' => 'insurance'] as $field => $label) {
            if (filled($data[$field] ?? null) && Carbon::parse($data[$field])->lt(today())) {
                return $label;
            }
        }

        return null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'vehicles') {
            $record->title = mb_strtoupper(trim((string) $record->title));

            return;
        }
        $record->occurs_on ??= today();
        if ($record->entity !== 'fuel') {
            return;
        }
        $odometer = $this->number($record, 'odometer');
        $litres = $this->number($record, 'litres');
        $previous = $odometer > 0 && ($vehicle = $record->value('vehicle'))
            ? $this->linked('fuel', 'vehicle', (int) $vehicle)->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->get()
                ->filter(fn (Record $slip) => $this->number($slip, 'odometer') > 0 && $this->number($slip, 'odometer') < $odometer)
                ->max(fn (Record $slip) => $this->number($slip, 'odometer'))
            : null;
        $distance = $previous ? $odometer - $previous : null;
        $this->put($record, [
            '_km' => $distance,
            '_km_per_litre' => $distance && $litres > 0 ? round($distance / $litres, 2) : null,
            '_price_per_litre' => $litres > 0 && (float) $record->amount > 0 ? round((float) $record->amount / $litres, 2) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'vehicles' || ! ($vehicle = $this->parent($record, 'vehicle'))) {
            return;
        }
        $odometer = $this->number($record, 'odometer');
        $changes = [];
        if ($odometer > $this->number($vehicle, 'odometer')) {
            $changes['data'] = [...$vehicle->data, 'odometer' => $odometer];
        }
        if ($record->entity === 'services' && $record->status === 'done' && ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            $changes['data'] = [...($changes['data'] ?? $vehicle->data), '_next_service_km' => $odometer + self::SERVICE_KM, '_last_service' => $record->occurs_on?->toDateString()];
            $changes['due_on'] = ($record->occurs_on ?? today())->copy()->addMonthsNoOverflow(self::SERVICE_MONTHS);
            if ($vehicle->status === 'in_service') {
                $changes['status'] = 'active';
            }
        }
        if ($changes) {
            $vehicle->update($changes);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'services' && $record->status === 'booked') {
            return ['done' => ['label' => 'Service done', 'icon' => 'check', 'fields' => [
                ['name' => 'odometer', 'label' => 'Odometer (km)', 'type' => 'number'],
                ['name' => 'cost', 'label' => 'Cost', 'type' => 'number', 'value' => $record->amount],
            ]]];
        }
        if ($record->entity === 'vehicles' && $record->status === 'active') {
            return ['workshop' => ['label' => 'Into the workshop', 'icon' => 'wrench']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'workshop') {
            $record->update(['status' => 'in_service']);

            return $record->title.' is in the workshop.';
        }
        $vehicle = $this->parent($record, 'vehicle');
        $values = $request->validate([
            'odometer' => ['required', 'numeric', 'min:'.($vehicle ? $this->number($vehicle, 'odometer') : 0)],
            'cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $record->update(['status' => 'done', 'amount' => $values['cost'] ?? $record->amount, 'occurs_on' => $record->occurs_on && $record->occurs_on->lte(today()) ? $record->occurs_on : today(), 'data' => [...$record->data, 'odometer' => (float) $values['odometer']]]);
        $vehicle?->refresh();

        return $vehicle
            ? $vehicle->title.' serviced. Next service by '.$vehicle->due_on?->format('d M Y').' or '.number_format($this->number($vehicle, '_next_service_km')).' km.'
            : 'Service done.';
    }

    public function daily(Workspace $workspace): int
    {
        $grounded = 0;
        foreach ($this->records('vehicles')->whereIn('status', ['active', 'in_service'])->get() as $vehicle) {
            if ($lapsed = $this->lapsed((array) $vehicle->data)) {
                $vehicle->update(['status' => 'off_road', 'data' => [...$vehicle->data, '_off_road_reason' => ucfirst($lapsed).' expired']]);
                $grounded++;
            }
        }

        return $grounded;
    }

    /**
     * Distance, litres, cost and efficiency from a set of fuel slips.
     *
     * @param  Collection<int, Record>  $slips
     * @return array{litres: float, cost: float, km: float, km_per_litre: ?float, cost_per_km: ?float}
     */
    public function economy(Collection $slips): array
    {
        $measured = $slips->filter(fn (Record $slip) => $this->number($slip, '_km') > 0);
        $km = (float) $measured->sum(fn (Record $slip) => $this->number($slip, '_km'));
        $litres = (float) $measured->sum(fn (Record $slip) => $this->number($slip, 'litres'));
        $cost = (float) $measured->sum('amount');

        return [
            'litres' => (float) $slips->sum(fn (Record $slip) => $this->number($slip, 'litres')),
            'cost' => (float) $slips->sum('amount'),
            'km' => $km,
            'km_per_litre' => $km > 0 && $litres > 0 ? round($km / $litres, 2) : null,
            'cost_per_km' => $km > 0 && $cost > 0 ? round($cost / $km, 2) : null,
        ];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'vehicles') {
            return [];
        }
        $economy = $this->economy($this->linked('fuel', 'vehicle', $record)->get());
        $nextKm = $this->number($record, '_next_service_km');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Running costs', 'icon' => 'gauge', 'stats' => [
            ['label' => 'Fuel', 'value' => number_format($economy['litres'], 1).' L · '.$this->money($economy['cost'])],
            ['label' => 'Kilometres per litre', 'value' => $economy['km_per_litre'] !== null ? number_format($economy['km_per_litre'], 2) : '—'],
            ['label' => 'Fuel cost per km', 'value' => $economy['cost_per_km'] !== null ? $this->money($economy['cost_per_km']) : '—'],
            ['label' => 'Services', 'value' => $this->money($this->linked('services', 'vehicle', $record)->where('status', 'done')->sum('amount'))],
            ['label' => 'Next service', 'value' => trim(($record->due_on?->format('d M Y') ?? '').($nextKm > 0 ? ' or '.number_format($nextKm).' km' : '')) ?: '—',
                'tone' => ($record->due_on && $record->due_on->lt(today())) || ($nextKm > 0 && $this->number($record, 'odometer') >= $nextKm) ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $soon = today()->addDays(30);
        $rows = [];
        foreach ($this->records('vehicles')->where('status', '!=', 'sold')->orderBy('title')->get() as $vehicle) {
            $nextKm = $this->number($vehicle, '_next_service_km');
            $reasons = array_filter([
                $vehicle->due_on && $vehicle->due_on->lte($soon) ? 'service '.$vehicle->due_on->format('d M') : null,
                $nextKm > 0 && $this->number($vehicle, 'odometer') >= $nextKm - 1000 ? 'service at '.number_format($nextKm).' km' : null,
                filled($vehicle->value('licence_expiry')) && Carbon::parse($vehicle->value('licence_expiry'))->lte($soon) ? 'licence '.Carbon::parse($vehicle->value('licence_expiry'))->format('d M') : null,
                filled($vehicle->value('insurance_expiry')) && Carbon::parse($vehicle->value('insurance_expiry'))->lte($soon) ? 'insurance '.Carbon::parse($vehicle->value('insurance_expiry'))->format('d M') : null,
            ]);
            if ($reasons) {
                $rows[] = ['label' => $vehicle->title, 'sub' => $vehicle->value('make_model'), 'value' => implode(', ', $reasons), 'href' => $vehicle->url(), 'tone' => $vehicle->status === 'off_road' ? 'danger' : 'warning'];
            }
        }

        return [['view' => 'apps.logic.list-card', 'data' => ['title' => 'Coming due', 'icon' => 'calendar-clock', 'empty' => 'Nothing due in the next 30 days.', 'rows' => $rows]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $fuel = $this->dated('fuel', $from, $to)->get()->groupBy(fn (Record $slip) => (int) $slip->value('vehicle'));
        $services = $this->dated('services', $from, $to)->where('status', 'done')->get()->groupBy(fn (Record $service) => (int) $service->value('vehicle'));
        $vehicles = $this->records('vehicles')->orderBy('title')->get()->filter(fn (Record $vehicle) => $fuel->has($vehicle->id) || $services->has($vehicle->id));

        return [['title' => 'Running costs by vehicle', 'columns' => ['Vehicle', 'Litres', 'Fuel cost', 'Kilometres', 'Km per litre', 'Fuel cost per km', 'Services', 'Service cost'], 'rows' => $vehicles->map(function (Record $vehicle) use ($fuel, $services) {
            $economy = $this->economy($fuel->get($vehicle->id, collect()));
            $done = $services->get($vehicle->id, collect());

            return [$vehicle->title, number_format($economy['litres'], 1), $this->money($economy['cost']), number_format($economy['km']), $economy['km_per_litre'] !== null ? number_format($economy['km_per_litre'], 2) : '—',
                $economy['cost_per_km'] !== null ? $this->money($economy['cost_per_km']) : '—', $done->count(), $this->money($done->sum('amount'))];
        })->values()->all()]];
    }
}
