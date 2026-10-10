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
 * Quality management: a non-conformance is closed only once its root cause is recorded and it has a
 * corrective action, all of them verified. Major and critical non-conformances need an owner.
 * Corrective actions need a due date, move forward from planned to closed, and are verified only
 * with an effectiveness check. An internal audit is done once its findings are written, and a
 * finding can raise a non-conformance in one step.
 */
class QualityLogic extends AppLogic
{
    /**
     * Corrective action steps in order.
     */
    protected const CAPA_STEPS = ['planned', 'in_progress', 'verified', 'closed'];

    /**
     * Days to close a non-conformance, by severity.
     */
    protected const TARGET_DAYS = ['critical' => 7, 'major' => 30, 'minor' => 90];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'ncrs') {
            if (in_array($data['severity'] ?? null, ['major', 'critical'], true) && blank($payload['assignee_id'] ?? null)) {
                $errors['assignee_id'] = 'A '.$data['severity'].' non-conformance needs an owner.';
            }
            if ($status === 'closed' && $existing?->status !== 'closed') {
                if (blank($data['root_cause'] ?? null)) {
                    $errors['data.root_cause'] = 'Record the root cause before closing.';
                }
                if ($problem = $this->cannotClose($existing)) {
                    $errors['status'] = $problem;
                }
            }
            if ($existing?->status === 'closed' && $status !== 'closed') {
                $errors['status'] = 'This non-conformance is closed; raise a new one if the problem is back.';
            }

            return $errors;
        }

        if ($entity->key === 'capas') {
            if (blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'Give the action a due date.';
            }
            if ($existing && array_search($status, self::CAPA_STEPS, true) < array_search($existing->status, self::CAPA_STEPS, true)) {
                $errors['status'] = 'A corrective action cannot go back to '.str_replace('_', ' ', $status).'.';
            }
            if (in_array($status, ['verified', 'closed'], true) && blank($data['effectiveness'] ?? null)) {
                $errors['data.effectiveness'] = 'Record the effectiveness check before verifying.';
            }
            if ($status === 'closed' && ! in_array($existing?->status, ['verified', 'closed'], true)) {
                $errors['status'] = 'Verify the action before closing it.';
            }
            $ncr = filled($data['ncr'] ?? null) ? $this->records('ncrs')->find($data['ncr']) : null;
            if (! $existing && $ncr?->status === 'closed') {
                $errors['data.ncr'] = $ncr->title.' is closed.';
            }

            return $errors;
        }

        if (in_array($status, ['done', 'reported'], true) && blank($data['findings'] ?? null)) {
            $errors['data.findings'] = 'Write up the findings first; "No findings" is fine.';
        }
        if ($status === 'planned' && $existing && $existing->status !== 'planned') {
            $errors['status'] = 'This audit has been carried out.';
        }

        return $errors;
    }

    /**
     * Why the non-conformance can't close yet, if it can't.
     */
    protected function cannotClose(?Record $ncr): ?string
    {
        if (! $ncr) {
            return 'Add a corrective action before closing.';
        }
        $actions = $this->linked('capas', 'ncr', $ncr)->get();
        if ($actions->isEmpty()) {
            return 'Add a corrective action before closing.';
        }
        $open = $actions->whereNotIn('status', ['verified', 'closed'])->count();

        return $open ? $open.' corrective '.str('action')->plural($open).' not verified yet.' : null;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'ncrs' && $record->isDirty('status') && $record->status === 'closed') {
            $this->put($record, ['_closed_on' => today()->toDateString(), '_days_open' => (int) $record->occurs_on->copy()->startOfDay()->diffInDays(today())]);
        }
        if ($record->entity === 'capas' && $record->isDirty('status') && in_array($record->status, ['verified', 'closed'], true)) {
            $this->put($record, ['_'.$record->status.'_on' => today()->toDateString()]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'capas' && $record->wasRecentlyCreated && ($ncr = $this->parent($record, 'ncr')) && $ncr->status === 'open') {
            $ncr->update(['status' => 'investigating']);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->entity) {
            'ncrs' => match ($record->status) {
                'open' => ['investigate' => ['label' => 'Start investigation', 'icon' => 'search']],
                'investigating' => ['close' => ['label' => 'Close', 'icon' => 'check', 'fields' => [['name' => 'root_cause', 'label' => 'Root cause', 'type' => 'textarea', 'value' => $record->value('root_cause')]]]],
                default => [],
            },
            'capas' => match ($record->status) {
                'planned' => ['start' => ['label' => 'Start', 'icon' => 'play']],
                'in_progress' => ['verify' => ['label' => 'Verify', 'icon' => 'badge-check', 'fields' => [['name' => 'effectiveness', 'label' => 'Effectiveness check', 'type' => 'textarea']]]],
                'verified' => ['close' => ['label' => 'Close', 'icon' => 'check']],
                default => [],
            },
            default => $record->status !== 'planned' ? ['raise_ncr' => ['label' => 'Raise non-conformance', 'icon' => 'circle-alert', 'fields' => [
                ['name' => 'description', 'label' => 'Finding', 'type' => 'text'],
                ['name' => 'severity', 'label' => 'Severity', 'type' => 'select', 'options' => ['minor' => 'Minor', 'major' => 'Major', 'critical' => 'Critical'], 'value' => 'minor'],
            ]]] : [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'audits') {
            $values = $request->validate(['description' => ['required', 'string', 'max:255'], 'severity' => ['required', 'in:minor,major,critical']]);
            $ncr = Record::create([
                'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'ncrs',
                'title' => trim($values['description']), 'status' => 'open', 'occurs_on' => today(), 'assignee_id' => $record->assignee_id,
                'data' => ['source' => 'internal_audit', 'severity' => $values['severity'], '_audit' => $record->id],
            ]);

            return $ncr->number.' raised from '.$record->title.'.';
        }

        switch ($record->entity.'.'.$action) {
            case 'ncrs.investigate':
                $record->update(['status' => 'investigating']);

                return $record->title.' is under investigation.';
            case 'ncrs.close':
                $cause = trim($request->validate(['root_cause' => ['required', 'string', 'max:2000']])['root_cause']);
                if ($problem = $this->cannotClose($record)) {
                    throw ValidationException::withMessages(['status' => $problem]);
                }
                $record->update(['status' => 'closed', 'data' => [...$record->data, 'root_cause' => $cause]]);

                return $record->title.' closed after '.$record->value('_days_open').' '.str('day')->plural((int) $record->value('_days_open')).'.';
            case 'capas.start':
                $record->update(['status' => 'in_progress']);

                return $record->title.' started.';
            case 'capas.verify':
                $check = trim($request->validate(['effectiveness' => ['required', 'string', 'max:2000']])['effectiveness']);
                $record->update(['status' => 'verified', 'data' => [...$record->data, 'effectiveness' => $check]]);

                return $record->title.' verified.';
            default:
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'ncrs') {
            return [];
        }
        $actions = $this->linked('capas', 'ncr', $record)->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Corrective actions', 'icon' => 'wrench', 'empty' => 'No corrective actions yet.',
            'rows' => $actions->map(fn (Record $capa) => ['label' => $capa->title, 'sub' => ucfirst((string) $capa->value('type')).' · '.str_replace('_', ' ', $capa->status), 'value' => $capa->due_on?->format('d M') ?? '—', 'href' => $capa->url(), 'tone' => in_array($capa->status, ['verified', 'closed'], true) ? 'success' : ($capa->due_on?->lt(today()) ? 'danger' : null)])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('ncrs')->where('status', '!=', 'closed')->get();
        $overdue = $this->records('capas')->whereIn('status', ['planned', 'in_progress'])->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->orderBy('due_on')->get();
        $late = $open->filter(fn (Record $ncr) => $ncr->occurs_on && $ncr->occurs_on->copy()->addDays(self::TARGET_DAYS[$ncr->value('severity')] ?? 90)->lt(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Open non-conformances', 'icon' => 'circle-alert', 'stats' => [
                ['label' => 'Critical', 'value' => $open->where('data.severity', 'critical')->count(), 'tone' => $open->where('data.severity', 'critical')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Major', 'value' => $open->where('data.severity', 'major')->count(), 'tone' => $open->where('data.severity', 'major')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Minor', 'value' => $open->where('data.severity', 'minor')->count()],
                ['label' => 'Past target', 'value' => $late->count(), 'tone' => $late->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue corrective actions', 'icon' => 'alarm-clock', 'empty' => 'No actions overdue.',
                'rows' => $overdue->map(fn (Record $capa) => ['label' => $capa->title, 'sub' => str_replace('_', ' ', $capa->status), 'value' => 'Due '.$capa->due_on->format('d M'), 'href' => $capa->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $ncrs = $this->dated('ncrs', $from, $to)->get();
        $capas = $this->records('capas')->whereIn('status', ['verified', 'closed'])->get();

        $bySource = $ncrs->groupBy(fn (Record $ncr) => $ncr->value('source') ?: 'unknown')->sortKeys()
            ->map(function (Collection $group, string $source) {
                $closed = $group->where('status', 'closed');

                return [ucfirst(str_replace('_', ' ', $source)), $group->count(), $group->where('data.severity', 'critical')->count(), $group->where('data.severity', 'major')->count(), $closed->count(), $closed->isNotEmpty() ? round($closed->avg(fn (Record $ncr) => (int) $ncr->value('_days_open')), 1) : '—'];
            })->values()->all();

        $onTime = $capas->filter(fn (Record $capa) => $capa->due_on && $capa->value('_verified_on') && Carbon::parse($capa->value('_verified_on'))->lte($capa->due_on))->count();

        return [
            ['title' => 'Non-conformances by source', 'columns' => ['Source', 'Raised', 'Critical', 'Major', 'Closed', 'Average days to close'], 'rows' => $bySource],
            ['title' => 'Corrective actions', 'columns' => ['Measure', 'Value'], 'rows' => [
                ['Verified', $capas->count()],
                ['Verified by the due date', $onTime],
                ['On-time rate', $capas->isNotEmpty() ? round($onTime / $capas->count() * 100, 1).'%' : '—'],
                ['Still open', $this->records('capas')->whereIn('status', ['planned', 'in_progress'])->count()],
            ]],
        ];
    }
}
