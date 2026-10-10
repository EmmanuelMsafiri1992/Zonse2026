<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Garage & workshop: job cards only open on active vehicles, and the odometer reading in can't go below the
 * vehicle's last reading; each job card moves the vehicle's odometer forward. A job card moves from booked
 * to the workshop, waits for parts if needed, is ready, then collected. The vehicle shows its service history.
 */
class GarageLogic extends AppLogic
{
    /**
     * Job card statuses still in the garage's hands.
     *
     * @var list<string>
     */
    public const OPEN = ['booked', 'in_workshop', 'awaiting_parts', 'ready'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'job_cards' || blank($data['vehicle'] ?? null) || ! ($vehicle = $this->records('vehicles')->find($data['vehicle']))) {
            return $errors;
        }
        if (! $existing && $vehicle->status !== 'active') {
            $errors['data.vehicle'] = $vehicle->title.' is '.$vehicle->status.'.';
        }
        $reading = $this->number($vehicle, 'odometer');
        if (filled($data['odometer_in'] ?? null) && (float) $data['odometer_in'] < $reading && (! $existing || (float) $existing->value('odometer_in') !== (float) $data['odometer_in'])) {
            $errors['data.odometer_in'] = 'The odometer can\'t be lower than '.$vehicle->title.'\'s last reading of '.number_format($reading).' km.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'job_cards') {
            $record->occurs_on ??= today();
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'job_cards' && ($vehicle = $this->parent($record, 'vehicle')) && $this->number($record, 'odometer_in') > $this->number($vehicle, 'odometer')) {
            $vehicle->update(['data' => [...$vehicle->data, 'odometer' => $this->number($record, 'odometer_in')]]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'job_cards') {
            return [];
        }

        return match ($record->status) {
            'booked' => ['start' => ['label' => 'Into workshop', 'icon' => 'wrench']],
            'in_workshop' => [
                'parts' => ['label' => 'Awaiting parts', 'icon' => 'package'],
                'ready' => ['label' => 'Ready', 'icon' => 'check', 'fields' => [
                    ['name' => 'labour_hours', 'label' => 'Labour hours', 'type' => 'number', 'value' => $record->value('labour_hours')],
                    ['name' => 'amount', 'label' => 'Job total', 'type' => 'number', 'value' => $record->amount],
                ]],
            ],
            'awaiting_parts' => ['start' => ['label' => 'Parts arrived', 'icon' => 'wrench']],
            'ready' => ['collect' => ['label' => 'Collected', 'icon' => 'key']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $record->update(['status' => 'in_workshop']);

                return $record->title.' is in the workshop.';
            case 'parts':
                $record->update(['status' => 'awaiting_parts']);

                return $record->title.' is waiting for parts.';
            case 'ready':
                $input = $request->validate(['labour_hours' => ['nullable', 'numeric', 'min:0'], 'amount' => ['nullable', 'numeric', 'min:0']]);
                $record->update([
                    'status' => 'ready', 'amount' => $input['amount'] ?? $record->amount,
                    'data' => [...$record->data, 'labour_hours' => (float) ($input['labour_hours'] ?? $record->value('labour_hours'))],
                ]);

                return $record->title.' is ready for collection'.($record->amount > 0 ? ' ('.$this->money($record->amount).')' : '').'.';
            default:
                $record->update(['status' => 'collected']);

                return $record->title.' collected.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'vehicles') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Service history', 'icon' => 'history', 'empty' => 'No job cards yet.',
            'rows' => $this->linked('job_cards', 'vehicle', $record)->orderByDesc('occurs_on')->orderByDesc('id')->get()
                ->map(fn (Record $job) => [
                    'label' => $job->title, 'sub' => $job->occurs_on?->format('d M Y').(filled($job->value('odometer_in')) ? ' · '.number_format($this->number($job, 'odometer_in')).' km' : ''),
                    'value' => $this->money($job->amount), 'href' => $job->url(), 'tone' => in_array($job->status, self::OPEN, true) ? 'warning' : null,
                ])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('job_cards')->whereIn('status', self::OPEN)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Workshop', 'icon' => 'wrench', 'stats' => [
                ['label' => 'Booked', 'value' => $open->where('status', 'booked')->count()],
                ['label' => 'In the workshop', 'value' => $open->where('status', 'in_workshop')->count()],
                ['label' => 'Awaiting parts', 'value' => $open->where('status', 'awaiting_parts')->count()],
                ['label' => 'Ready to collect', 'value' => $open->where('status', 'ready')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Promised today or late', 'icon' => 'alarm-clock', 'empty' => 'Nothing promised for today.',
                'rows' => $open->where('status', '!=', 'ready')->filter(fn (Record $job) => $job->due_on && $job->due_on->lte(today()))->sortBy('due_on')
                    ->map(fn (Record $job) => ['label' => $job->title, 'sub' => str_replace('_', ' ', $job->status), 'value' => $job->due_on->format('d M'), 'href' => $job->url(), 'tone' => $job->due_on->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('job_cards', $from, $to)->where('status', '!=', 'cancelled')->get();

        return [['title' => 'Workshop by month', 'columns' => ['Month', 'Job cards', 'Collected', 'Labour hours', 'Value'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($jobs) {
                $inMonth = $jobs->filter(fn (Record $job) => $job->occurs_on->format('Y-m') === $month);

                return [$label, $inMonth->count(), $inMonth->where('status', 'collected')->count(), round($inMonth->sum(fn (Record $job) => $this->number($job, 'labour_hours')), 1), $this->money($inMonth->sum('amount'))];
            })->values()->all()]];
    }
}
