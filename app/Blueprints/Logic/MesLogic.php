<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Shop-floor execution: a machine runs one production run at a time and can't start one while it is
 * down or in maintenance, and its status follows its runs. Downtime always needs a reason. A breakdown
 * stops the run on the machine. When a run ends its OEE is worked out: availability from the time it
 * ran less downtime, performance from the output against the machine's rated output per hour and
 * quality from the good parts against everything made.
 */
class MesLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'machines') {
            if ($existing && in_array($payload['status'], ['down', 'maintenance'], true) && $existing->status === 'running' && $this->runningOn($existing)) {
                $errors['status'] = 'Use "Breakdown" so the run on '.$existing->title.' is stopped too.';
            }

            return $errors;
        }

        if ($existing && in_array($existing->status, ['completed', 'stopped'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This run has ended.';
        }
        foreach (['planned_quantity', 'good_quantity', 'scrap', 'downtime_minutes'] as $field) {
            if ((float) ($data[$field] ?? 0) < 0) {
                $errors['data.'.$field] = 'This cannot be negative.';
            }
        }
        if ((int) ($data['downtime_minutes'] ?? 0) > 0 && blank($data['downtime_reason'] ?? null)) {
            $errors['data.downtime_reason'] = 'Say why the machine was down.';
        }
        $machine = filled($data['machine'] ?? null) ? $this->records('machines')->find($data['machine']) : null;
        if ($machine && $payload['status'] === 'running' && (! $existing || $existing->status !== 'running')) {
            if ($problem = $this->cannotStart($machine, $existing?->id)) {
                $errors['data.machine'] = $problem;
            }
        }

        return $errors;
    }

    /**
     * The run in progress on a machine.
     */
    protected function runningOn(Record $machine, ?int $except = null): ?Record
    {
        return $this->linked('runs', 'machine', $machine)->where('status', 'running')->when($except, fn ($query) => $query->whereKeyNot($except))->first();
    }

    /**
     * Why a run can't start on the machine, if it can't.
     */
    protected function cannotStart(Record $machine, ?int $except = null): ?string
    {
        if (in_array($machine->status, ['down', 'maintenance'], true)) {
            return $machine->title.' is '.$machine->status.'.';
        }
        if ($busy = $this->runningOn($machine, $except)) {
            return $machine->title.' is running '.$busy->title.' ('.$busy->number.').';
        }

        return null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'runs') {
            return;
        }
        $record->occurs_on ??= today();
        $good = (float) $record->value('good_quantity');
        $scrap = (float) $record->value('scrap');
        $planned = (float) $record->value('planned_quantity');
        $values = [
            '_quality' => $good + $scrap > 0 ? round($good / ($good + $scrap) * 100, 1) : null,
            '_attainment' => $planned > 0 ? round($good / $planned * 100, 1) : null,
        ];

        $started = $record->value('_started_at');
        $ended = $record->value('_ended_at');
        if ($started && $ended) {
            $minutes = max(1, Carbon::parse($started)->diffInMinutes(Carbon::parse($ended)));
            $running = max(0, $minutes - (int) $record->value('downtime_minutes'));
            $rated = (float) $this->parent($record, 'machine')?->value('rated_output');
            $availability = $running / $minutes;
            $performance = $rated > 0 && $running > 0 ? min(1, ($good + $scrap) / ($rated * $running / 60)) : null;
            $quality = $good + $scrap > 0 ? $good / ($good + $scrap) : null;
            $values += [
                '_run_minutes' => (int) round($minutes),
                '_availability' => round($availability * 100, 1),
                '_performance' => $performance === null ? null : round($performance * 100, 1),
                '_oee' => $performance === null || $quality === null ? null : round($availability * $performance * $quality * 100, 1),
            ];
        }
        $this->put($record, $values);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'runs' || ! ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            return;
        }
        $this->follow($this->parent($record, 'machine'));
        $this->follow($this->previousParent($record, 'machine'));
    }

    /**
     * Set a running or idle machine's status from its runs.
     */
    protected function follow(?Record $machine): void
    {
        if (! $machine || in_array($machine->status, ['down', 'maintenance'], true)) {
            return;
        }
        $status = $this->runningOn($machine) ? 'running' : 'idle';
        if ($machine->status !== $status) {
            $machine->update(['status' => $status]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'machines') {
            return match ($record->status) {
                'running', 'idle' => [
                    'breakdown' => ['label' => 'Breakdown', 'icon' => 'triangle-alert', 'fields' => [['name' => 'reason', 'label' => 'What happened', 'type' => 'text']]],
                    'maintain' => ['label' => 'Planned maintenance', 'icon' => 'wrench'],
                ],
                'down', 'maintenance' => ['repaired' => ['label' => 'Back in service', 'icon' => 'check']],
                default => [],
            };
        }

        $end = fn (string $label) => ['label' => $label, 'icon' => 'flag', 'fields' => [
            ['name' => 'good_quantity', 'label' => 'Good quantity', 'type' => 'number', 'value' => $record->value('good_quantity')],
            ['name' => 'scrap', 'label' => 'Scrap', 'type' => 'number', 'value' => $record->value('scrap')],
            ['name' => 'downtime_minutes', 'label' => 'Downtime (min)', 'type' => 'number', 'value' => $record->value('downtime_minutes')],
            ['name' => 'downtime_reason', 'label' => 'Downtime reason', 'type' => 'text', 'value' => $record->value('downtime_reason')],
        ]];

        return match ($record->status) {
            'planned' => ['start' => ['label' => 'Start run', 'icon' => 'play']],
            'running' => ['complete' => $end('Complete run'), 'stop' => $end('Stop run')],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'machines') {
            if ($action === 'repaired') {
                $record->update(['status' => 'idle', 'data' => [...$record->data, '_down_since' => null]]);

                return $record->title.' is back in service.';
            }
            $reason = $action === 'breakdown' ? trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']) : 'Planned maintenance';
            $run = $this->runningOn($record);
            $record->update(['status' => $action === 'breakdown' ? 'down' : 'maintenance', 'data' => [...$record->data, '_down_since' => now()->toDateTimeString(), '_down_reason' => $reason]]);
            $run?->update(['status' => 'stopped', 'data' => [...$run->data, '_ended_at' => now()->toDateTimeString(), 'downtime_reason' => $run->value('downtime_reason') ?: $reason]]);

            return $record->title.' is '.$record->status.($run ? '; '.$run->title.' stopped.' : '.');
        }

        if ($action === 'start') {
            $machine = $this->parent($record, 'machine');
            if ($machine && ($problem = $this->cannotStart($machine, $record->id))) {
                throw ValidationException::withMessages(['machine' => $problem]);
            }
            $record->update(['status' => 'running', 'data' => [...$record->data, '_started_at' => now()->toDateTimeString()]]);

            return $record->title.' started on '.($machine?->title ?? 'the line').'.';
        }

        $values = $request->validate([
            'good_quantity' => ['required', 'numeric', 'min:0'], 'scrap' => ['nullable', 'numeric', 'min:0'],
            'downtime_minutes' => ['nullable', 'integer', 'min:0'], 'downtime_reason' => ['nullable', 'string', 'max:255'],
        ]);
        if ((int) ($values['downtime_minutes'] ?? 0) > 0 && blank($values['downtime_reason'] ?? null)) {
            throw ValidationException::withMessages(['downtime_reason' => 'Say why the machine was down.']);
        }
        $record->update(['status' => $action === 'complete' ? 'completed' : 'stopped', 'data' => [...$record->data, ...$values, '_ended_at' => now()->toDateTimeString()]]);

        return $record->title.' '.$record->status.': '.number_format((float) $record->value('good_quantity')).' good, '.number_format((float) $record->value('scrap')).' scrap'.($record->value('_oee') !== null ? ', OEE '.$record->value('_oee').'%.' : '.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'runs' || $record->value('_run_minutes') === null) {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'OEE', 'icon' => 'gauge', 'stats' => [
            ['label' => 'Availability', 'value' => $record->value('_availability').'%'],
            ['label' => 'Performance', 'value' => $record->value('_performance') !== null ? $record->value('_performance').'%' : '—'],
            ['label' => 'Quality', 'value' => $record->value('_quality') !== null ? $record->value('_quality').'%' : '—'],
            ['label' => 'OEE', 'value' => $record->value('_oee') !== null ? $record->value('_oee').'%' : '—', 'tone' => $record->value('_oee') !== null && $record->value('_oee') < 60 ? 'danger' : 'success'],
        ]]]];
    }

    public function homeCards(): array
    {
        $machines = $this->records('machines')->orderBy('title')->get();
        $running = $this->records('runs')->where('status', 'running')->get()->keyBy(fn (Record $run) => (int) $run->value('machine'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Floor now', 'icon' => 'factory', 'empty' => 'No machines yet.',
            'rows' => $machines->map(fn (Record $machine) => [
                'label' => $machine->title,
                'sub' => $running->has($machine->id) ? $running[$machine->id]->title : ($machine->value('_down_reason') && $machine->status !== 'idle' ? $machine->value('_down_reason') : $machine->value('line')),
                'value' => ucfirst($machine->status), 'href' => $machine->url(),
                'tone' => match ($machine->status) {
                    'running' => 'success', 'down' => 'danger', 'maintenance' => 'warning', default => null
                },
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $runs = $this->dated('runs', $from, $to)->whereIn('status', ['completed', 'stopped'])->get();
        $names = $this->records('machines')->pluck('title', 'id');

        $byMachine = $runs->groupBy(fn (Record $run) => (int) $run->value('machine'))
            ->map(function (Collection $group, int $machine) use ($names) {
                $timed = $group->filter(fn (Record $run) => $run->value('_oee') !== null);

                return [
                    $names[$machine] ?? '—', $group->count(),
                    number_format($group->sum(fn (Record $run) => (float) $run->value('good_quantity'))),
                    number_format($group->sum(fn (Record $run) => (float) $run->value('scrap'))),
                    $group->sum(fn (Record $run) => (int) $run->value('downtime_minutes')).' min',
                    $timed->isNotEmpty() ? round($timed->avg(fn (Record $run) => (float) $run->value('_oee')), 1).'%' : '—',
                ];
            })->sortBy(fn (array $row) => $row[0])->values()->all();

        $reasons = $runs->filter(fn (Record $run) => (int) $run->value('downtime_minutes') > 0)
            ->groupBy(fn (Record $run) => ucfirst(mb_strtolower(trim((string) $run->value('downtime_reason')))) ?: '—')
            ->map(fn (Collection $group, string $reason) => [$reason, $group->count(), $group->sum(fn (Record $run) => (int) $run->value('downtime_minutes'))])
            ->sortByDesc(fn (array $row) => $row[2])->map(fn (array $row) => [$row[0], $row[1], $row[2].' min'])->values()->all();

        $byProduct = $runs->groupBy(fn (Record $run) => $run->title)->sortKeys()
            ->map(function (Collection $group, string $product) {
                $good = $group->sum(fn (Record $run) => (float) $run->value('good_quantity'));
                $scrap = $group->sum(fn (Record $run) => (float) $run->value('scrap'));

                return [$product, number_format($good), number_format($scrap), $good + $scrap > 0 ? round($scrap / ($good + $scrap) * 100, 1).'%' : '—'];
            })->values()->all();

        return [
            ['title' => 'OEE by machine', 'columns' => ['Machine', 'Runs', 'Good', 'Scrap', 'Downtime', 'Average OEE'], 'rows' => $byMachine],
            ['title' => 'Downtime reasons', 'columns' => ['Reason', 'Runs', 'Minutes'], 'rows' => $reasons],
            ['title' => 'Scrap by product', 'columns' => ['Product', 'Good', 'Scrap', 'Scrap rate'], 'rows' => $byProduct],
        ];
    }
}
