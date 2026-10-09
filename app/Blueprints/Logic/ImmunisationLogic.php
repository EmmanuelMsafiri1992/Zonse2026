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
 * Child immunisation register and community health workers: each child is checked against the
 * routine infant schedule (BCG at birth, OPV, pentavalent and PCV at 6, 10 and 14 weeks, rotavirus
 * at 6 and 10 weeks, measles-rubella at 9 and 15 months). Doses must be on the schedule, not
 * given early or twice, and carry a batch number. The child's next dose date and status follow
 * from what is outstanding: due once a dose falls due, and a defaulter four weeks after that.
 * The daily job keeps every child's status current, and community health worker visits that
 * end in a referral must say where the household was referred.
 */
class ImmunisationLogic extends AppLogic
{
    /**
     * The routine schedule: vaccine => age in days for each dose.
     *
     * @var array<string, list<int>>
     */
    public const SCHEDULE = [
        'BCG' => [0],
        'OPV' => [42, 70, 98],
        'Pentavalent' => [42, 70, 98],
        'PCV' => [42, 70, 98],
        'Rotavirus' => [42, 70],
        'Measles-Rubella' => [273, 456],
    ];

    /**
     * Days past the due date after which a child counts as a defaulter.
     */
    protected const DEFAULTER_DAYS = 28;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'children') {
            if (filled($data['date_of_birth'] ?? null) && Carbon::parse($data['date_of_birth'])->isFuture()) {
                $errors['data.date_of_birth'] = 'The date of birth cannot be in the future.';
            } elseif (filled($data['date_of_birth'] ?? null) && Carbon::parse($data['date_of_birth'])->lt(today()->subYears(5))) {
                $errors['data.date_of_birth'] = 'The register covers children under five.';
            }

            return $errors;
        }

        if ($entity->key === 'chw_visits') {
            if ($payload['status'] === 'referred' && blank($data['referral'] ?? null)) {
                $errors['data.referral'] = 'Say where the household was referred.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
                $errors['occurs_on'] = 'A visit cannot be recorded before it happens.';
            }

            return $errors;
        }

        $vaccine = $this->vaccine($payload['title'] ?? null);
        $number = (int) ($data['dose_number'] ?? 1) ?: 1;
        if (! $vaccine) {
            $errors['title'] = 'Choose a vaccine on the schedule: '.implode(', ', array_keys(self::SCHEDULE)).'.';
        } elseif ($number < 1 || $number > count(self::SCHEDULE[$vaccine])) {
            $errors['data.dose_number'] = $vaccine.' has '.count(self::SCHEDULE[$vaccine]).' dose(s).';
        }
        if ($payload['status'] !== 'given') {
            return $errors;
        }
        if (blank($data['batch'] ?? null)) {
            $errors['data.batch'] = 'Record the vaccine batch number.';
        }
        $given = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        if ($given->isFuture()) {
            $errors['occurs_on'] = 'A dose cannot be recorded before it is given.';
        }
        $child = filled($data['child'] ?? null) ? $this->records('children')->find($data['child']) : null;
        if ($child && $vaccine && ! isset($errors['data.dose_number'])) {
            $earliest = Carbon::parse($child->value('date_of_birth'))->addDays(self::SCHEDULE[$vaccine][$number - 1]);
            if ($given->lt($earliest) && ! isset($errors['occurs_on'])) {
                $errors['occurs_on'] = $vaccine.' '.$number.' is given from '.$this->age(self::SCHEDULE[$vaccine][$number - 1]).'; '.$child->title.' is not old enough until '.$earliest->format('d M Y').'.';
            }
            $twice = $this->linked('doses', 'child', $child)->where('status', 'given')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $dose) => $this->vaccine($dose->title) === $vaccine && (int) ($dose->value('dose_number') ?: 1) === $number);
            if ($twice) {
                $errors['data.dose_number'] = $child->title.' already had '.$vaccine.' '.$number.' on '.$twice->occurs_on?->format('d M Y').'.';
            }
        }

        return $errors;
    }

    /**
     * The schedule name for a vaccine as typed, ignoring case and spacing.
     */
    protected function vaccine(?string $name): ?string
    {
        $key = strtolower(preg_replace('/[^a-z]/i', '', (string) $name));
        $aliases = ['penta' => 'Pentavalent', 'mr' => 'Measles-Rubella', 'measles' => 'Measles-Rubella', 'rota' => 'Rotavirus'];

        foreach (array_keys(self::SCHEDULE) as $vaccine) {
            if (strtolower(preg_replace('/[^a-z]/i', '', $vaccine)) === $key) {
                return $vaccine;
            }
        }

        return $aliases[$key] ?? null;
    }

    /**
     * A schedule age in days, written as birth, weeks or months.
     */
    protected function age(int $days): string
    {
        return match (true) {
            $days === 0 => 'birth',
            $days < 180 => intdiv($days, 7).' weeks',
            default => (int) round($days / 30.4).' months',
        };
    }

    /**
     * The scheduled doses a child has not had yet, with the date each is due.
     *
     * @return Collection<int, array{vaccine: string, dose: int, due: Carbon}>
     */
    protected function outstanding(Record $child): Collection
    {
        if (blank($child->value('date_of_birth'))) {
            return collect();
        }
        $born = Carbon::parse($child->value('date_of_birth'));
        $given = $child->exists
            ? $this->linked('doses', 'child', $child)->where('status', 'given')->get()->map(fn (Record $dose) => $this->vaccine($dose->title).' '.((int) ($dose->value('dose_number') ?: 1)))->all()
            : [];

        return collect(self::SCHEDULE)->flatMap(fn (array $ages, string $vaccine) => collect($ages)->map(fn (int $days, int $index) => ['vaccine' => $vaccine, 'dose' => $index + 1, 'due' => $born->copy()->addDays($days)]))
            ->reject(fn (array $dose) => in_array($dose['vaccine'].' '.$dose['dose'], $given, true))
            ->sortBy(fn (array $dose) => $dose['due']->timestamp)->values();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'doses') {
            $this->put($record, ['dose_number' => (int) ($record->value('dose_number') ?: 1)]);
            if ($vaccine = $this->vaccine($record->title)) {
                $record->title = $vaccine;
            }

            return;
        }
        if ($record->entity !== 'children') {
            return;
        }
        $outstanding = $this->outstanding($record);
        $next = $outstanding->first();
        $total = collect(self::SCHEDULE)->flatten()->count();
        $record->due_on = $next['due'] ?? null;
        $record->status = match (true) {
            $next === null || $next['due']->isFuture() => 'up_to_date',
            $next['due']->lt(today()->subDays(self::DEFAULTER_DAYS)) => 'defaulter',
            default => 'due',
        };
        $this->put($record, [
            '_doses_given' => $total - $outstanding->count(),
            '_doses_total' => $total,
            '_next_doses' => $next ? $outstanding->filter(fn (array $dose) => $dose['due']->isSameDay($next['due']))->map(fn (array $dose) => $dose['vaccine'].' '.$dose['dose'])->implode(', ') : null,
            '_fully_immunised' => $outstanding->isEmpty(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'doses') {
            $this->recalculate($this->parent($record, 'child'));
            $this->recalculate($this->previousParent($record, 'child'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'doses') {
            $this->recalculate($this->parent($record, 'child'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        $this->records('children')->get()->each(function (Record $child) use (&$changed) {
            $before = $child->status;
            $this->recalculate($child);
            $changed += $child->fresh()->status !== $before ? 1 : 0;
        });

        return $changed;
    }

    public function actions(Record $record): array
    {
        return $record->entity === 'children' && in_array($record->status, ['due', 'defaulter'], true) ? [
            'vaccinate' => ['label' => 'Give due doses', 'icon' => 'syringe', 'fields' => [
                ['name' => 'batch', 'label' => 'Batch number', 'type' => 'text'],
                ['name' => 'site', 'label' => 'Facility / outreach site', 'type' => 'text'],
            ]],
        ] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $input = $request->validate(['batch' => ['required', 'string', 'max:60'], 'site' => ['nullable', 'string', 'max:120']]);
        $due = $this->outstanding($record)->filter(fn (array $dose) => $dose['due']->lte(today()));
        if ($due->isEmpty()) {
            throw ValidationException::withMessages(['batch' => 'Nothing is due for '.$record->title.' yet.']);
        }
        foreach ($due as $dose) {
            Record::create([
                'workspace_id' => $record->workspace_id,
                'blueprint' => $record->blueprint,
                'entity' => 'doses',
                'title' => $dose['vaccine'],
                'status' => 'given',
                'occurs_on' => today(),
                'assignee_id' => $request->user()?->id,
                'data' => ['child' => $record->id, 'dose_number' => $dose['dose'], 'batch' => $input['batch'], 'site' => $input['site'] ?? null],
            ]);
        }
        $record = $record->fresh();

        return $due->count().' dose(s) given to '.$record->title.': '.$due->map(fn (array $dose) => $dose['vaccine'].' '.$dose['dose'])->implode(', ').'. '
            .($record->due_on ? 'Next due '.$record->due_on->format('d M Y').'.' : 'Fully immunised.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'children') {
            return [];
        }
        $outstanding = $this->outstanding($record);
        $born = $record->value('date_of_birth') ? Carbon::parse($record->value('date_of_birth')) : null;
        $tone = ['due' => 'warning', 'defaulter' => 'danger'][$record->status] ?? null;

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Immunisation', 'icon' => 'syringe', 'stats' => [
                ['label' => 'Age', 'value' => $born ? $this->age((int) $born->diffInDays(today())) : '—'],
                ['label' => 'Doses given', 'value' => (int) $record->value('_doses_given').' of '.(int) $record->value('_doses_total')],
                ['label' => 'Next due', 'value' => $record->due_on ? $record->due_on->format('d M Y') : 'Fully immunised', 'tone' => $tone],
                ['label' => 'Next doses', 'value' => (string) ($record->value('_next_doses') ?? '—')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Outstanding doses', 'icon' => 'calendar-clock', 'empty' => 'Every scheduled dose has been given.',
                'rows' => $outstanding->map(fn (array $dose) => [
                    'label' => $dose['vaccine'].' '.$dose['dose'], 'value' => $dose['due']->format('d M Y'), 'tone' => $dose['due']->lte(today()) ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $children = $this->records('children')->get();
        $chase = $children->whereIn('status', ['due', 'defaulter'])->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Register', 'icon' => 'baby', 'stats' => [
                ['label' => 'Children', 'value' => (string) $children->count()],
                ['label' => 'Up to date', 'value' => (string) $children->where('status', 'up_to_date')->count()],
                ['label' => 'Due now', 'value' => (string) $children->where('status', 'due')->count(), 'tone' => $children->where('status', 'due')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Defaulters', 'value' => (string) $children->where('status', 'defaulter')->count(), 'tone' => $children->where('status', 'defaulter')->isNotEmpty() ? 'danger' : null],
                ['label' => 'CHW visits this month', 'value' => (string) $this->records('chw_visits')->whereBetween('occurs_on', [today()->startOfMonth(), today()->endOfMonth()])->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Children to trace', 'icon' => 'footprints', 'empty' => 'No child is due or overdue.',
                'rows' => $chase->map(fn (Record $child) => [
                    'label' => $child->title, 'sub' => trim($child->value('_next_doses').' · '.$child->value('village'), ' ·'), 'value' => $child->due_on?->format('d M'), 'href' => $child->url(), 'tone' => $child->status === 'defaulter' ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $doses = $this->dated('doses', $from, $to)->get();
        $byVaccine = collect(self::SCHEDULE)->flatMap(fn (array $ages, string $vaccine) => collect($ages)->keys()->map(fn (int $index) => $vaccine.' '.($index + 1)))
            ->map(function (string $label) use ($doses) {
                $group = $doses->filter(fn (Record $dose) => $dose->title.' '.$dose->value('dose_number') === $label);

                return [$label, $group->where('status', 'given')->count(), $group->where('status', 'missed')->count()];
            })->values()->all();

        $children = $this->records('children')->get();
        $byVillage = $children->groupBy(fn (Record $child) => (string) ($child->value('village') ?: 'Unknown'))->sortKeys()
            ->map(fn (Collection $group, string $village) => [
                $village, $group->count(), $group->where('status', 'up_to_date')->count(), $group->where('status', 'due')->count(), $group->where('status', 'defaulter')->count(),
                (int) round($group->where('status', 'up_to_date')->count() / $group->count() * 100).'%',
            ])->values()->all();

        $visits = $this->dated('chw_visits', $from, $to)->get();
        $byReason = $visits->groupBy(fn (Record $visit) => str_replace('_', ' / ', ucfirst((string) $visit->value('reason'))))->sortKeys()
            ->map(fn (Collection $group, string $reason) => [$reason, $group->count(), $group->where('status', 'referred')->count(), $group->where('status', 'follow_up')->count()])->values()->all();

        return [
            ['title' => 'Doses by vaccine', 'columns' => ['Dose', 'Given', 'Missed'], 'rows' => $byVaccine],
            ['title' => 'Coverage by village', 'columns' => ['Village', 'Children', 'Up to date', 'Due', 'Defaulters', 'Coverage'], 'rows' => $byVillage],
            ['title' => 'CHW visits by reason', 'columns' => ['Reason', 'Visits', 'Referred', 'Follow-up'], 'rows' => $byReason],
        ];
    }
}
