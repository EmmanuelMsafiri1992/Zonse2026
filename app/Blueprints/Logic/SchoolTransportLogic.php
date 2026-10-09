<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * School transport: pupils ride only on active routes, a trip is logged once per route and
 * direction a day and never carries more pupils than ride the route, and each route totals its
 * riders, fees and how often the bus ran on time.
 */
class SchoolTransportLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'routes') {
            return $errors;
        }

        $route = ! empty($data['route']) ? $this->records('routes')->find($data['route']) : null;

        if ($entity->key === 'riders') {
            if ($route && $route->status !== 'active' && $payload['status'] === 'active' && (! $existing || $existing->status !== 'active' || (int) $existing->value('route') !== $route->id)) {
                $errors['data.route'] = $route->title.' is suspended.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The transport fee cannot be negative.';
            }

            return $errors;
        }

        $pupils = (int) ($data['pupils'] ?? 0);
        if ($pupils < 0) {
            $errors['data.pupils'] = 'Pupils carried cannot be negative.';
        } elseif ($route && $pupils > ($riders = $this->linked('riders', 'route', $route)->where('status', 'active')->count())) {
            $errors['data.pupils'] = 'Only '.$riders.' pupils ride '.$route->title.'.';
        }
        if ($route && filled($data['direction'] ?? null) && filled($payload['occurs_on'] ?? null)) {
            $duplicate = $this->linked('trips', 'route', $route)->where('data->direction', $data['direction'])->whereDate('occurs_on', Carbon::parse($payload['occurs_on']))
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($duplicate) {
                $errors['data.direction'] = 'The '.$data['direction'].' trip on '.$route->title.' is already logged for that day.';
            }
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['occurs_on'] = 'A trip is logged after it happens.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'trips') {
            $record->occurs_on ??= today();
            $this->put($record, ['pupils' => (int) $record->value('pupils')]);

            return;
        }

        if ($record->entity === 'riders') {
            if (blank($record->amount) && ($route = $this->parent($record, 'route'))) {
                $last = $this->linked('riders', 'route', $route)->orderByDesc('id')->first();
                $record->amount = (float) ($last?->amount ?? 0);
            }

            return;
        }

        $riders = $record->exists ? $this->linked('riders', 'route', $record)->where('status', 'active')->get() : collect();
        $trips = $record->exists ? $this->linked('trips', 'route', $record)->get() : collect();
        $month = $trips->filter(fn (Record $trip) => $trip->occurs_on?->isCurrentMonth());
        $run = $month->whereIn('status', ['completed', 'delayed']);
        $this->put($record, [
            '_riders' => $riders->count(),
            '_fees' => round($riders->sum('amount'), 2),
            '_trips_month' => $run->count(),
            '_on_time_pct' => $run->isEmpty() ? null : round($run->where('status', 'completed')->count() / $run->count() * 100),
            '_last_trip' => $trips->max(fn (Record $trip) => $trip->occurs_on?->toDateString()),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'routes') {
            $this->recalculate($this->parent($record, 'route'));
            $this->recalculate($this->previousParent($record, 'route'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'routes') {
            $this->recalculate($this->parent($record, 'route'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'routes') {
            if ($record->status !== 'active') {
                return ['resume' => ['label' => 'Resume', 'icon' => 'play']];
            }
            $directions = $this->app->entities['trips']->field('direction')?->options ?? [];
            $statuses = ['completed' => 'On time', 'delayed' => 'Delayed', 'cancelled' => 'Cancelled'];

            return [
                'log_trip' => ['label' => 'Log today\'s trip', 'icon' => 'bus', 'fields' => [
                    ['name' => 'direction', 'label' => 'Direction', 'type' => 'select', 'options' => $directions, 'value' => now()->hour < 12 ? 'morning' : 'afternoon'],
                    ['name' => 'pupils', 'label' => 'Pupils carried', 'type' => 'number', 'value' => (int) $record->value('_riders')],
                    ['name' => 'outcome', 'label' => 'How did it go?', 'type' => 'select', 'options' => $statuses, 'value' => 'completed'],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ]],
                'suspend' => ['label' => 'Suspend', 'icon' => 'pause', 'confirm' => 'Suspend this route? Riders stay on it but no trips run.'],
            ];
        }

        if ($record->entity === 'riders') {
            return $record->status === 'active'
                ? ['stop' => ['label' => 'Stop riding', 'icon' => 'user-x', 'confirm' => 'Take this pupil off the route?']]
                : ['restart' => ['label' => 'Ride again', 'icon' => 'user-check']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'suspend':
                $record->update(['status' => 'suspended']);

                return $record->title.' is suspended.';
            case 'resume':
                $record->update(['status' => 'active']);

                return $record->title.' is running again.';
            case 'stop':
                $record->update(['status' => 'stopped']);

                return $record->title.' no longer rides.';
            case 'restart':
                $route = $this->parent($record, 'route');
                if ($route && $route->status !== 'active') {
                    throw ValidationException::withMessages(['data.route' => $route->title.' is suspended.']);
                }
                $record->update(['status' => 'active']);

                return $record->title.' rides again.';
        }

        $riders = (int) $record->value('_riders');
        $input = $request->validate([
            'direction' => ['required', 'in:morning,afternoon'],
            'pupils' => ['required', 'integer', 'min:0', 'max:'.$riders],
            'outcome' => ['required', 'in:completed,delayed,cancelled'],
            'notes' => ['nullable', 'string'],
        ]);
        if ($this->linked('trips', 'route', $record)->where('data->direction', $input['direction'])->whereDate('occurs_on', today())->exists()) {
            throw ValidationException::withMessages(['direction' => 'The '.$input['direction'].' trip is already logged for today.']);
        }
        Record::create([
            'workspace_id' => $record->workspace_id,
            'branch_id' => $record->branch_id,
            'blueprint' => $record->blueprint,
            'entity' => 'trips',
            'title' => $record->title.' · '.ucfirst($input['direction']).' '.today()->format('d M'),
            'status' => $input['outcome'],
            'occurs_on' => today(),
            'data' => ['route' => $record->id, 'direction' => $input['direction'], 'pupils' => (int) $input['pupils'], 'notes' => $input['notes'] ?? null],
        ]);

        return ucfirst($input['direction']).' trip logged with '.(int) $input['pupils'].' pupils'.($input['outcome'] === 'completed' ? '.' : ' ('.$input['outcome'].').');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'routes') {
            return [];
        }

        $riders = $this->linked('riders', 'route', $record)->where('status', 'active')->orderBy('data->stop')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Route', 'icon' => 'route', 'stats' => [
                ['label' => 'Bus', 'value' => $record->value('bus') ?: '—'],
                ['label' => 'Driver', 'value' => $record->value('driver') ?: '—'],
                ['label' => 'Riders', 'value' => (string) (int) $record->value('_riders')],
                ['label' => 'Fees', 'value' => $this->money($this->number($record, '_fees'))],
                ['label' => 'Trips this month', 'value' => (string) (int) $record->value('_trips_month')],
                ['label' => 'On time', 'value' => $record->value('_on_time_pct') === null ? '—' : $record->value('_on_time_pct').'%', 'tone' => $record->value('_on_time_pct') !== null && (int) $record->value('_on_time_pct') < 80 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Riders', 'icon' => 'backpack', 'empty' => 'Nobody rides this route.',
                'rows' => $riders->take(15)->map(fn (Record $rider) => [
                    'label' => $rider->title, 'sub' => $rider->value('stop') ?: 'Stop not set', 'value' => $this->money($rider->amount), 'href' => $rider->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $routes = $this->records('routes')->get();
        $active = $routes->where('status', 'active');
        $trips = $this->records('trips')->get();
        $today = $trips->filter(fn (Record $trip) => $trip->occurs_on?->isToday());
        $month = $trips->filter(fn (Record $trip) => $trip->occurs_on?->isCurrentMonth());
        $names = $routes->pluck('title', 'id');
        $notLogged = $active->filter(fn (Record $route) => ! $today->contains(fn (Record $trip) => (int) $trip->value('route') === $route->id && $trip->value('direction') === 'morning'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'School transport', 'icon' => 'bus', 'stats' => [
                ['label' => 'Active routes', 'value' => (string) $active->count()],
                ['label' => 'Riders', 'value' => (string) $active->sum(fn (Record $route) => (int) $route->value('_riders'))],
                ['label' => 'Transport fees', 'value' => $this->money($active->sum(fn (Record $route) => (float) $route->value('_fees')))],
                ['label' => 'Trips today', 'value' => (string) $today->count()],
                ['label' => 'Delayed this month', 'value' => (string) $month->where('status', 'delayed')->count(), 'tone' => $month->where('status', 'delayed')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Cancelled this month', 'value' => (string) $month->where('status', 'cancelled')->count(), 'tone' => $month->where('status', 'cancelled')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s trips', 'icon' => 'bus', 'empty' => 'No trips logged today.',
                'rows' => $today->sortBy(fn (Record $trip) => $trip->value('direction').$trip->title)->take(10)->map(fn (Record $trip) => [
                    'label' => $names[(int) $trip->value('route')] ?? $trip->title, 'sub' => ucfirst((string) $trip->value('direction')).' · '.(int) $trip->value('pupils').' pupils', 'value' => $trip->status === 'completed' ? 'On time' : ucfirst($trip->status), 'href' => $trip->url(),
                    'tone' => $trip->status === 'completed' ? 'success' : ($trip->status === 'delayed' ? 'warning' : 'danger'),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Morning trip not logged', 'icon' => 'alert-circle', 'empty' => 'Every route has logged this morning.',
                'rows' => $notLogged->take(10)->map(fn (Record $route) => [
                    'label' => $route->title, 'sub' => ($route->value('driver') ?: 'No driver').' · '.($route->value('bus') ?: 'No bus'), 'value' => $route->value('morning_departure') ?: '', 'href' => $route->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $routes = $this->records('routes')->get();
        $trips = $this->dated('trips', $from, $to)->get();
        $byRoute = $routes->sortBy('title')->map(function (Record $route) use ($trips) {
            $own = $trips->where('data.route', $route->id)->whereIn('status', ['completed', 'delayed']);

            return [$route->title, $route->value('driver') ?: '—', ucfirst($route->status), (int) $route->value('_riders'), $this->money($this->number($route, '_fees')), $own->count(), $own->isEmpty() ? '—' : round($own->where('status', 'completed')->count() / $own->count() * 100).'%', $own->sum(fn (Record $trip) => (int) $trip->value('pupils'))];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($trips) {
            $group = $trips->filter(fn (Record $trip) => $trip->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'delayed')->count(), $group->where('status', 'cancelled')->count(), $group->sum(fn (Record $trip) => (int) $trip->value('pupils'))];
        })->values()->all();

        $riders = $this->records('riders')->where('status', 'active')->get();
        $names = $routes->pluck('title', 'id');
        $byStop = $riders->groupBy(fn (Record $rider) => ($names[(int) $rider->value('route')] ?? 'Unknown').' · '.($rider->value('stop') ?: 'Stop not set'))->sortKeys()->map(fn ($group, $stop) => [
            $stop, $group->count(), $this->money($group->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Routes', 'columns' => ['Route', 'Driver', 'Status', 'Riders', 'Fees', 'Trips', 'On time', 'Pupils carried'], 'rows' => $byRoute],
            ['title' => 'Trips by month', 'columns' => ['Month', 'Trips', 'On time', 'Delayed', 'Cancelled', 'Pupils carried'], 'rows' => $byMonth],
            ['title' => 'Riders by stop', 'columns' => ['Route · stop', 'Riders', 'Fees'], 'rows' => $byStop],
        ];
    }
}
