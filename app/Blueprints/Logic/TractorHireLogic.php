<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Tractor hire: a job is priced at hectares times the rate, a machine under repair cannot be
 * booked and cannot take two open jobs on the same day; the machine's status follows its jobs,
 * and each machine adds up hectares worked, earnings and fuel per hectare.
 */
class TractorHireLogic extends AppLogic
{
    public const OPEN = ['booked', 'in_progress'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'jobs' || empty($payload['data']['machine']) || ! in_array($payload['status'], self::OPEN, true)) {
            return [];
        }

        $machine = $this->records('machines')->find($payload['data']['machine']);
        if (! $machine) {
            return [];
        }
        if ($machine->status === 'repair') {
            return ['data.machine' => $machine->title.' is under repair.'];
        }

        if (filled($payload['occurs_on'] ?? null)) {
            $clash = $this->linked('jobs', 'machine', $machine)->whereIn('status', self::OPEN)->whereDate('occurs_on', $payload['occurs_on'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($clash) {
                return ['occurs_on' => $machine->title.' is already booked by '.$clash->title.' that day.'];
            }
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'jobs' && $this->number($record, 'rate_per_ha') > 0 && $this->number($record, 'hectares') > 0) {
            $record->amount = round($this->number($record, 'hectares') * $this->number($record, 'rate_per_ha'), 2);
        }

        if ($record->entity === 'machines' && $record->exists) {
            $jobs = $this->linked('jobs', 'machine', $record)->get();
            $worked = $jobs->whereIn('status', ['done', 'paid']);
            $hectares = $worked->sum(fn (Record $job) => $this->number($job, 'hectares'));
            $fuel = $worked->sum(fn (Record $job) => $this->number($job, 'fuel'));
            $this->put($record, ['_hectares' => round($hectares, 1), '_earned' => round((float) $worked->sum('amount'), 2), '_fuel_per_ha' => $hectares > 0 && $fuel > 0 ? round($fuel / $hectares, 1) : null]);

            if ($record->status !== 'repair') {
                $record->status = match (true) {
                    $jobs->where('status', 'in_progress')->isNotEmpty() => 'in_field',
                    $jobs->where('status', 'booked')->isNotEmpty() => 'booked',
                    default => 'available',
                };
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'jobs') {
            $this->recalculate($this->parent($record, 'machine'));
            $this->recalculate($this->previousParent($record, 'machine'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'jobs') {
            $this->recalculate($this->parent($record, 'machine'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'machines') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Work done', 'icon' => 'tractor', 'stats' => [
            ['label' => 'Hectares', 'value' => $this->number($record, '_hectares').' ha'],
            ['label' => 'Earned', 'value' => $this->money($record->value('_earned'))],
            ['label' => 'Fuel', 'value' => $record->value('_fuel_per_ha') !== null ? $record->value('_fuel_per_ha').' L/ha' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $machines = $this->records('machines')->pluck('title', 'id');
        $jobs = $this->records('jobs')->whereIn('status', self::OPEN)->whereDate('occurs_on', '<=', today()->addDays(7))->orderBy('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Jobs this week', 'icon' => 'tractor', 'empty' => 'No jobs booked this week.',
            'rows' => $jobs->map(fn (Record $job) => [
                'label' => $job->title.' · '.ucfirst((string) $job->value('service')), 'sub' => ($machines[$job->value('machine')] ?? '—').' · '.$this->number($job, 'hectares').' ha',
                'value' => $job->occurs_on?->format('d M') ?? '—', 'href' => $job->url(), 'tone' => $job->status === 'in_progress' ? 'success' : ($job->occurs_on?->lt(today()) ? 'danger' : null),
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('jobs', $from, $to)->whereIn('status', ['done', 'paid'])->get();
        $ha = fn ($group) => $group->sum(fn (Record $job) => $this->number($job, 'hectares'));

        $machines = $this->records('machines')->orderBy('title')->get()->map(function (Record $machine) use ($jobs, $ha) {
            $mine = $jobs->where('data.machine', $machine->id);
            $fuel = $mine->sum(fn (Record $job) => $this->number($job, 'fuel'));

            return [$machine->title, $mine->count(), round($ha($mine), 1), $this->money($mine->sum('amount')), $ha($mine) > 0 && $fuel > 0 ? round($fuel / $ha($mine), 1) : '—'];
        })->all();

        $services = $jobs->groupBy(fn (Record $job) => (string) $job->value('service'))->sortKeys()
            ->map(fn ($group, $service) => [ucfirst($service), round($ha($group), 1), $this->money($group->sum('amount')), $ha($group) > 0 ? $this->money($group->sum('amount') / $ha($group)) : '—'])->values()->all();

        $unpaid = $this->records('jobs')->where('status', 'done')->get();

        return [
            ['title' => 'Work by machine', 'columns' => ['Machine', 'Jobs', 'Hectares', 'Earned', 'Fuel L/ha'], 'rows' => $machines],
            ['title' => 'Work by service', 'columns' => ['Service', 'Hectares', 'Earned', 'Average per ha'], 'rows' => $services],
            ['title' => 'Done but not paid', 'columns' => ['Jobs', 'Owed'], 'rows' => [[$unpaid->count(), $this->money($unpaid->sum('amount'))]]],
        ];
    }
}
