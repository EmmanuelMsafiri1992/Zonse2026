<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Surveying & GIS jobs: field work needs a surveyor who is not already in the field that day,
 * coordinates are a valid "lat, lng" pair, a job is delivered only with its deliverables listed,
 * and only cadastral surveys are lodged, each under its own diagram number.
 */
class SurveyingLogic extends AppLogic
{
    public const FIELD_ONWARDS = ['fieldwork', 'processing', 'delivered', 'lodged'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (in_array($payload['status'], self::FIELD_ONWARDS, true) && blank($data['surveyor'] ?? null)) {
            $errors['data.surveyor'] = 'Assign a surveyor before the job goes to the field.';
        }
        if (filled($data['coordinates'] ?? null) && ! $this->coordinatesValid((string) $data['coordinates'])) {
            $errors['data.coordinates'] = 'Give coordinates as "latitude, longitude", e.g. -15.4167, 28.2833.';
        }
        if (in_array($payload['status'], ['delivered', 'lodged'], true) && blank($data['deliverables'] ?? null)) {
            $errors['data.deliverables'] = 'List what was delivered.';
        }
        if ($payload['status'] === 'lodged') {
            if (($data['type'] ?? null) !== 'cadastral') {
                $errors['status'] = 'Only cadastral surveys are lodged with the Surveyor General.';
            } elseif (blank($data['diagram_number'] ?? null)) {
                $errors['data.diagram_number'] = 'Record the diagram number the survey was lodged under.';
            }
        }
        if (filled($data['diagram_number'] ?? null) && $this->records('jobs')->where('data->diagram_number', $data['diagram_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['data.diagram_number'] = 'Diagram '.$data['diagram_number'].' is already lodged on another job.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The due date cannot be before the field date.';
        }
        if ($payload['status'] === 'fieldwork' && filled($data['surveyor'] ?? null) && filled($payload['occurs_on'] ?? null)) {
            $clash = $this->records('jobs')->where('status', 'fieldwork')->where('data->surveyor', (int) $data['surveyor'])->whereDate('occurs_on', Carbon::parse($payload['occurs_on'])->toDateString())
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($clash) {
                $errors['data.surveyor'] = 'This surveyor is already in the field that day on '.$clash->title.'.';
            }
        }

        return $errors;
    }

    protected function coordinatesValid(string $value): bool
    {
        $parts = array_map('trim', explode(',', $value));
        if (count($parts) !== 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return false;
        }

        return abs((float) $parts[0]) <= 90 && abs((float) $parts[1]) <= 180;
    }

    public function saving(Record $record): void
    {
        $this->put($record, ['_delivered_on' => in_array($record->status, ['delivered', 'lodged'], true) ? ($record->value('_delivered_on') ?? today()->toDateString()) : null]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'booked' => ['start_fieldwork' => ['label' => 'Start field work', 'icon' => 'locate-fixed']],
            'fieldwork' => ['process' => ['label' => 'Back from the field', 'icon' => 'cpu']],
            'processing' => ['deliver' => ['label' => 'Deliver', 'icon' => 'package-check', 'fields' => [
                ['name' => 'deliverables', 'label' => 'Deliverables', 'type' => 'textarea', 'value' => $record->value('deliverables')],
            ]]],
            'delivered' => $record->value('type') === 'cadastral' ? ['lodge' => ['label' => 'Lodge', 'icon' => 'landmark', 'fields' => [
                ['name' => 'diagram_number', 'label' => 'Diagram / SG number', 'type' => 'text', 'value' => $record->value('diagram_number')],
            ]]] : [],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start_fieldwork':
                $record->update(['status' => 'fieldwork']);

                return $record->title.' is in the field.';
            case 'process':
                $record->update(['status' => 'processing']);

                return $record->title.' is being processed.';
            case 'deliver':
                $deliverables = $request->validate(['deliverables' => ['required', 'string']])['deliverables'];
                $record->update(['status' => 'delivered', 'data' => [...(array) $record->data, 'deliverables' => $deliverables]]);

                return $record->title.' delivered.';
        }

        $diagram = $request->validate(['diagram_number' => ['required', 'string', 'max:50']])['diagram_number'];
        if ($this->records('jobs')->where('data->diagram_number', $diagram)->whereKeyNot($record->id)->exists()) {
            throw ValidationException::withMessages(['diagram_number' => 'Diagram '.$diagram.' is already lodged on another job.']);
        }
        $record->update(['status' => 'lodged', 'data' => [...(array) $record->data, 'diagram_number' => $diagram]]);

        return $record->title.' lodged as diagram '.$diagram.'.';
    }

    public function recordCards(Record $record): array
    {
        $types = $this->app->entities['jobs']->field('type')?->options ?? [];
        $surveyor = $record->value('surveyor') ? User::find($record->value('surveyor'))?->name : null;
        $late = $record->due_on?->lt(today()) && ! in_array($record->status, ['delivered', 'lodged'], true);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Survey', 'icon' => 'locate-fixed', 'stats' => [
            ['label' => 'Type', 'value' => $types[$record->value('type')] ?? ucfirst((string) $record->value('type'))],
            ['label' => 'Surveyor', 'value' => $surveyor ?? 'Unassigned', 'tone' => $surveyor ? null : 'warning'],
            ['label' => 'Field date', 'value' => $record->occurs_on?->format('d M Y') ?? '—'],
            ['label' => 'Due', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $late ? 'danger' : null],
            ['label' => 'Fee', 'value' => $record->amount !== null ? $this->money($record->amount) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $jobs = $this->records('jobs')->with('contact')->get();
        $open = $jobs->whereNotIn('status', ['delivered', 'lodged']);
        $overdue = $open->filter(fn (Record $job) => $job->due_on?->lt(today()));
        $thisWeek = $jobs->whereIn('status', ['booked', 'fieldwork'])->filter(fn (Record $job) => $job->occurs_on?->between(today()->startOfWeek(), today()->endOfWeek()))->sortBy('occurs_on');
        $names = User::whereIn('id', $thisWeek->pluck('data.surveyor')->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Jobs', 'icon' => 'locate-fixed', 'stats' => [
                ['label' => 'Booked', 'value' => (string) $open->where('status', 'booked')->count()],
                ['label' => 'In the field', 'value' => (string) $open->where('status', 'fieldwork')->count()],
                ['label' => 'Processing', 'value' => (string) $open->where('status', 'processing')->count()],
                ['label' => 'Overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "This week's field work", 'icon' => 'calendar-days', 'empty' => 'No field work booked this week.',
                'rows' => $thisWeek->map(fn (Record $job) => [
                    'label' => $job->title, 'sub' => trim(($job->contact?->name ?? '').' · '.($names[$job->value('surveyor')] ?? 'Unassigned'), ' ·'), 'value' => $job->occurs_on->format('D d M'), 'href' => $job->url(),
                    'tone' => $job->value('surveyor') ? null : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('jobs', $from, $to)->get();
        $types = $this->app->entities['jobs']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $key) => [
            $label, $jobs->where('data.type', $key)->count(), $jobs->where('data.type', $key)->whereIn('status', ['delivered', 'lodged'])->count(), $this->money($jobs->where('data.type', $key)->sum('amount')),
        ])->values()->all();

        $names = User::whereIn('id', $jobs->pluck('data.surveyor')->filter()->unique())->pluck('name', 'id');
        $workload = $jobs->groupBy(fn (Record $job) => $names[$job->value('surveyor')] ?? 'Unassigned')->sortKeys()->map(fn ($group, $surveyor) => [
            $surveyor, $group->count(), $group->whereNotIn('status', ['delivered', 'lodged'])->count(), $this->money($group->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Jobs by type', 'columns' => ['Type', 'Jobs', 'Delivered', 'Fees'], 'rows' => $byType],
            ['title' => 'Surveyor workload', 'columns' => ['Surveyor', 'Jobs', 'Open', 'Fees'], 'rows' => $workload],
        ];
    }
}
