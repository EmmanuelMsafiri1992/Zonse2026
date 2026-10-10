<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Airport shuttles & chauffeur services: an airport pick-up needs the flight number and shows the passenger's
 * name on the board unless other text is given. The party must fit the vehicle class, and a driver can't take
 * two transfers less than two hours apart. A transfer is assigned, on route and then completed or marked as a
 * no-show. The home page lists today's transfers in pick-up order.
 */
class AirportTransferLogic extends AppLogic
{
    /**
     * Passengers each vehicle class carries.
     *
     * @var array<string, int>
     */
    public const SEATS = ['sedan' => 3, 'suv' => 6, 'minibus' => 14, 'luxury' => 3];

    /**
     * Minutes a driver needs between pick-ups.
     */
    public const GAP_MINUTES = 120;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (($data['type'] ?? null) === 'airport_pickup' && blank($data['flight_number'] ?? null)) {
            $errors['data.flight_number'] = 'Give the flight number for an airport pick-up.';
        }
        $class = $data['vehicle_class'] ?? null;
        if (isset(self::SEATS[$class]) && (int) ($data['passengers'] ?? 0) > self::SEATS[$class]) {
            $errors['data.passengers'] = 'A '.$class.' carries '.self::SEATS[$class].' passengers at most.';
        }
        if (in_array($payload['status'], ['assigned', 'on_route'], true) && blank($data['driver'] ?? null)) {
            $errors['data.driver'] = 'Give the driver.';
        }
        if (filled($data['driver'] ?? null) && filled($data['pickup_time'] ?? null) && ! in_array($payload['status'], ['completed', 'no_show', 'cancelled'], true)
            && ($clash = $this->clash((int) $data['driver'], filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today(), (string) $data['pickup_time'], $existing))) {
            $errors['data.driver'] = 'The driver has '.$clash->title.' at '.substr((string) $clash->value('pickup_time'), 0, 5).'.';
        }

        return $errors;
    }

    /**
     * Another transfer the driver has too close to the given pick-up.
     */
    protected function clash(int $driver, Carbon $date, string $time, ?Record $existing): ?Record
    {
        $minutes = fn (string $value) => (int) substr($value, 0, 2) * 60 + (int) substr($value, 3, 2);

        return $this->records('transfers')->whereIn('status', ['booked', 'assigned', 'on_route'])->whereDate('occurs_on', $date->toDateString())
            ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $other) => (int) $other->value('driver') === $driver && abs($minutes((string) $other->value('pickup_time')) - $minutes($time)) < self::GAP_MINUTES);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->value('type') === 'airport_pickup' && blank($record->value('name_board'))) {
            $this->put($record, ['name_board' => $record->title]);
        }
        if (filled($record->value('flight_number'))) {
            $this->put($record, ['flight_number' => strtoupper(str_replace(' ', '', (string) $record->value('flight_number')))]);
        }
    }

    /**
     * The workspace members who can drive, by id.
     *
     * @return array<int, string>
     */
    protected function drivers(Record $record): array
    {
        return Workspace::query()->find($record->workspace_id)?->members()->orderBy('users.name')->pluck('users.name', 'users.id')->all() ?? [];
    }

    public function actions(Record $record): array
    {
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x']];

        return match ($record->status) {
            'booked' => ['assign' => ['label' => 'Assign driver', 'icon' => 'user-check', 'fields' => [['name' => 'driver', 'label' => 'Driver', 'type' => 'select', 'options' => $this->drivers($record), 'value' => $record->value('driver')]]], ...$cancel],
            'assigned' => ['on_route' => ['label' => 'On route', 'icon' => 'car'], ...$cancel],
            'on_route' => ['complete' => ['label' => 'Completed', 'icon' => 'flag'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'assign':
                $driver = (int) $request->validate(['driver' => ['required', 'integer']])['driver'];
                if (filled($record->value('pickup_time')) && ($clash = $this->clash($driver, $record->occurs_on, (string) $record->value('pickup_time'), $record))) {
                    throw ValidationException::withMessages(['driver' => 'The driver has '.$clash->title.' at '.substr((string) $clash->value('pickup_time'), 0, 5).'.']);
                }
                $record->update(['status' => 'assigned', 'data' => [...$record->data, 'driver' => $driver]]);

                return $record->title.'\'s transfer assigned to '.User::query()->whereKey($driver)->value('name').'.';
            case 'on_route':
                $record->update(['status' => 'on_route']);

                return 'Driver on route to '.$record->value('pickup').'.';
            case 'complete':
                $record->update(['status' => 'completed']);

                return $record->title.'\'s transfer completed.';
            case 'no_show':
                $record->update(['status' => 'no_show']);

                return $record->title.' did not show.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s transfer cancelled.';
        }
    }

    public function homeCards(): array
    {
        $today = $this->records('transfers')->whereIn('status', ['booked', 'assigned', 'on_route'])->whereDate('occurs_on', today()->toDateString())->get();
        $names = User::query()->whereIn('id', $today->map(fn (Record $transfer) => $transfer->value('driver'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s transfers', 'icon' => 'plane-landing', 'empty' => 'No more transfers today.',
                'rows' => $today->sortBy(fn (Record $transfer) => (string) $transfer->value('pickup_time'))
                    ->map(fn (Record $transfer) => [
                        'label' => $transfer->title.(filled($transfer->value('flight_number')) ? ' · '.$transfer->value('flight_number') : ''),
                        'sub' => $transfer->value('pickup').' → '.$transfer->value('dropoff').' · '.($names[$transfer->value('driver')] ?? 'No driver'),
                        'value' => substr((string) $transfer->value('pickup_time'), 0, 5), 'href' => $transfer->url(), 'tone' => blank($transfer->value('driver')) ? 'warning' : null,
                    ])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Bookings', 'icon' => 'calendar', 'stats' => [
                ['label' => 'Without a driver', 'value' => $this->records('transfers')->where('status', 'booked')->count()],
                ['label' => 'Next 7 days', 'value' => $this->records('transfers')->whereIn('status', ['booked', 'assigned'])->whereDate('occurs_on', '>', today()->toDateString())->whereDate('occurs_on', '<=', today()->addDays(7)->toDateString())->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $transfers = $this->dated('transfers', $from, $to)->whereIn('status', ['completed', 'no_show'])->get();
        $names = User::query()->whereIn('id', $transfers->map(fn (Record $transfer) => $transfer->value('driver'))->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Transfers by type', 'columns' => ['Type', 'Completed', 'No-shows', 'Fares'], 'rows' => $transfers
                ->groupBy(fn (Record $transfer) => ucfirst(str_replace('_', ' ', (string) $transfer->value('type'))))->sortKeys()
                ->map(fn ($group, string $type) => [$type, $group->where('status', 'completed')->count(), $group->where('status', 'no_show')->count(), $this->money($group->sum('amount'))])
                ->values()->all()],
            ['title' => 'Transfers by driver', 'columns' => ['Driver', 'Transfers', 'Fares'], 'rows' => $transfers->where('status', 'completed')
                ->groupBy(fn (Record $transfer) => $names[$transfer->value('driver')] ?? 'No driver')->sortKeys()
                ->map(fn ($group, string $driver) => [$driver, $group->count(), $this->money($group->sum('amount'))])
                ->values()->all()],
        ];
    }
}
