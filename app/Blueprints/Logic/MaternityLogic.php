<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Maternity and antenatal care: a pregnancy's expected delivery date is worked out from the last
 * menstrual period (Naegele's rule, LMP + 280 days) and its gestation is kept current. Each
 * antenatal visit gets its gestation from the LMP; a blood pressure of 140/90 or more marks the
 * pregnancy high risk with a hypertension warning. Recording a delivery closes the antenatal record,
 * and births before 37 weeks or under 2.5 kg are flagged as preterm and low birth weight.
 */
class MaternityLogic extends AppLogic
{
    /**
     * Days from the last menstrual period to the expected delivery.
     */
    protected const TERM_DAYS = 280;

    /**
     * The WHO recommends at least eight antenatal contacts.
     */
    protected const CONTACTS = 8;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'pregnancies') {
            if ($payload['status'] === 'antenatal' && blank($data['lmp'] ?? null) && blank($payload['due_on'] ?? null)) {
                $errors['data.lmp'] = 'Enter the last menstrual period or the expected delivery date.';
            }
            if (filled($data['lmp'] ?? null) && Carbon::parse($data['lmp'])->isFuture()) {
                $errors['data.lmp'] = 'The last menstrual period cannot be in the future.';
            } elseif (filled($data['lmp'] ?? null) && $payload['status'] === 'antenatal' && Carbon::parse($data['lmp'])->lt(today()->subWeeks(45))) {
                $errors['data.lmp'] = 'That is more than 45 weeks ago; record the delivery instead.';
            }
            if (filled($data['gravida'] ?? null) && filled($data['para'] ?? null) && $data['para'] >= $data['gravida']) {
                $errors['data.para'] = 'Para counts earlier births, so it must be less than gravida.';
            }

            return $errors;
        }

        $pregnancy = filled($data['pregnancy'] ?? null) ? $this->records('pregnancies')->find($data['pregnancy']) : null;

        if ($entity->key === 'visits') {
            if ($pregnancy && $pregnancy->status !== 'antenatal' && ! $existing) {
                $errors['data.pregnancy'] = $pregnancy->title.'\'s pregnancy is '.$pregnancy->status.'.';
            }
            if (filled($data['blood_pressure'] ?? null) && ! $this->pressure($data['blood_pressure'])) {
                $errors['data.blood_pressure'] = 'Write the blood pressure as systolic/diastolic, such as 120/80.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
                $errors['occurs_on'] = 'A visit cannot be recorded before it happens.';
            }

            return $errors;
        }

        if ($pregnancy && $pregnancy->status === 'closed') {
            $errors['data.pregnancy'] = $pregnancy->title.'\'s record is closed.';
        }
        if (filled($data['birth_weight'] ?? null) && ($data['birth_weight'] < 0.3 || $data['birth_weight'] > 6.5)) {
            $errors['data.birth_weight'] = 'Enter the birth weight in kilograms, between 0.3 and 6.5.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
            $errors['occurs_on'] = 'A delivery cannot be in the future.';
        }

        return $errors;
    }

    /**
     * A "120/80" reading as [systolic, diastolic], or null when it is not one.
     *
     * @return array{0: int, 1: int}|null
     */
    protected function pressure(mixed $value): ?array
    {
        if (! preg_match('/^\s*(\d{2,3})\s*\/\s*(\d{2,3})\s*$/', (string) $value, $match)) {
            return null;
        }

        return [(int) $match[1], (int) $match[2]];
    }

    /**
     * Completed weeks of gestation on a date.
     */
    protected function weeksOn(Record $pregnancy, Carbon $date): ?int
    {
        $lmp = $pregnancy->value('lmp') ? Carbon::parse($pregnancy->value('lmp'))
            : ($pregnancy->due_on ? $pregnancy->due_on->copy()->subDays(self::TERM_DAYS) : null);

        return $lmp ? intdiv((int) $lmp->diffInDays($date, false), 7) : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'pregnancies') {
            if (filled($record->value('lmp'))) {
                $record->due_on = Carbon::parse($record->value('lmp'))->addDays(self::TERM_DAYS);
            }
            $visits = $record->exists ? $this->linked('visits', 'pregnancy', $record)->where('status', 'done')->get() : collect();
            $raised = $visits->first(fn (Record $visit) => $visit->value('_hypertension'));
            $this->put($record, [
                '_weeks' => $record->status === 'antenatal' ? $this->weeksOn($record, today()) : $record->value('_weeks'),
                '_visits' => $visits->count(),
                '_hypertension' => (bool) $raised,
            ]);
            if ($raised) {
                $this->put($record, ['risk' => 'high']);
            }

            return;
        }

        $pregnancy = $this->parent($record, 'pregnancy');
        if ($record->entity === 'visits') {
            if ($pregnancy && blank($record->value('weeks')) && $record->occurs_on) {
                $this->put($record, ['weeks' => $this->weeksOn($pregnancy, $record->occurs_on)]);
            }
            $bp = $this->pressure($record->value('blood_pressure'));
            $this->put($record, ['_hypertension' => $bp !== null && ($bp[0] >= 140 || $bp[1] >= 90)]);

            return;
        }

        $weeks = $pregnancy && $record->occurs_on ? $this->weeksOn($pregnancy, $record->occurs_on) : null;
        $this->put($record, [
            '_weeks' => $weeks,
            '_preterm' => $weeks !== null && $weeks < 37,
            '_low_birth_weight' => filled($record->value('birth_weight')) && (float) $record->value('birth_weight') < 2.5,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'visits') {
            $this->recalculate($this->parent($record, 'pregnancy'));
        }
        if ($record->entity === 'deliveries' && ($pregnancy = $this->parent($record, 'pregnancy')) && $pregnancy->status === 'antenatal') {
            $pregnancy->update(['status' => 'delivered', 'data' => [...$pregnancy->data, '_weeks' => $record->value('_weeks'), '_delivered_on' => $record->occurs_on?->toDateString()]]);
        }
    }

    public function actions(Record $record): array
    {
        return $record->entity === 'pregnancies' && $record->status === 'antenatal' ? [
            'visit' => ['label' => 'Record visit', 'icon' => 'stethoscope', 'fields' => [
                ['name' => 'blood_pressure', 'label' => 'Blood pressure', 'type' => 'text'],
                ['name' => 'weight', 'label' => 'Weight (kg)', 'type' => 'number'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
            ]],
        ] : ($record->entity === 'pregnancies' && $record->status === 'delivered' ? ['close' => ['label' => 'Close postnatal care', 'icon' => 'archive']] : []);
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'close') {
            $record->update(['status' => 'closed']);

            return $record->title.'\'s maternity record closed.';
        }

        $input = $request->validate([
            'blood_pressure' => ['required', 'string', fn ($attribute, $value, $fail) => $this->pressure($value) ? null : $fail('Write the blood pressure as systolic/diastolic, such as 120/80.')],
            'weight' => ['nullable', 'numeric', 'min:30', 'max:250'],
            'notes' => ['nullable', 'string'],
        ]);
        $visit = Record::create([
            'workspace_id' => $record->workspace_id,
            'blueprint' => $record->blueprint,
            'entity' => 'visits',
            'title' => 'Visit '.((int) $record->value('_visits') + 1),
            'status' => 'done',
            'occurs_on' => today(),
            'assignee_id' => $request->user()?->id,
            'data' => ['pregnancy' => $record->id, 'blood_pressure' => trim($input['blood_pressure']), 'weight' => $input['weight'] ?? null, 'notes' => $input['notes'] ?? null],
        ]);

        return 'Visit at '.$visit->value('weeks').' weeks recorded for '.$record->title
            .($visit->value('_hypertension') ? ' — blood pressure '.$visit->value('blood_pressure').' is high; she is now high risk.' : '.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'pregnancies') {
            return [];
        }
        $visits = $this->linked('visits', 'pregnancy', $record)->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $delivery = $this->linked('deliveries', 'pregnancy', $record)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pregnancy', 'icon' => 'heart-pulse', 'stats' => [
                ['label' => 'Gestation', 'value' => $record->value('_weeks') === null ? '—' : $record->value('_weeks').' weeks'],
                ['label' => 'Expected delivery', 'value' => $record->due_on?->format('d M Y') ?? '—'],
                ['label' => 'Risk', 'value' => ucfirst((string) ($record->value('risk') ?: 'low')).($record->value('_hypertension') ? ' · hypertension' : ''), 'tone' => $record->value('risk') === 'high' ? 'danger' : null],
                ['label' => 'Antenatal contacts', 'value' => (int) $record->value('_visits').' of '.self::CONTACTS],
                ['label' => 'Births', 'value' => $delivery->isEmpty() ? '—' : (string) $delivery->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Antenatal visits', 'icon' => 'stethoscope', 'empty' => 'No visits yet.',
                'rows' => $visits->map(fn (Record $visit) => [
                    'label' => $visit->occurs_on?->format('d M Y').' · '.($visit->value('weeks') ?? '?').' wks', 'sub' => trim(($visit->value('weight') ? $visit->value('weight').' kg' : '').' '.$visit->value('notes')),
                    'value' => (string) ($visit->value('blood_pressure') ?? '—'), 'href' => $visit->url(), 'tone' => $visit->value('_hypertension') ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $pregnancies = $this->records('pregnancies')->where('status', 'antenatal')->orderBy('due_on')->get();
        $due = $pregnancies->filter(fn (Record $pregnancy) => $pregnancy->due_on?->lte(today()->addWeeks(2)));
        $overdue = $pregnancies->filter(fn (Record $pregnancy) => $pregnancy->due_on?->lt(today()->subWeek()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Antenatal care', 'icon' => 'baby', 'stats' => [
                ['label' => 'Pregnancies', 'value' => (string) $pregnancies->count()],
                ['label' => 'High risk', 'value' => (string) $pregnancies->filter(fn (Record $pregnancy) => $pregnancy->value('risk') === 'high')->count(), 'tone' => $pregnancies->contains(fn (Record $pregnancy) => $pregnancy->value('risk') === 'high') ? 'danger' : null],
                ['label' => 'Due in two weeks', 'value' => (string) $due->count()],
                ['label' => 'Past due date', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'warning' : null],
                ['label' => 'Deliveries this month', 'value' => (string) $this->records('deliveries')->whereBetween('occurs_on', [today()->startOfMonth(), today()->endOfMonth()])->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due soon', 'icon' => 'calendar-heart', 'empty' => 'Nobody is due in the next two weeks.',
                'rows' => $due->map(fn (Record $pregnancy) => [
                    'label' => $pregnancy->title, 'sub' => $pregnancy->value('_weeks').' weeks'.($pregnancy->value('risk') === 'high' ? ' · high risk' : ''), 'value' => $pregnancy->due_on->format('d M'), 'href' => $pregnancy->url(), 'tone' => $pregnancy->value('risk') === 'high' ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deliveries = $this->dated('deliveries', $from, $to)->get();
        $byMode = $deliveries->groupBy(fn (Record $delivery) => ucfirst((string) ($delivery->value('mode') ?: 'normal')))->sortKeys()
            ->map(fn (Collection $group, string $mode) => [$mode, $group->count(), $deliveries->isEmpty() ? '—' : (int) round($group->count() / $deliveries->count() * 100).'%'])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($deliveries) {
            $group = $deliveries->filter(fn (Record $delivery) => $delivery->occurs_on?->format('Y-m') === $month);

            return [$label, $group->where('status', 'live_birth')->count(), $group->where('status', 'stillbirth')->count(), $group->filter(fn (Record $delivery) => $delivery->value('_preterm'))->count(), $group->filter(fn (Record $delivery) => $delivery->value('_low_birth_weight'))->count()];
        })->values()->all();

        $visits = $this->dated('visits', $from, $to)->get();
        $contacts = collect($this->months($from, $to))->map(function (string $label, string $month) use ($visits) {
            $group = $visits->filter(fn (Record $visit) => $visit->occurs_on?->format('Y-m') === $month);

            return [$label, $group->where('status', 'done')->count(), $group->where('status', 'missed')->count(), $group->filter(fn (Record $visit) => $visit->value('_hypertension'))->count()];
        })->values()->all();

        return [
            ['title' => 'Deliveries by mode', 'columns' => ['Mode', 'Deliveries', 'Share'], 'rows' => $byMode],
            ['title' => 'Birth outcomes by month', 'columns' => ['Month', 'Live births', 'Stillbirths', 'Preterm', 'Low birth weight'], 'rows' => $byMonth],
            ['title' => 'Antenatal visits by month', 'columns' => ['Month', 'Visits', 'Missed', 'High blood pressure'], 'rows' => $contacts],
        ];
    }
}
