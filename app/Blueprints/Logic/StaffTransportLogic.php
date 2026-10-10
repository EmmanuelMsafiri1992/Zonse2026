<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * School bus & staff transport: passengers are only added to active routes. Each route has one morning and
 * one afternoon trip log a day, the odometer can't go below the route's last reading, and a regular run
 * can't carry more passengers than are on the route. Each route shows its passengers, fees and recent trips,
 * and the home page lists routes with no trip logged yet today.
 */
class StaffTransportLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'routes' || blank($data['route'] ?? null) || ! ($route = $this->records('routes')->find($data['route']))) {
            return $errors;
        }
        $routeChanged = ! $existing || (string) $existing->value('route') !== (string) $data['route'];
        if ($routeChanged && $route->status !== 'active') {
            $errors['data.route'] = $route->title.' is suspended.';
        }
        if ($entity->key !== 'trips' || $payload['status'] === 'cancelled') {
            return $errors;
        }
        $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on'])->toDateString() : today()->toDateString();
        $run = $data['run'] ?? 'morning';
        $others = $this->linked('trips', 'route', $route)->where('status', '!=', 'cancelled')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
        if ($run !== 'special' && $others->first(fn (Record $trip) => $trip->occurs_on?->toDateString() === $date && $trip->value('run') === $run)) {
            $errors['data.run'] = 'The '.$run.' run on '.$route->title.' is already logged for '.Carbon::parse($date)->format('d M Y').'.';
        }
        $last = $others->filter(fn (Record $trip) => $trip->occurs_on && $trip->occurs_on->toDateString() <= $date)->max(fn (Record $trip) => $this->number($trip, 'odometer'));
        if (filled($data['odometer'] ?? null) && $last && (float) $data['odometer'] < $last) {
            $errors['data.odometer'] = 'The odometer can\'t go below the last reading of '.number_format($last).'.';
        }
        $onRoute = $this->linked('passengers', 'route', $route)->where('status', 'active')->count();
        if ($run !== 'special' && (int) ($data['passengers_carried'] ?? 0) > $onRoute) {
            $errors['data.passengers_carried'] = $route->title.' only has '.$onRoute.' '.str('passenger')->plural($onRoute).'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'trips') {
            return;
        }
        $record->occurs_on ??= today();
        if ($route = $this->parent($record, 'route')) {
            $record->title = $route->title;
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'routes') {
            return [];
        }
        $passengers = $this->linked('passengers', 'route', $record)->where('status', 'active')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Route', 'icon' => 'users', 'stats' => [
                ['label' => 'Passengers', 'value' => $passengers->count()],
                ['label' => 'Monthly fees', 'value' => $this->money($passengers->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Recent trips', 'icon' => 'clipboard-list', 'empty' => 'No trips logged yet.',
                'rows' => $this->linked('trips', 'route', $record)->orderByDesc('occurs_on')->limit(10)->get()
                    ->map(fn (Record $trip) => ['label' => $trip->occurs_on->format('d M Y').' · '.$trip->value('run'), 'sub' => (int) $trip->value('passengers_carried').' carried', 'value' => $trip->status, 'href' => $trip->url(), 'tone' => match ($trip->status) {
                        'late' => 'warning', 'cancelled' => 'danger', default => null,
                    }])->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $logged = $this->records('trips')->whereDate('occurs_on', today()->toDateString())->get()->map(fn (Record $trip) => (int) $trip->value('route'))->all();
        $month = $this->records('trips')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'No trip logged today', 'icon' => 'route', 'empty' => 'Every route has a trip logged today.',
                'rows' => $this->records('routes')->where('status', 'active')->orderBy('title')->get()->reject(fn (Record $route) => in_array($route->id, $logged, true))
                    ->map(fn (Record $route) => ['label' => $route->title, 'sub' => (string) $route->value('driver'), 'value' => substr((string) $route->value('morning_departure'), 0, 5), 'href' => $route->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'bus', 'stats' => [
                ['label' => 'Trips', 'value' => $month->where('status', '!=', 'cancelled')->count()],
                ['label' => 'Late', 'value' => $month->where('status', 'late')->count()],
                ['label' => 'With incidents', 'value' => $month->filter(fn (Record $trip) => filled($trip->value('incidents')))->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Trips by route', 'columns' => ['Route', 'Completed', 'Late', 'Cancelled', 'Passengers carried', 'Distance (km)'], 'rows' => $this->dated('trips', $from, $to)->get()
            ->groupBy('title')->sortKeys()
            ->map(function ($group, string $route) {
                $readings = $group->map(fn (Record $trip) => $this->number($trip, 'odometer'))->filter();

                return [$route, $group->where('status', 'completed')->count(), $group->where('status', 'late')->count(), $group->where('status', 'cancelled')->count(), $group->sum(fn (Record $trip) => (int) $trip->value('passengers_carried')), $readings->count() > 1 ? number_format($readings->max() - $readings->min()) : '—'];
            })->values()->all()]];
    }
}
