<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Medical records: each chart has one file number and an age from its date of birth. Encounters are
 * only opened on active charts, carry valid ICD-10 codes and need a diagnosis, and once signed they
 * are locked: nothing changes except through a dated addendum, which keeps the original note. A
 * chart shows its allergies, last visit and the diagnoses made over time.
 */
class EmrLogic extends AppLogic
{
    public const ICD10 = '/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/';

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'records') {
            $file = strtoupper(trim((string) ($data['file_number'] ?? '')));
            if ($file !== '' && $this->records('records')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $chart) => strtoupper((string) $chart->value('file_number')) === $file)) {
                $errors['data.file_number'] = 'Another chart has file number '.$file.'.';
            }
            if (filled($data['date_of_birth'] ?? null) && Carbon::parse($data['date_of_birth'])->gt(today())) {
                $errors['data.date_of_birth'] = 'The date of birth cannot be in the future.';
            }

            return $errors;
        }

        if ($existing && $existing->status === 'signed') {
            return ['status' => 'This encounter is signed; add an addendum instead.'];
        }
        $chart = filled($data['chart'] ?? null) ? $this->records('records')->find($data['chart']) : null;
        if ($chart && $chart->status !== 'active') {
            $errors['data.chart'] = $chart->title.'\'s chart is '.$chart->status.'.';
        }
        if ($invalid = $this->codes($data['icd10'] ?? null)->reject(fn (string $code) => preg_match(self::ICD10, $code) === 1)->first()) {
            $errors['data.icd10'] = $invalid.' is not an ICD-10 code.';
        }
        if ($payload['status'] === 'signed' && $this->codes($data['icd10'] ?? null)->isEmpty() && ! isset($errors['data.icd10'])) {
            $errors['data.icd10'] = 'Code the diagnosis before signing.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'An encounter cannot be in the future.';
        }

        return $errors;
    }

    /**
     * ICD-10 codes, uppercased and split on commas, semicolons or spaces.
     *
     * @return Collection<int, string>
     */
    protected function codes(?string $codes): Collection
    {
        return collect(preg_split('/[\s,;]+/', strtoupper(trim((string) $codes)), -1, PREG_SPLIT_NO_EMPTY))->unique()->values();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'records') {
            $born = $record->value('date_of_birth');
            $this->put($record, [
                'file_number' => strtoupper(trim((string) $record->value('file_number'))),
                '_age' => $born ? (int) Carbon::parse($born)->diffInYears(today()) : null,
                '_has_allergies' => filled($record->value('allergies')) && ! in_array(strtolower(trim((string) $record->value('allergies'))), ['none', 'nkda', 'nil', 'no known allergies'], true),
            ]);

            return;
        }

        $record->occurs_on ??= today();
        $this->put($record, ['icd10' => $this->codes($record->value('icd10'))->implode(', ') ?: null]);
        if ($record->status === 'signed' && blank($record->value('_signed_at'))) {
            $this->put($record, ['_signed_at' => now()->toDateTimeString(), '_signed_by' => auth()->id() ?? $record->assignee_id]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'encounters' && $chart = $this->parent($record, 'chart')) {
            $this->summarise($chart);
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'encounters' && $chart = $this->parent($record, 'chart')) {
            $this->summarise($chart);
        }
    }

    /**
     * Keep the chart's visit count, last visit and latest diagnosis up to date.
     */
    protected function summarise(Record $chart): void
    {
        $encounters = $this->linked('encounters', 'chart', $chart)->get()->sortByDesc(fn (Record $encounter) => $encounter->occurs_on?->toDateString().sprintf('%010d', $encounter->id));
        $latest = $encounters->first();
        $summary = [
            '_visits' => $encounters->count(),
            '_last_visit' => $latest?->occurs_on?->toDateString(),
            '_last_diagnosis' => $latest ? trim(($latest->value('icd10') ? $latest->value('icd10').' ' : '').str($latest->value('diagnosis'))->limit(80)) : null,
            '_unsigned' => $encounters->where('status', 'open')->count(),
        ];
        if (collect($summary)->contains(fn ($value, string $key) => $chart->value($key) !== $value)) {
            $chart->data = [...$chart->data, ...$summary];
            $chart->saveQuietly();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'encounters') {
            return $record->status === 'active' ? ['deceased' => ['label' => 'Record death', 'icon' => 'heart-off', 'fields' => [['name' => 'date_of_death', 'label' => 'Date of death', 'type' => 'date']]]] : [];
        }

        return $record->status === 'open'
            ? ['sign' => ['label' => 'Sign', 'icon' => 'pen-line']]
            : ['addendum' => ['label' => 'Add addendum', 'icon' => 'file-plus', 'fields' => [['name' => 'note', 'label' => 'Addendum', 'type' => 'textarea']]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'deceased') {
            $date = $request->validate(['date_of_death' => ['required', 'date', 'before_or_equal:today']])['date_of_death'];
            $record->update(['status' => 'deceased', 'data' => [...$record->data, '_date_of_death' => Carbon::parse($date)->toDateString()]]);

            return $record->title.'\'s chart closed: deceased '.Carbon::parse($date)->format('d M Y').'.';
        }

        if ($action === 'sign') {
            $errors = $this->validate($this->app->entities['encounters'], ['status' => 'signed', 'occurs_on' => $record->occurs_on?->toDateString(), 'data' => $record->data], $record);
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $record->update(['status' => 'signed']);

            return 'Encounter for '.($this->parent($record, 'chart')?->title ?? $record->title).' signed and locked.';
        }

        $note = $request->validate(['note' => ['required', 'string']])['note'];
        $addenda = [...(array) ($record->value('_addenda') ?? []), ['at' => now()->toDateTimeString(), 'by' => $request->user()?->name, 'note' => $note]];
        $record->data = [...$record->data, '_addenda' => $addenda];
        $record->saveQuietly();

        return 'Addendum added to the encounter.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'encounters') {
            $addenda = collect((array) ($record->value('_addenda') ?? []));

            return $addenda->isEmpty() && $record->status !== 'signed' ? [] : [
                ['view' => 'apps.logic.list-card', 'data' => [
                    'title' => 'Signature and addenda', 'icon' => 'pen-line', 'empty' => 'No addenda.',
                    'rows' => [
                        ...($record->value('_signed_at') ? [['label' => 'Signed', 'sub' => (string) (User::query()->whereKey($record->value('_signed_by'))->value('name') ?? ''), 'value' => Carbon::parse($record->value('_signed_at'))->format('d M Y H:i')]] : []),
                        ...$addenda->map(fn (array $addendum) => ['label' => (string) $addendum['note'], 'sub' => (string) ($addendum['by'] ?? ''), 'value' => Carbon::parse($addendum['at'])->format('d M Y H:i')])->all(),
                    ],
                ]],
            ];
        }

        $encounters = $this->linked('encounters', 'chart', $record)->get()->sortByDesc(fn (Record $encounter) => $encounter->occurs_on?->toDateString());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Chart', 'icon' => 'folder-heart', 'stats' => [
                ['label' => 'Age', 'value' => $record->value('_age') === null ? '—' : $record->value('_age').' yrs'],
                ['label' => 'Allergies', 'value' => $record->value('_has_allergies') ? (string) str($record->value('allergies'))->limit(40) : 'None known', 'tone' => $record->value('_has_allergies') ? 'danger' : null],
                ['label' => 'Visits', 'value' => (string) $encounters->count()],
                ['label' => 'Last visit', 'value' => $encounters->first()?->occurs_on?->format('d M Y') ?? '—'],
                ['label' => 'Unsigned notes', 'value' => (string) $encounters->where('status', 'open')->count(), 'tone' => $encounters->where('status', 'open')->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'History', 'icon' => 'history', 'empty' => 'No encounters yet.',
                'rows' => $encounters->take(15)->map(fn (Record $encounter) => [
                    'label' => $encounter->title, 'sub' => trim(($encounter->value('icd10') ? $encounter->value('icd10').' · ' : '').str($encounter->value('diagnosis'))->limit(60)), 'value' => $encounter->occurs_on?->format('d M Y'), 'href' => $encounter->url(), 'tone' => $encounter->status === 'open' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('encounters')->where('status', 'open')->orderBy('occurs_on')->get();
        $charts = $this->records('records')->get()->keyBy('id');
        $names = User::query()->whereIn('id', $open->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Records', 'icon' => 'clipboard-plus', 'stats' => [
                ['label' => 'Active charts', 'value' => (string) $charts->where('status', 'active')->count()],
                ['label' => 'Encounters today', 'value' => (string) $this->records('encounters')->whereDate('occurs_on', today())->count()],
                ['label' => 'Unsigned notes', 'value' => (string) $open->count(), 'tone' => $open->contains(fn (Record $encounter) => $encounter->occurs_on?->lt(today())) ? 'danger' : null],
                ['label' => 'Charts with allergies', 'value' => (string) $charts->filter(fn (Record $chart) => $chart->value('_has_allergies'))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Notes to sign', 'icon' => 'pen-line', 'empty' => 'Every note is signed.',
                'rows' => $open->take(10)->map(fn (Record $encounter) => [
                    'label' => ($charts->get($encounter->value('chart'))?->title ?? '—').' · '.$encounter->title, 'sub' => (string) ($names[$encounter->assignee_id] ?? ''), 'value' => $encounter->occurs_on?->format('d M'), 'href' => $encounter->url(), 'tone' => $encounter->occurs_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $encounters = $this->dated('encounters', $from, $to)->get();
        $diagnoses = $encounters->flatMap(fn (Record $encounter) => $this->codes($encounter->value('icd10'))->map(fn (string $code) => ['code' => $code, 'chart' => $encounter->value('chart')]))
            ->groupBy('code')->map(fn (Collection $group, string $code) => [$code, $group->count(), $group->pluck('chart')->unique()->count()])
            ->sortByDesc(fn (array $row) => $row[1])->take(25)->values()->all();

        $names = User::query()->whereIn('id', $encounters->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byClinician = $encounters->groupBy(fn (Record $encounter) => $names[$encounter->assignee_id] ?? 'Unassigned')->sortKeys()->map(fn (Collection $group, string $name) => [
            $name, $group->count(), $group->where('status', 'signed')->count(), $group->where('status', 'open')->count(), $group->pluck('data.chart')->unique()->count(),
        ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($encounters) {
            $group = $encounters->filter(fn (Record $encounter) => $encounter->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->pluck('data.chart')->unique()->count()];
        })->values()->all();

        return [
            ['title' => 'Top diagnoses', 'columns' => ['ICD-10', 'Encounters', 'Patients'], 'rows' => $diagnoses],
            ['title' => 'Encounters by clinician', 'columns' => ['Clinician', 'Encounters', 'Signed', 'Unsigned', 'Patients'], 'rows' => $byClinician],
            ['title' => 'Encounters by month', 'columns' => ['Month', 'Encounters', 'Patients'], 'rows' => $byMonth],
        ];
    }
}
