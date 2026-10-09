<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laboratory: a request's results fall due by its urgency (stat the same day, urgent the next day,
 * routine in three days) unless a date is set. A sample is collected with its type before it is
 * processed, a result needs the findings, and turnaround is measured from request to result, with
 * late and abnormal results flagged for the clinician.
 */
class LaboratoryLogic extends AppLogic
{
    /** @var array<string, int> */
    public const TURNAROUND_DAYS = ['stat' => 0, 'urgent' => 1, 'routine' => 3];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (in_array($payload['status'], ['sample_collected', 'processing', 'resulted'], true) && blank($data['sample_type'] ?? null)) {
            $errors['data.sample_type'] = 'Record the sample type.';
        }
        if ($payload['status'] === 'resulted' && blank($data['results'] ?? null)) {
            $errors['data.results'] = 'Enter the results.';
        }
        if ($existing && $existing->status === 'resulted' && $payload['status'] !== 'resulted') {
            $errors['status'] = 'A resulted request cannot go back.';
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'Results cannot be due before the request.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $urgency = (string) ($record->value('urgency') ?: 'routine');
        $record->due_on ??= $record->occurs_on->copy()->addDays(self::TURNAROUND_DAYS[$urgency] ?? 3);
        $requested = $record->value('_requested_at') ?? now()->toDateTimeString();
        $resulted = $record->status === 'resulted' ? ($record->value('_resulted_at') ?? now()->toDateTimeString()) : null;

        $this->put($record, [
            'urgency' => $urgency,
            'abnormal' => (bool) $record->value('abnormal'),
            '_requested_at' => $requested,
            '_collected_at' => in_array($record->status, ['sample_collected', 'processing', 'resulted'], true) ? ($record->value('_collected_at') ?? now()->toDateTimeString()) : null,
            '_resulted_at' => $resulted,
            '_turnaround_hours' => $resulted ? (int) Carbon::parse($requested)->diffInHours(Carbon::parse($resulted)) : null,
            '_late' => $resulted ? Carbon::parse($resulted)->startOfDay()->gt($record->due_on) : (! in_array($record->status, ['cancelled'], true) && $record->due_on->lt(today())),
        ]);
    }

