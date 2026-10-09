<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Training centres: trainees enrol only on open or running intakes with a free seat, pay no more
 * than the fee, and are declared competent or not yet competent only with an assessment result.
 * An intake starts running on its start date, completes only once every trainee is assessed or
 * dropped, and shows its seats, fees outstanding, attendance and pass rate.
 */
class TrainingCentresLogic extends AppLogic
{
    public const ON_COURSE = ['enrolled', 'completed'];

    public const ASSESSED = ['competent', 'not_yet_competent'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'intakes') {
            $seated = $existing ? $this->linked('trainees', 'intake', $existing)->where('status', '!=', 'dropped')->count() : 0;
            if (filled($data['capacity'] ?? null) && (int) $data['capacity'] < 0) {
                $errors['data.capacity'] = 'Capacity cannot be negative.';
            } elseif (filled($data['capacity'] ?? null) && (int) $data['capacity'] > 0 && $seated > (int) $data['capacity']) {
                $errors['data.capacity'] = $seated.' trainees already hold seats on this intake.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The fee cannot be negative.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The end date comes before the start date.';
            }
            if ($payload['status'] === 'completed' && $existing && $existing->status !== 'completed' && ($enrolled = $this->linked('trainees', 'intake', $existing)->where('status', 'enrolled')->count()) > 0) {
                $errors['status'] = $enrolled.' trainees are still enrolled; assess or drop them first.';
            }

            return $errors;
        }

