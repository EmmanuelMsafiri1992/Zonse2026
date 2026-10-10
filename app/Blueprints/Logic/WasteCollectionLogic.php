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
 * Waste management & refuse collection: an active collection point needs at least one bin, a collection day and
 * a route. Each morning a collection run is scheduled for every route with stops that day, and runs still
 * scheduled from earlier days are marked missed. A run counts the stops planned on its route; completing it
 * records the stops done and tonnage, and tonnage needs the landfill ticket. The report shows tonnage by route.
 */
class WasteCollectionLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'customers') {
            if ($payload['status'] === 'active') {
                if ((int) ($data['bins'] ?? 0) < 1) {
                    $errors['data.bins'] = 'An active collection point needs at least one bin.';
                }
                if (blank($data['collection_day'] ?? null)) {
                    $errors['data.collection_day'] = 'Give the collection day.';
                }
                if (blank($data['route'] ?? null)) {
                    $errors['data.route'] = 'Give the route.';
                }
            }

            return $errors;
        }
        if ($payload['status'] === 'completed' && blank($data['stops_done'] ?? null)) {
            $errors['data.stops_done'] = 'Give the number of stops done.';
        }
        if ((float) ($data['tonnage'] ?? 0) > 0 && blank($data['landfill_ticket'] ?? null)) {
            $errors['data.landfill_ticket'] = 'Give the landfill or weighbridge ticket for the tonnage.';
        }
        if ($payload['status'] === 'missed' && blank($data['missed_stops'] ?? null) && $existing?->status !== 'missed') {
            $errors['data.missed_stops'] = 'Say why the run was missed.';
        }

        return $errors;
    }

    /**
     * The active collection points on a route that are collected on a date.
     *
     * @return Collection<int, Record>
     */
    protected function stops(string $route, Carbon $date): Collection
    {
        $route = strtolower(trim($route));
        $day = strtolower($date->englishDayOfWeek);

        return $this->records('customers')->where('status', 'active')->get()
            ->filter(fn (Record $customer) => strtolower(trim((string) $customer->value('route'))) === $route && $customer->value('collection_day') === $day)->values();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'customers') {
            if (filled($record->value('route'))) {
                $this->put($record, ['route' => trim((string) $record->value('route'))]);
            }

            return;
        }
        $record->occurs_on ??= today();
        $stops = $this->stops($record->title, $record->occurs_on);
        $this->put($record, ['_stops_planned' => $stops->count(), '_bins_planned' => $stops->sum(fn (Record $customer) => (int) $customer->value('bins'))]);
    }

    public function daily(Workspace $workspace): int
    {
        $missed = $this->records('collections')->where('status', 'scheduled')->whereDate('occurs_on', '<', today()->toDateString())->get();
        $missed->each(fn (Record $run) => $run->update(['status' => 'missed', 'data' => [...$run->data, 'missed_stops' => $run->value('missed_stops') ?: 'Not run on the day.']]));

        $today = strtolower(today()->englishDayOfWeek);
        $scheduled = $this->records('collections')->whereDate('occurs_on', today()->toDateString())->pluck('title')->map(fn (string $route) => strtolower(trim($route)))->all();
        $routes = $this->records('customers')->where('status', 'active')->get()
            ->filter(fn (Record $customer) => $customer->value('collection_day') === $today && filled($customer->value('route')))
            ->map(fn (Record $customer) => trim((string) $customer->value('route')))->unique(fn (string $route) => strtolower($route))
            ->reject(fn (string $route) => in_array(strtolower($route), $scheduled, true));
        $routes->each(fn (string $route) => Record::create([
            'workspace_id' => $workspace->id, 'blueprint' => $this->app()->key, 'entity' => 'collections', 'title' => $route,
            'status' => 'scheduled', 'occurs_on' => today(), 'data' => [],
        ]));

        return $missed->count() + $routes->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'customers') {
            return match ($record->status) {
                'active' => ['suspend' => ['label' => 'Suspend', 'icon' => 'pause']],
                'suspended' => ['resume' => ['label' => 'Resume', 'icon' => 'play']],
                default => [],
            };
        }
        $complete = ['complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [
            ['name' => 'stops_done', 'label' => 'Stops done', 'type' => 'number', 'value' => $record->value('stops_done') ?? $record->value('_stops_planned')],
            ['name' => 'tonnage', 'label' => 'Tonnage (t)', 'type' => 'number', 'value' => $record->value('tonnage')],
            ['name' => 'landfill_ticket', 'label' => 'Landfill ticket', 'type' => 'text', 'value' => $record->value('landfill_ticket')],
            ['name' => 'missed_stops', 'label' => 'Missed stops', 'type' => 'textarea', 'value' => $record->value('missed_stops')],
        ]]];

        return match ($record->status) {
            'scheduled' => ['start' => ['label' => 'Start run', 'icon' => 'truck'], ...$complete],
            'in_progress' => $complete,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'suspend':
                $record->update(['status' => 'suspended']);

                return $record->title.' suspended.';
            case 'resume':
                $record->update(['status' => 'active']);

                return $record->title.' resumed.';
            case 'start':
                $record->update(['status' => 'in_progress', 'assignee_id' => $record->assignee_id ?? $request->user()->id]);

                return $record->title.' run started with '.$this->number($record, '_stops_planned').' stops.';
            default:
                $input = $request->validate(['stops_done' => ['required', 'integer', 'min:0'], 'tonnage' => ['nullable', 'numeric', 'min:0'], 'landfill_ticket' => ['nullable', 'string', 'max:100'], 'missed_stops' => ['nullable', 'string', 'max:2000']]);
                if ((float) ($input['tonnage'] ?? 0) > 0 && blank($input['landfill_ticket'] ?? null)) {
                    throw ValidationException::withMessages(['landfill_ticket' => 'Give the landfill or weighbridge ticket for the tonnage.']);
                }
                $record->update(['status' => 'completed', 'data' => [...$record->data, ...$input]]);
                $planned = (int) $record->value('_stops_planned');

                return $record->title.' completed: '.$input['stops_done'].' of '.$planned.' stops'.((float) ($input['tonnage'] ?? 0) > 0 ? ', '.(float) $input['tonnage'].' t' : '').'.';
        }
    }

    public function homeCards(): array
    {
        $month = $this->records('collections')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Today\'s runs', 'icon' => 'truck', 'empty' => 'No runs today.',
                'rows' => $month->filter(fn (Record $run) => $run->occurs_on->isToday())->sortBy('title')
                    ->map(fn (Record $run) => ['label' => $run->title, 'sub' => (int) $run->value('_stops_planned').' stops · '.(int) $run->value('_bins_planned').' bins', 'value' => str_replace('_', ' ', $run->status), 'href' => $run->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'recycle', 'stats' => [
                ['label' => 'Tonnage', 'value' => round($month->where('status', 'completed')->sum(fn (Record $run) => $this->number($run, 'tonnage')), 1).' t'],
                ['label' => 'Runs completed', 'value' => $month->where('status', 'completed')->count()],
                ['label' => 'Runs missed', 'value' => $month->where('status', 'missed')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $runs = $this->dated('collections', $from, $to)->get();
        $customers = $this->records('customers')->where('status', 'active')->get();

        return [
            ['title' => 'Tonnage by route', 'columns' => ['Route', 'Runs', 'Missed', 'Stops done', 'Tonnage (t)'], 'rows' => $runs
                ->groupBy('title')->sortKeys()
                ->map(fn ($group, string $route) => [$route, $group->where('status', 'completed')->count(), $group->where('status', 'missed')->count(), $group->sum(fn (Record $run) => (int) $run->value('stops_done')), round($group->sum(fn (Record $run) => $this->number($run, 'tonnage')), 1)])
                ->values()->all()],
            ['title' => 'Collection points by route', 'columns' => ['Route', 'Points', 'Bins', 'Monthly fees'], 'rows' => $customers
                ->groupBy(fn (Record $customer) => (string) $customer->value('route'))->sortKeys()
                ->map(fn ($group, string $route) => [$route, $group->count(), $group->sum(fn (Record $customer) => (int) $customer->value('bins')), $this->money($group->sum('amount'))])
                ->values()->all()],
        ];
    }
}
