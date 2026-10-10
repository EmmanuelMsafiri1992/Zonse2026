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
 * Taxi & ride dispatch: a driver whose licence has expired can't take rides, and drivers with expired licences
 * are suspended overnight. A ride can only be given to an available driver, who is on a trip until the ride is
 * completed or cancelled. A completed ride records the company's share of the fare. The report shows rides,
 * fares and the company's share for each driver.
 */
class TaxiDispatchLogic extends AppLogic
{
    /**
     * Ride statuses where the driver is busy with the ride.
     *
     * @var list<string>
     */
    public const BUSY = ['assigned', 'picked_up'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'drivers') {
            if (in_array($payload['status'], ['available', 'on_trip'], true) && filled($data['licence_expiry'] ?? null) && Carbon::parse($data['licence_expiry'])->lt(today())) {
                $errors['data.licence_expiry'] = 'The driver\'s licence has expired.';
            }

            return $errors;
        }
        if (in_array($payload['status'], self::BUSY, true) && blank($data['driver'] ?? null)) {
            $errors['data.driver'] = 'Give the driver.';
        }
        $driverChanged = ! $existing || (string) $existing->value('driver') !== (string) ($data['driver'] ?? '');
        if (filled($data['driver'] ?? null) && $driverChanged && in_array($payload['status'], self::BUSY, true) && ($driver = $this->records('drivers')->find($data['driver'])) && ($problem = $this->unavailable($driver))) {
            $errors['data.driver'] = $problem;
        }

        return $errors;
    }

    /**
     * Why a driver can't take a ride, if there is a reason.
     */
    protected function unavailable(Record $driver): ?string
    {
        return match (true) {
            filled($driver->value('licence_expiry')) && Carbon::parse($driver->value('licence_expiry'))->lt(today()) => $driver->title.'\'s licence has expired.',
            $driver->status !== 'available' => $driver->title.' is '.str_replace('_', ' ', $driver->status).'.',
            default => null,
        };
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'rides') {
            return;
        }
        $record->occurs_on ??= today();
        if ($record->status === 'completed' && ($driver = $this->parent($record, 'driver'))) {
            $this->put($record, ['_company_share' => round((float) $record->amount * $this->number($driver, 'commission_percent') / 100, 2)]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'rides') {
            return;
        }
        if (($previous = $this->previousParent($record, 'driver')) && $previous->status === 'on_trip') {
            $previous->update(['status' => 'available']);
        }
        if (! ($driver = $this->parent($record, 'driver'))) {
            return;
        }
        $busy = in_array($record->status, self::BUSY, true);
        if ($busy && $driver->status === 'available') {
            $driver->update(['status' => 'on_trip']);
        } elseif (! $busy && $driver->status === 'on_trip' && ! $this->linked('rides', 'driver', $driver)->whereKeyNot($record->id)->whereIn('status', self::BUSY)->exists()) {
            $driver->update(['status' => 'available']);
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('drivers')->whereIn('status', ['available', 'off_duty'])->get()
            ->filter(fn (Record $driver) => filled($driver->value('licence_expiry')) && Carbon::parse($driver->value('licence_expiry'))->lt(today()))
            ->each(fn (Record $driver) => $driver->update(['status' => 'suspended']))
            ->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'rides') {
            return [];
        }
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x']];

        return match ($record->status) {
            'requested' => ['assign' => ['label' => 'Dispatch', 'icon' => 'send', 'fields' => [['name' => 'driver', 'label' => 'Driver', 'type' => 'select', 'options' => $this->records('drivers')->where('status', 'available')->orderBy('title')->pluck('title', 'id')->all()]]], ...$cancel],
            'assigned' => ['pick_up' => ['label' => 'Picked up', 'icon' => 'user-check'], ...$cancel],
            'picked_up' => ['complete' => ['label' => 'Drop-off', 'icon' => 'flag', 'fields' => [
                ['name' => 'fare', 'label' => 'Fare', 'type' => 'number', 'value' => $record->amount],
                ['name' => 'distance', 'label' => 'Distance (km)', 'type' => 'number', 'value' => $record->value('distance')],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'assign':
                $driver = $this->records('drivers')->find($request->validate(['driver' => ['required']])['driver']);
                if (! $driver || ($problem = $this->unavailable($driver))) {
                    throw ValidationException::withMessages(['driver' => $problem ?? 'Choose a driver.']);
                }
                $record->update(['status' => 'assigned', 'data' => [...$record->data, 'driver' => $driver->id]]);

                return $driver->title.' dispatched to '.$record->value('pickup').'.';
            case 'pick_up':
                $record->update(['status' => 'picked_up', 'data' => [...$record->data, 'pickup_time' => $record->value('pickup_time') ?: now()->format('H:i')]]);

                return $record->title.' picked up.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s ride cancelled.';
            default:
                $input = $request->validate(['fare' => ['required', 'numeric', 'min:0'], 'distance' => ['nullable', 'numeric', 'min:0']]);
                $record->update(['status' => 'completed', 'amount' => $input['fare'], 'data' => [...$record->data, 'distance' => $input['distance'] ?? $record->value('distance')]]);

                return $record->title.' dropped off; fare '.$this->money($record->amount).'.';
        }
    }

    public function homeCards(): array
    {
        $drivers = $this->records('drivers')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Dispatch', 'icon' => 'car-taxi-front', 'stats' => [
                ['label' => 'Waiting for a driver', 'value' => $this->records('rides')->where('status', 'requested')->count()],
                ['label' => 'Drivers available', 'value' => $drivers->where('status', 'available')->count()],
                ['label' => 'On a trip', 'value' => $drivers->where('status', 'on_trip')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Licences expiring in 30 days', 'icon' => 'id-card', 'empty' => 'No licences expiring soon.',
                'rows' => $drivers->where('status', '!=', 'suspended')->filter(fn (Record $driver) => filled($driver->value('licence_expiry')) && Carbon::parse($driver->value('licence_expiry'))->lte(today()->addDays(30)))
                    ->sortBy(fn (Record $driver) => $driver->value('licence_expiry'))
                    ->map(fn (Record $driver) => ['label' => $driver->title, 'sub' => (string) $driver->value('vehicle'), 'value' => Carbon::parse($driver->value('licence_expiry'))->format('d M Y'), 'href' => $driver->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $rides = $this->dated('rides', $from, $to)->where('status', 'completed')->get();
        $names = $this->records('drivers')->pluck('title', 'id');

        return [['title' => 'Rides by driver', 'columns' => ['Driver', 'Rides', 'Distance (km)', 'Fares', 'Company share'], 'rows' => $rides
            ->groupBy(fn (Record $ride) => $names[(int) $ride->value('driver')] ?? 'No driver')->sortKeys()
            ->map(fn ($group, string $driver) => [$driver, $group->count(), round($group->sum(fn (Record $ride) => $this->number($ride, 'distance')), 1), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $ride) => $this->number($ride, '_company_share')))])
            ->values()->all()]];
    }
}