        $intake = ! empty($data['intake']) ? $this->records('intakes')->find($data['intake']) : null;
        $joining = $intake && (! $existing || (int) $existing->value('intake') !== $intake->id);
        if ($joining && $intake->status === 'completed') {
            $errors['data.intake'] = $intake->title.' has already completed.';
        } elseif ($joining && (int) $intake->value('capacity') > 0 && $this->linked('trainees', 'intake', $intake)->where('status', '!=', 'dropped')->count() >= (int) $intake->value('capacity')) {
            $errors['data.intake'] = $intake->title.' is full ('.(int) $intake->value('capacity').' seats).';
        }
        $idNumber = strtoupper(trim((string) ($data['id_number'] ?? '')));
        if ($intake && $idNumber !== '' && $this->linked('trainees', 'intake', $intake)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $trainee) => strtoupper(trim((string) $trainee->value('id_number'))) === $idNumber)) {
            $errors['data.id_number'] = 'ID number '.$idNumber.' is already enrolled on '.$intake->title.'.';
        }
        if (filled($data['attendance'] ?? null) && ((float) $data['attendance'] < 0 || (float) $data['attendance'] > 100)) {
            $errors['data.attendance'] = 'Attendance is a percentage from 0 to 100.';
        }
        $paid = (float) ($payload['amount'] ?? 0);
        if ($paid < 0) {
            $errors['amount'] = 'The amount paid cannot be negative.';
        } elseif ($intake && (float) $intake->amount > 0 && $paid > (float) $intake->amount) {
            $errors['amount'] = 'Paid cannot exceed the fee of '.$this->money($intake->amount).'.';
        }
        if (in_array($payload['status'], self::ASSESSED, true) && blank($data['assessment_result'] ?? null)) {
            $errors['data.assessment_result'] = 'Record the assessment result before declaring the outcome.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'trainees') {
            $intake = $this->parent($record, 'intake');
            $fee = (float) ($intake?->amount ?? 0);
            $this->put($record, [
                'id_number' => strtoupper(trim((string) $record->value('id_number'))),
                '_fee' => $fee,
                '_balance' => round(max(0, $fee - (float) $record->amount), 2),
                '_assessed_on' => in_array($record->status, self::ASSESSED, true) ? ($record->value('_assessed_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $trainees = $record->exists ? $this->linked('trainees', 'intake', $record)->get() : collect();
        $seated = $trainees->where('status', '!=', 'dropped');
        $assessed = $trainees->whereIn('status', self::ASSESSED);
        $withAttendance = $seated->filter(fn (Record $trainee) => filled($trainee->value('attendance')));
        $fee = (float) $record->amount;
        $this->put($record, [
            '_seated' => $seated->count(),
            '_enrolled' => $trainees->where('status', 'enrolled')->count(),
            '_competent' => $trainees->where('status', 'competent')->count(),
            '_not_yet_competent' => $trainees->where('status', 'not_yet_competent')->count(),
            '_dropped' => $trainees->where('status', 'dropped')->count(),
            '_free' => (int) $record->value('capacity') > 0 ? max(0, (int) $record->value('capacity') - $seated->count()) : null,
            '_collected' => round($seated->sum('amount'), 2),
            '_outstanding' => round($seated->sum(fn (Record $trainee) => max(0, $fee - (float) $trainee->amount)), 2),
            '_average_attendance' => $withAttendance->isEmpty() ? null : round($withAttendance->avg(fn (Record $trainee) => (float) $trainee->value('attendance'))),
            '_pass_rate' => $assessed->isEmpty() ? null : round($trainees->where('status', 'competent')->count() / $assessed->count() * 100),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'trainees') {
            $this->recalculate($this->parent($record, 'intake'));
            $this->recalculate($this->previousParent($record, 'intake'));
        } else {
            foreach ($this->linked('trainees', 'intake', $record)->get() as $trainee) {
                if ((float) $trainee->value('_fee') !== (float) $record->amount) {
                    $this->recalculate($trainee);
                }
            }
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'trainees') {
            $this->recalculate($this->parent($record, 'intake'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('intakes')->where('status', 'open')->whereNotNull('occurs_on')->whereDate('occurs_on', '<=', today())->update(['status' => 'running']);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'intakes') {
            return match ($record->status) {
                'open' => ['start' => ['label' => 'Start', 'icon' => 'play']],
                'running' => ['complete' => ['label' => 'Complete', 'icon' => 'check-circle', 'confirm' => 'Complete this intake? Every trainee must be assessed or dropped.']],
                default => [],
            };
        }

        $assess = ['label' => 'Assess', 'icon' => 'clipboard-check', 'fields' => [
            ['name' => 'result', 'label' => 'Assessment result', 'type' => 'text', 'value' => $record->value('assessment_result')],
            ['name' => 'outcome', 'label' => 'Outcome', 'type' => 'select', 'options' => ['competent' => 'Competent', 'not_yet_competent' => 'Not yet competent'], 'value' => 'competent'],
        ]];
        $pay = ['label' => 'Record payment', 'icon' => 'banknote', 'fields' => [
            ['name' => 'amount', 'label' => 'Amount received', 'type' => 'number', 'value' => $this->number($record, '_balance') ?: ''],
        ]];

        return match ($record->status) {
            'enrolled' => ['record_payment' => $pay, 'assess' => $assess, 'drop' => ['label' => 'Drop', 'icon' => 'user-x', 'confirm' => 'Drop '.$record->title.' from the intake?']],
            'completed' => ['record_payment' => $pay, 'assess' => $assess],
            'not_yet_competent' => ['record_payment' => $pay, 'reassess' => $assess + ['label' => 'Reassess']],
            'competent' => $this->number($record, '_balance') > 0 ? ['record_payment' => $pay] : [],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $record->update(['status' => 'running', 'occurs_on' => $record->occurs_on ?? today()]);

                return $record->title.' is running.';
            case 'complete':
                $enrolled = $this->linked('trainees', 'intake', $record)->where('status', 'enrolled')->count();
                if ($enrolled > 0) {
                    throw ValidationException::withMessages(['status' => $enrolled.' trainees are still enrolled; assess or drop them first.']);
                }
                $record->update(['status' => 'completed', 'due_on' => $record->due_on ?? today()]);

                return $record->title.' completed with '.(int) $record->fresh()->value('_competent').' trainees competent.';
            case 'drop':
                $record->update(['status' => 'dropped']);

                return $record->title.' dropped out.';
            case 'record_payment':
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount'];
                $balance = $this->number($record, '_balance');
                if ((float) $record->value('_fee') > 0 && $amount > $balance) {
                    throw ValidationException::withMessages(['amount' => 'Only '.$this->money($balance).' is outstanding.']);
                }
                $record->update(['amount' => (float) $record->amount + $amount]);

                return 'Received '.$this->money($amount).'; '.($this->number($record->fresh(), '_balance') > 0 ? $this->money($this->number($record->fresh(), '_balance')).' still outstanding.' : 'fees paid in full.');
        }

        $input = $request->validate(['result' => ['required', 'string'], 'outcome' => ['required', 'in:competent,not_yet_competent']]);
        $record->update(['status' => $input['outcome'], 'data' => [...$record->data, 'assessment_result' => $input['result'], '_assessed_on' => today()->toDateString()]]);

        return $record->title.' assessed '.($input['outcome'] === 'competent' ? 'competent' : 'not yet competent').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'intakes') {
            return [];
        }

        $trainees = $this->linked('trainees', 'intake', $record)->orderBy('title')->get();
        $statuses = $this->app->entities['trainees']->field('status')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Intake', 'icon' => 'presentation', 'stats' => [
                ['label' => 'Seats taken', 'value' => (int) $record->value('_seated').((int) $record->value('capacity') > 0 ? ' of '.(int) $record->value('capacity') : ''), 'tone' => $record->value('_free') === 0 ? 'warning' : null],
                ['label' => 'Enrolled', 'value' => (string) (int) $record->value('_enrolled')],
                ['label' => 'Competent', 'value' => (string) (int) $record->value('_competent'), 'tone' => (int) $record->value('_competent') > 0 ? 'success' : null],
                ['label' => 'Not yet competent', 'value' => (string) (int) $record->value('_not_yet_competent'), 'tone' => (int) $record->value('_not_yet_competent') > 0 ? 'warning' : null],
                ['label' => 'Pass rate', 'value' => $record->value('_pass_rate') === null ? '—' : (int) $record->value('_pass_rate').'%'],
                ['label' => 'Attendance', 'value' => $record->value('_average_attendance') === null ? '—' : (int) $record->value('_average_attendance').'%'],
                ['label' => 'Fees collected', 'value' => $this->money($this->number($record, '_collected'))],
                ['label' => 'Outstanding', 'value' => $this->money($this->number($record, '_outstanding')), 'tone' => $this->number($record, '_outstanding') > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Trainees', 'icon' => 'hard-hat', 'empty' => 'Nobody enrolled yet.',
                'rows' => $trainees->take(15)->map(fn (Record $trainee) => [
                    'label' => $trainee->title, 'sub' => ($trainee->value('id_number') ?: 'No ID').(filled($trainee->value('attendance')) ? ' · '.(int) $trainee->value('attendance').'% attendance' : ''),
                    'value' => $this->number($trainee, '_balance') > 0 ? $this->money($this->number($trainee, '_balance')).' owed' : ($statuses[$trainee->status] ?? ucfirst($trainee->status)), 'href' => $trainee->url(),
                    'tone' => match ($trainee->status) {
                        'competent' => 'success', 'not_yet_competent', 'dropped' => 'warning', default => $this->number($trainee, '_balance') > 0 ? 'warning' : null
                    },
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $intakes = $this->records('intakes')->get();
        $trainees = $this->records('trainees')->get();
        $owing = $trainees->whereIn('status', [...self::ON_COURSE, ...self::ASSESSED])->filter(fn (Record $trainee) => $this->number($trainee, '_balance') > 0)->sortByDesc(fn (Record $trainee) => $this->number($trainee, '_balance'));
        $assessed = $trainees->whereIn('status', self::ASSESSED);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Training centre', 'icon' => 'wrench', 'stats' => [
                ['label' => 'Open intakes', 'value' => (string) $intakes->where('status', 'open')->count()],
                ['label' => 'Running', 'value' => (string) $intakes->where('status', 'running')->count()],
                ['label' => 'Trainees enrolled', 'value' => (string) $trainees->where('status', 'enrolled')->count()],
                ['label' => 'Pass rate', 'value' => $assessed->isEmpty() ? '—' : round($trainees->where('status', 'competent')->count() / $assessed->count() * 100).'%'],
                ['label' => 'Fees outstanding', 'value' => $this->money($owing->sum(fn (Record $trainee) => $this->number($trainee, '_balance'))), 'tone' => $owing->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Fees outstanding', 'icon' => 'banknote', 'empty' => 'Every trainee has paid in full.',
                'rows' => $owing->take(10)->map(fn (Record $trainee) => [
                    'label' => $trainee->title, 'sub' => $intakes->firstWhere('id', (int) $trainee->value('intake'))?->title, 'value' => $this->money($this->number($trainee, '_balance')), 'href' => $trainee->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $intakes = $this->dated('intakes', $from, $to)->get();
        $byTrade = $intakes->groupBy(fn (Record $intake) => $intake->value('trade') ?: 'No trade')->sortKeys()->map(fn ($group, $trade) => [
            $trade, $group->count(), $group->sum(fn (Record $intake) => (int) $intake->value('_seated')), $group->sum(fn (Record $intake) => (int) $intake->value('_competent')), $group->sum(fn (Record $intake) => (int) $intake->value('_not_yet_competent')), $group->sum(fn (Record $intake) => (int) $intake->value('_dropped')), $this->money($group->sum(fn (Record $intake) => (float) $intake->value('_collected'))), $this->money($group->sum(fn (Record $intake) => (float) $intake->value('_outstanding'))),
        ])->values()->all();

        $statuses = $this->app->entities['trainees']->field('status')?->options ?? [];
        $intakeRows = $intakes->sortBy('occurs_on')->map(fn (Record $intake) => [
            $intake->title, $intake->value('trade') ?: '—', $intake->occurs_on?->format('d M Y') ?? '—', ucfirst($intake->status), (int) $intake->value('_seated'), $intake->value('_pass_rate') === null ? '—' : (int) $intake->value('_pass_rate').'%', $intake->value('_average_attendance') === null ? '—' : (int) $intake->value('_average_attendance').'%', $this->money((float) $intake->value('_outstanding')),
        ])->values()->all();

        $trainees = $this->records('trainees')->get();
        $byOutcome = collect($statuses)->map(fn (string $label, string $status) => [$label, $trainees->where('status', $status)->count(), $this->money($trainees->where('status', $status)->sum('amount')), $this->money($trainees->where('status', $status)->sum(fn (Record $trainee) => (float) $trainee->value('_balance')))])->values()->all();

        return [
            ['title' => 'Intakes by trade', 'columns' => ['Trade', 'Intakes', 'Trainees', 'Competent', 'Not yet competent', 'Dropped', 'Collected', 'Outstanding'], 'rows' => $byTrade],
            ['title' => 'Intakes', 'columns' => ['Intake', 'Trade', 'Start', 'Status', 'Trainees', 'Pass rate', 'Attendance', 'Outstanding'], 'rows' => $intakeRows],
            ['title' => 'Trainees by outcome', 'columns' => ['Outcome', 'Trainees', 'Paid', 'Outstanding'], 'rows' => $byOutcome],
        ];
    }
}