    public function actions(Record $record): array
    {
        $cancel = ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]];

        return match ($record->status) {
            'requested' => ['collect' => ['label' => 'Sample collected', 'icon' => 'test-tube', 'fields' => [['name' => 'sample_type', 'label' => 'Sample type', 'type' => 'select', 'options' => $this->app->entities['requests']->field('sample_type')?->options ?? [], 'value' => $record->value('sample_type')]]], 'cancel' => $cancel],
            'sample_collected' => ['process' => ['label' => 'Start processing', 'icon' => 'flask-conical'], 'cancel' => $cancel],
            'processing' => ['result' => ['label' => 'Enter results', 'icon' => 'file-check', 'fields' => [
                ['name' => 'results', 'label' => 'Results', 'type' => 'textarea'],
                ['name' => 'abnormal', 'label' => 'Abnormal result', 'type' => 'checkbox'],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'collect':
                $type = $request->validate(['sample_type' => ['required', 'in:'.implode(',', array_keys($this->app->entities['requests']->field('sample_type')?->options ?? []))]])['sample_type'];
                $record->update(['status' => 'sample_collected', 'data' => [...$record->data, 'sample_type' => $type]]);

                return ucfirst($type).' sample collected from '.$record->title.'.';
            case 'process':
                $record->update(['status' => 'processing']);

                return 'Processing '.$record->title.'\'s sample.';
            case 'result':
                $input = $request->validate(['results' => ['required', 'string'], 'abnormal' => ['nullable', 'boolean']]);
                $abnormal = $request->boolean('abnormal');
                $record->update(['status' => 'resulted', 'data' => [...$record->data, 'results' => $input['results'], 'abnormal' => $abnormal]]);

                return 'Results for '.$record->title.' ready'.($abnormal ? ' — abnormal, tell '.($record->value('referred_by') ?: 'the clinician').'.' : '.');
        }

        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
        $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

        return 'Request for '.$record->title.' cancelled.';
    }

    public function recordCards(Record $record): array
    {
        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Request', 'icon' => 'test-tube', 'stats' => [
                ['label' => 'Urgency', 'value' => ucfirst((string) $record->value('urgency')), 'tone' => $record->value('urgency') === 'stat' ? 'danger' : ($record->value('urgency') === 'urgent' ? 'warning' : null)],
                ['label' => 'Results due', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_late') ? 'danger' : null],
                ['label' => 'Collected', 'value' => filled($record->value('_collected_at')) ? Carbon::parse($record->value('_collected_at'))->format('d M H:i') : '—'],
                ['label' => 'Turnaround', 'value' => $record->value('_turnaround_hours') === null ? '—' : (int) $record->value('_turnaround_hours').' h'],
                ['label' => 'Result', 'value' => $record->status === 'resulted' ? ($record->value('abnormal') ? 'Abnormal' : 'Normal') : '—', 'tone' => $record->value('abnormal') ? 'danger' : null],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $requests = $this->records('requests')->get();
        $open = $requests->whereIn('status', ['requested', 'sample_collected', 'processing']);
        $order = ['stat' => 0, 'urgent' => 1, 'routine' => 2];
        $queue = $open->sortBy(fn (Record $request) => ($order[$request->value('urgency')] ?? 2).'-'.$request->due_on?->toDateString());
        $abnormal = $requests->filter(fn (Record $request) => $request->status === 'resulted' && $request->value('abnormal') && filled($request->value('_resulted_at')) && Carbon::parse($request->value('_resulted_at'))->gte(now()->subDays(2)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Laboratory', 'icon' => 'flask-conical', 'stats' => [
                ['label' => 'Awaiting samples', 'value' => (string) $open->where('status', 'requested')->count()],
                ['label' => 'In the lab', 'value' => (string) $open->whereIn('status', ['sample_collected', 'processing'])->count()],
                ['label' => 'Stat / urgent', 'value' => (string) $open->filter(fn (Record $request) => in_array($request->value('urgency'), ['stat', 'urgent'], true))->count()],
                ['label' => 'Overdue', 'value' => (string) $open->filter(fn (Record $request) => $request->value('_late'))->count(), 'tone' => $open->contains(fn (Record $request) => $request->value('_late')) ? 'danger' : null],
                ['label' => 'Resulted today', 'value' => (string) $requests->filter(fn (Record $request) => filled($request->value('_resulted_at')) && Carbon::parse($request->value('_resulted_at'))->isToday())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Work queue', 'icon' => 'list-ordered', 'empty' => 'Nothing waiting.',
                'rows' => $queue->take(10)->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => ucfirst((string) $request->value('urgency')).' · '.ucfirst(str_replace('_', ' ', $request->status)), 'value' => $request->due_on?->format('d M'), 'href' => $request->url(), 'tone' => $request->value('urgency') === 'stat' || $request->value('_late') ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Abnormal results', 'icon' => 'triangle-alert', 'empty' => 'No abnormal results in the last two days.',
                'rows' => $abnormal->take(10)->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => (string) $request->value('referred_by'), 'value' => Carbon::parse($request->value('_resulted_at'))->format('d M H:i'), 'href' => $request->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($requests) {
            $group = $requests->filter(fn (Record $request) => $request->occurs_on?->format('Y-m') === $month);
            $resulted = $group->where('status', 'resulted');

            return [$label, $group->count(), $resulted->count(), $resulted->filter(fn (Record $request) => $request->value('abnormal'))->count(), $resulted->filter(fn (Record $request) => $request->value('_late'))->count(), $this->money($group->where('status', '!=', 'cancelled')->sum('amount'))];
        })->values()->all();

        $byUrgency = collect(['stat' => 'Stat', 'urgent' => 'Urgent', 'routine' => 'Routine'])->map(function (string $label, string $urgency) use ($requests) {
            $resulted = $requests->filter(fn (Record $request) => $request->value('urgency') === $urgency && $request->status === 'resulted');

            return [$label, $requests->filter(fn (Record $request) => $request->value('urgency') === $urgency)->count(), $resulted->isEmpty() ? '—' : round($resulted->avg(fn (Record $request) => (int) $request->value('_turnaround_hours')), 1).' h', $resulted->isEmpty() ? '—' : (int) round($resulted->reject(fn (Record $request) => $request->value('_late'))->count() / $resulted->count() * 100).'%'];
        })->values()->all();

        $byReferrer = $requests->groupBy(fn (Record $request) => $request->value('referred_by') ?: 'Walk-in')->sortKeys()->map(fn (Collection $group, string $referrer) => [$referrer, $group->count(), $group->filter(fn (Record $request) => $request->value('abnormal'))->count()])->values()->all();

        return [
            ['title' => 'Requests by month', 'columns' => ['Month', 'Requests', 'Resulted', 'Abnormal', 'Late', 'Fees'], 'rows' => $byMonth],
            ['title' => 'Turnaround by urgency', 'columns' => ['Urgency', 'Requests', 'Average turnaround', 'On time'], 'rows' => $byUrgency],
            ['title' => 'Requests by referrer', 'columns' => ['Referred by', 'Requests', 'Abnormal'], 'rows' => $byReferrer],
        ];
    }
}
