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
 * Veterinary clinic: each animal has one microchip or tag number and its age is worked out from
 * its date of birth. Consultations and vaccinations are only recorded for living animals, a
 * vaccination given books the next dose a year later (or on the date set), doses that pass their
 * date become overdue every morning, and an animal that dies has its future doses removed.
 */
class VeterinaryLogic extends AppLogic
{
    public const BOOSTER_MONTHS = 12;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'animals') {
            $chip = strtoupper(trim((string) ($data['microchip'] ?? '')));
            if ($chip !== '' && $this->records('animals')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $animal) => strtoupper(trim((string) $animal->value('microchip'))) === $chip)) {
                $errors['data.microchip'] = 'Another animal has this microchip / tag number.';
            }
            if (filled($data['date_of_birth'] ?? null) && Carbon::parse($data['date_of_birth'])->gt(today())) {
                $errors['data.date_of_birth'] = 'The date of birth cannot be in the future.';
            }

            return $errors;
        }

        $animal = filled($data['animal'] ?? null) ? $this->records('animals')->find($data['animal']) : null;
        if ($animal && $animal->status !== 'active' && ! $existing) {
            $errors['data.animal'] = $animal->title.' is '.$animal->status.'.';
        }
        if ($entity->key === 'consultations') {
            if ($payload['status'] === 'completed' && blank($data['treatment'] ?? null) && blank($data['findings'] ?? null)) {
                $errors['data.findings'] = 'Record the findings or the treatment.';
            }
            if (filled($data['follow_up'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['follow_up'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['data.follow_up'] = 'The follow-up must be after the consultation.';
            }
            if (filled($data['weight'] ?? null) && (float) $data['weight'] <= 0) {
                $errors['data.weight'] = 'Enter a weight above zero.';
            }
        }
        if ($entity->key === 'vaccinations') {
            if ($payload['status'] === 'given' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'A dose cannot be given in the future.';
            }
            if ($payload['status'] !== 'given' && blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'Say when the dose is due.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'animals') {
            $born = $record->value('date_of_birth');
            $this->put($record, [
                'microchip' => filled($record->value('microchip')) ? strtoupper(trim((string) $record->value('microchip'))) : null,
                '_age' => $born ? $this->age(Carbon::parse($born)) : null,
            ]);

            return;
        }

        if ($record->entity === 'consultations') {
            $record->occurs_on ??= today();

            return;
        }

        if ($record->status === 'given') {
            $record->occurs_on ??= today();
        } elseif ($record->due_on) {
            $record->status = $record->due_on->lt(today()) ? 'overdue' : 'due';
        }
    }

    /**
     * An age such as "3 yrs 2 mths" or "5 mths".
     */
    protected function age(Carbon $born): string
    {
        $months = (int) $born->diffInMonths(today());

        return $months >= 12 ? intdiv($months, 12).' yrs'.($months % 12 ? ' '.($months % 12).' mths' : '') : $months.' mths';
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'animals' && $record->wasChanged('status') && $record->status === 'deceased') {
            $this->linked('vaccinations', 'animal', $record)->whereIn('status', ['due', 'overdue'])->get()->each->delete();
        }
        if ($record->entity === 'consultations' && $record->status === 'completed' && filled($record->value('weight')) && $animal = $this->parent($record, 'animal')) {
            if ((float) $animal->value('_weight') !== $this->number($record, 'weight')) {
                $animal->update(['data' => [...$animal->data, '_weight' => $this->number($record, 'weight'), '_weighed_on' => $record->occurs_on?->toDateString()]]);
            }
        }
    }

    public function daily(Workspace $workspace): int
    {
        $marked = 0;
        foreach ($this->records('vaccinations')->where('status', 'due')->whereDate('due_on', '<', today())->get() as $dose) {
            $dose->update(['status' => 'overdue']);
            $marked++;
        }

        return $marked;
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'vaccinations' && in_array($record->status, ['due', 'overdue'], true) => ['give' => ['label' => 'Give dose', 'icon' => 'syringe', 'fields' => [
                ['name' => 'batch_number', 'label' => 'Batch number', 'type' => 'text'],
                ['name' => 'next_due', 'label' => 'Next due (blank for a year)', 'type' => 'date'],
            ]]],
            $record->entity === 'consultations' && $record->status === 'open' => ['complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [
                ['name' => 'findings', 'label' => 'Findings', 'type' => 'textarea', 'value' => $record->value('findings')],
                ['name' => 'treatment', 'label' => 'Treatment', 'type' => 'textarea', 'value' => $record->value('treatment')],
            ]]],
            $record->entity === 'animals' && $record->status === 'active' => ['deceased' => ['label' => 'Record death', 'icon' => 'heart-off']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'deceased') {
            $record->update(['status' => 'deceased']);

            return $record->title.' recorded as deceased; upcoming vaccinations removed.';
        }

        if ($action === 'complete') {
            $input = $request->validate(['findings' => ['nullable', 'string'], 'treatment' => ['nullable', 'string']]);
            if (blank($input['findings'] ?? null) && blank($input['treatment'] ?? null)) {
                throw ValidationException::withMessages(['findings' => 'Record the findings or the treatment.']);
            }
            $record->update(['status' => 'completed', 'data' => [...$record->data, 'findings' => $input['findings'] ?? null, 'treatment' => $input['treatment'] ?? null]]);

            return 'Consultation for '.($this->parent($record, 'animal')?->title ?? $record->title).' completed.';
        }

        $input = $request->validate(['batch_number' => ['nullable', 'string'], 'next_due' => ['nullable', 'date', 'after:today']]);
        $animal = $this->parent($record, 'animal');
        if (! $animal || $animal->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'This animal is no longer at the clinic.']);
        }
        $next = filled($input['next_due'] ?? null) ? Carbon::parse($input['next_due']) : today()->addMonths(self::BOOSTER_MONTHS);
        $record->update(['status' => 'given', 'occurs_on' => today(), 'due_on' => $next, 'data' => [...$record->data, 'batch_number' => ($input['batch_number'] ?? null) ?: $record->value('batch_number')]]);

        Record::create([
            'workspace_id' => $record->workspace_id,
            'blueprint' => $record->blueprint,
            'entity' => 'vaccinations',
            'title' => $record->title,
            'status' => 'due',
            'due_on' => $next,
            'data' => ['animal' => $animal->id],
        ]);

        return $record->title.' given to '.$animal->title.'; next dose due '.$next->format('d M Y').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'animals') {
            return [];
        }
        $doses = $this->linked('vaccinations', 'animal', $record)->get();
        $visits = $this->linked('consultations', 'animal', $record)->get()->sortByDesc('occurs_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Patient', 'icon' => 'paw-print', 'stats' => [
                ['label' => 'Age', 'value' => (string) ($record->value('_age') ?? '—')],
                ['label' => 'Last weight', 'value' => $record->value('_weight') === null ? '—' : $record->value('_weight').' kg'],
                ['label' => 'Visits', 'value' => (string) $visits->count()],
                ['label' => 'Last visit', 'value' => $visits->first()?->occurs_on?->format('d M Y') ?? '—'],
                ['label' => 'Vaccinations overdue', 'value' => (string) $doses->where('status', 'overdue')->count(), 'tone' => $doses->where('status', 'overdue')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Vaccinations', 'icon' => 'syringe', 'empty' => 'No vaccinations recorded.',
                'rows' => $doses->sortByDesc(fn (Record $dose) => ($dose->occurs_on ?? $dose->due_on)?->toDateString())->map(fn (Record $dose) => [
                    'label' => $dose->title, 'sub' => ucfirst($dose->status), 'value' => ($dose->status === 'given' ? $dose->occurs_on : $dose->due_on)?->format('d M Y'), 'href' => $dose->url(), 'tone' => $dose->status === 'overdue' ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $doses = $this->records('vaccinations')->whereIn('status', ['due', 'overdue'])->whereDate('due_on', '<=', today()->addDays(14))->orderBy('due_on')->get();
        $animals = $this->records('animals')->get()->keyBy('id');
        $followUps = $this->records('consultations')->get()->filter(fn (Record $visit) => filled($visit->value('follow_up')) && Carbon::parse($visit->value('follow_up'))->between(today(), today()->addDays(7)))->sortBy(fn (Record $visit) => $visit->value('follow_up'));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Vaccinations due', 'icon' => 'syringe', 'empty' => 'No vaccinations due in the next two weeks.',
                'rows' => $doses->take(10)->map(fn (Record $dose) => [
                    'label' => ($animals->get($dose->value('animal'))?->title ?? '—').' · '.$dose->title, 'sub' => ucfirst($dose->status), 'value' => $dose->due_on?->format('d M'), 'href' => $dose->url(), 'tone' => $dose->status === 'overdue' ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Follow-ups this week', 'icon' => 'calendar-check', 'empty' => 'No follow-ups this week.',
                'rows' => $followUps->take(10)->map(fn (Record $visit) => [
                    'label' => ($animals->get($visit->value('animal'))?->title ?? '—').' · '.$visit->title, 'value' => Carbon::parse($visit->value('follow_up'))->format('d M'), 'href' => $visit->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('consultations', $from, $to)->get();
        $animals = $this->records('animals')->get();
        $species = $this->app->entities['animals']->field('species')?->options ?? [];
        $speciesOf = $animals->mapWithKeys(fn (Record $animal) => [$animal->id => $animal->value('species')]);

        $bySpecies = collect($species)->map(function (string $label, string $key) use ($animals, $visits, $speciesOf) {
            $group = $visits->filter(fn (Record $visit) => $speciesOf->get($visit->value('animal')) === $key);

            return [$label, $animals->filter(fn (Record $animal) => $animal->value('species') === $key && $animal->status === 'active')->count(), $group->count(), $this->money($group->where('status', 'completed')->sum('amount'))];
        })->filter(fn (array $row) => $row[1] > 0 || $row[2] > 0)->values()->all();

        $doses = $this->dated('vaccinations', $from, $to)->where('status', 'given')->get();
        $byVaccine = $doses->groupBy('title')->sortKeys()->map(fn (Collection $group, string $vaccine) => [$vaccine, $group->count(), $group->pluck('data.animal')->unique()->count()])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($visits, $doses) {
            $group = $visits->filter(fn (Record $visit) => $visit->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $doses->filter(fn (Record $dose) => $dose->occurs_on?->format('Y-m') === $month)->count(), $this->money($group->where('status', 'completed')->sum('amount'))];
        })->values()->all();

        return [
            ['title' => 'Patients by species', 'columns' => ['Species', 'Active animals', 'Consultations', 'Fees'], 'rows' => $bySpecies],
            ['title' => 'Vaccinations given', 'columns' => ['Vaccine', 'Doses', 'Animals'], 'rows' => $byVaccine],
            ['title' => 'Activity by month', 'columns' => ['Month', 'Consultations', 'Vaccinations', 'Fees'], 'rows' => $byMonth],
        ];
    }
}
