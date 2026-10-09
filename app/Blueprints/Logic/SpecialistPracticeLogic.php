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
 * Dental, optometry and physio: a treatment plan is proposed, accepted by the patient and then
 * worked through procedure by procedure. Each plan keeps its sessions done, fees charged and
 * whether the work has run over the estimate. Dental teeth are checked against FDI numbering,
 * procedures are only booked on accepted plans, and the plan moves to in progress by itself.
 */
class SpecialistPracticeLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'plans') {
            if ($payload['status'] === 'completed' && $existing && $this->linked('procedures', 'plan', $existing)->where('status', 'scheduled')->exists()) {
                $errors['status'] = 'Finish or cancel the scheduled procedures first.';
            }
            if (filled($data['sessions'] ?? null) && (int) $data['sessions'] < 1) {
                $errors['data.sessions'] = 'Plan at least one session.';
            }

            return $errors;
        }

        $plan = filled($data['plan'] ?? null) ? $this->records('plans')->find($data['plan']) : null;
        if ($plan && in_array($plan->status, ['proposed', 'completed'], true) && $payload['status'] !== 'cancelled' && (! $existing || (int) $existing->value('plan') !== $plan->id)) {
            $errors['data.plan'] = $plan->status === 'proposed' ? 'The patient has not accepted '.$plan->title.'\'s plan yet.' : $plan->title.'\'s plan is completed.';
        }
        if ($plan && $plan->value('speciality') === 'dental' && filled($data['tooth_or_site'] ?? null)) {
            $bad = $this->badTeeth((string) $data['tooth_or_site']);
            if ($bad) {
                $errors['data.tooth_or_site'] = 'Use FDI tooth numbers such as 11 or 36; '.implode(', ', $bad).' is not a tooth.';
            }
        }

        return $errors;
    }

    /**
     * Tooth numbers in a list that are not FDI numbers (permanent 11–48, primary 51–85).
     *
     * @return list<string>
     */
    protected function badTeeth(string $teeth): array
    {
        return collect(preg_split('/[\s,;\/]+/', trim($teeth)))->filter()->reject(function (string $tooth) {
            if (! preg_match('/^([1-8])([1-8])$/', $tooth, $match)) {
                return false;
            }

            return (int) $match[1] <= 4 || (int) $match[2] <= 5;
        })->values()->all();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'procedures' && $record->value('tooth_or_site') !== null && $this->parent($record, 'plan')?->value('speciality') === 'dental') {
            $this->put($record, ['tooth_or_site' => collect(preg_split('/[\s,;\/]+/', trim((string) $record->value('tooth_or_site'))))->filter()->implode(', ')]);
        }
        if ($record->entity === 'plans' && $record->exists) {
            $this->put($record, $this->rollup($record));
        }
    }

    /**
     * A plan's progress worked out from its procedures.
     *
     * @return array<string, mixed>
     */
    protected function rollup(Record $plan): array
    {
        $procedures = $this->linked('procedures', 'plan', $plan)->get();
        $done = $procedures->where('status', 'done');
        $charged = (float) $done->sum('amount');
        $estimate = (float) $plan->amount;

        return [
            '_done' => $done->count(),
            '_scheduled' => $procedures->where('status', 'scheduled')->count(),
            '_charged' => $charged,
            '_over_estimate' => $estimate > 0 && $charged > $estimate,
            '_progress' => (int) $plan->value('sessions') > 0 ? min(100, (int) round($done->count() / (int) $plan->value('sessions') * 100)) : null,
        ];
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'procedures') {
            $this->refresh($this->parent($record, 'plan'), $record->status === 'done');
            $this->refresh($this->previousParent($record, 'plan'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'procedures') {
            $this->refresh($this->parent($record, 'plan'));
        }
    }

    /**
     * Refresh a plan's progress and start it once work is done on it.
     */
    protected function refresh(?Record $plan, bool $workDone = false): void
    {
        if (! $plan) {
            return;
        }
        $data = [...$plan->data, ...$this->rollup($plan)];
        $status = $workDone && $plan->status === 'accepted' ? 'in_progress' : $plan->status;
        if ($data !== $plan->data || $status !== $plan->status) {
            $plan->data = $data;
            $plan->status = $status;
            $plan->saveQuietly();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'plans') {
            return match ($record->status) {
                'proposed' => ['accept' => ['label' => 'Patient accepted', 'icon' => 'check']],
                'accepted', 'in_progress' => ['complete' => ['label' => 'Complete plan', 'icon' => 'check-check']],
                default => [],
            };
        }

        return $record->status === 'scheduled' ? [
            'done' => ['label' => 'Done', 'icon' => 'check'],
            'cancel' => ['label' => 'Cancel', 'icon' => 'x'],
        ] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'plans') {
            if ($action === 'accept') {
                $record->update(['status' => 'accepted', 'data' => [...$record->data, '_accepted_on' => today()->toDateString()]]);

                return $record->title.' accepted the plan'.($record->amount ? ' at an estimate of '.$this->money($record->amount) : '').'.';
            }
            if ($this->linked('procedures', 'plan', $record)->where('status', 'scheduled')->exists()) {
                throw ValidationException::withMessages(['status' => 'Finish or cancel the scheduled procedures first.']);
            }
            $record->update(['status' => 'completed']);

            return $record->title.'\'s plan completed.';
        }

        $record->update(['status' => $action === 'done' ? 'done' : 'cancelled']);

        return $record->title.($action === 'done' ? ' done.' : ' cancelled.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'plans') {
            return [];
        }
        $procedures = $this->linked('procedures', 'plan', $record)->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'list-checks', 'stats' => [
                ['label' => 'Sessions', 'value' => $record->value('_done').' of '.($record->value('sessions') ?: '—')],
                ['label' => 'Scheduled', 'value' => (string) (int) $record->value('_scheduled')],
                ['label' => 'Charged', 'value' => $this->money($record->value('_charged') ?? 0)],
                ['label' => 'Estimate', 'value' => $record->amount ? $this->money($record->amount) : '—', 'tone' => $record->value('_over_estimate') ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Procedures', 'icon' => 'stethoscope', 'empty' => 'No procedures yet.',
                'rows' => $procedures->map(fn (Record $procedure) => [
                    'label' => $procedure->title, 'sub' => trim(($procedure->occurs_on?->format('d M Y') ?? '').' '.$procedure->value('tooth_or_site')), 'value' => ucfirst($procedure->status), 'href' => $procedure->url(), 'tone' => $procedure->status === 'cancelled' ? 'muted' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $plans = $this->records('plans')->get();
        $today = $this->records('procedures')->whereDate('occurs_on', today())->where('status', 'scheduled')->orderBy('id')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Treatment plans', 'icon' => 'smile', 'stats' => [
                ['label' => 'Awaiting acceptance', 'value' => (string) $plans->where('status', 'proposed')->count()],
                ['label' => 'In progress', 'value' => (string) $plans->whereIn('status', ['accepted', 'in_progress'])->count()],
                ['label' => 'Over estimate', 'value' => (string) $plans->filter(fn (Record $plan) => $plan->value('_over_estimate'))->count()],
                ['label' => 'Proposed value', 'value' => $this->money($plans->where('status', 'proposed')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Procedures today', 'icon' => 'calendar-check', 'empty' => 'Nothing scheduled today.',
                'rows' => $today->map(fn (Record $procedure) => [
                    'label' => $procedure->title, 'sub' => (string) $this->parent($procedure, 'plan')?->title, 'value' => (string) $procedure->value('tooth_or_site'), 'href' => $procedure->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $plans = $this->dated('plans', $from, $to)->get();
        $bySpeciality = $plans->groupBy(fn (Record $plan) => (string) $plan->value('speciality'))->sortKeys()->map(function (Collection $group, string $speciality) {
            $decided = $group->where('status', '!=', 'proposed');

            return [ucfirst($speciality), $group->count(), $group->count() > 0 ? (int) round($decided->count() / $group->count() * 100).'%' : '—', $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $plan) => (float) $plan->value('_charged')))];
        })->values()->all();

        $procedures = $this->dated('procedures', $from, $to)->where('status', 'done')->get();
        $byCode = $procedures->groupBy(fn (Record $procedure) => filled($procedure->value('code')) ? (string) $procedure->value('code') : $procedure->title)->sortKeys()
            ->map(fn (Collection $group, string $code) => [$code, $group->count(), $this->money($group->sum('amount'))])->values()->all();

        $over = $plans->filter(fn (Record $plan) => $plan->value('_over_estimate'))
            ->map(fn (Record $plan) => [$plan->title, ucfirst((string) $plan->value('speciality')), $this->money($plan->amount), $this->money($plan->value('_charged')), $this->money((float) $plan->value('_charged') - (float) $plan->amount)])->values()->all();

        return [
            ['title' => 'Plans by speciality', 'columns' => ['Speciality', 'Plans', 'Accepted', 'Estimated', 'Charged'], 'rows' => $bySpeciality],
            ['title' => 'Procedures done', 'columns' => ['Procedure', 'Done', 'Fees'], 'rows' => $byCode],
            ['title' => 'Plans over estimate', 'columns' => ['Patient', 'Speciality', 'Estimate', 'Charged', 'Over by'], 'rows' => $over],
        ];
    }
}
