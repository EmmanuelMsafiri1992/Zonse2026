<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Prison & correctional records: every inmate has one inmate number, a sentenced inmate has a
 * sentence and a release date, and nobody is released before that date. Visits are booked only for
 * inmates in custody with the visitor's ID, one visit per inmate a day, and a visit is completed
 * only on or after its day. Each inmate tracks days in custody, days to release and visits.
 */
class PrisonLogic extends AppLogic
{
    public const CUSTODY = ['remand', 'sentenced'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'inmates') {
            $number = strtoupper(trim((string) ($data['inmate_number'] ?? '')));
            if ($number !== '' && $this->records('inmates')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $inmate) => strtoupper(trim((string) $inmate->value('inmate_number'))) === $number)) {
                $errors['data.inmate_number'] = 'Inmate number '.$number.' is taken.';
            }
            if ($payload['status'] === 'sentenced') {
                if (blank($data['sentence'] ?? null)) {
                    $errors['data.sentence'] = 'Record the sentence.';
                }
                if (blank($data['release_date'] ?? null)) {
                    $errors['data.release_date'] = 'Set the release date.';
                }
            }
            if (filled($data['release_date'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['release_date'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['data.release_date'] = 'Release cannot be before admission.';
            }

            return $errors;
        }

        $inmate = ! empty($data['inmate']) ? $this->records('inmates')->find($data['inmate']) : null;
        $day = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        if ($inmate && $payload['status'] === 'booked') {
            if (! in_array($inmate->status, self::CUSTODY, true)) {
                $errors['data.inmate'] = $inmate->title.' is not in custody.';
            } elseif ($this->linked('visits', 'inmate', $inmate)->where('status', '!=', 'refused')->whereDate('occurs_on', $day)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['occurs_on'] = $inmate->title.' already has a visit on '.$day->format('d M Y').'.';
            }
        }
        if ($payload['status'] === 'booked' && blank($data['id_number'] ?? null)) {
            $errors['data.id_number'] = 'Record the visitor\'s ID.';
        }
        if ($payload['status'] === 'completed' && $day->gt(today())) {
            $errors['status'] = 'A visit cannot be completed before its day.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'visits') {
            return;
        }

        $release = filled($record->value('release_date')) ? Carbon::parse($record->value('release_date')) : null;
        $inCustody = in_array($record->status, self::CUSTODY, true);
        $left = $inCustody ? null : ($record->value('_left_on') ?? today()->toDateString());
        $visits = $record->exists ? $this->linked('visits', 'inmate', $record)->get() : collect();
        $this->put($record, [
            'inmate_number' => strtoupper(trim((string) $record->value('inmate_number'))) ?: null,
            '_left_on' => $left,
            '_days_in_custody' => (int) $record->occurs_on->diffInDays($left ? Carbon::parse($left) : today()),
            '_days_to_release' => $inCustody && $release ? (int) today()->diffInDays($release, false) : null,
            '_visits' => $visits->where('status', 'completed')->count(),
            '_last_visit' => $visits->where('status', 'completed')->sortByDesc('occurs_on')->first()?->occurs_on?->toDateString(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'visits') {
            $this->recalculate($this->parent($record, 'inmate'));
            $this->recalculate($this->previousParent($record, 'inmate'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'visits') {
            $this->recalculate($this->parent($record, 'inmate'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'visits') {
            return $record->status === 'booked'
                ? ['complete' => ['label' => 'Visit done', 'icon' => 'check'], 'refuse' => ['label' => 'Refuse', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]]
                : [];
        }

        $transfer = ['label' => 'Transfer', 'icon' => 'truck', 'fields' => [['name' => 'facility', 'label' => 'To facility', 'type' => 'text']]];
        $escape = ['label' => 'Record escape', 'icon' => 'siren', 'confirm' => 'Record that '.$record->title.' has escaped?'];

        return match ($record->status) {
            'remand' => [
                'sentence' => ['label' => 'Sentence', 'icon' => 'gavel', 'fields' => [
                    ['name' => 'sentence', 'label' => 'Sentence', 'type' => 'text'],
                    ['name' => 'release_date', 'label' => 'Release date', 'type' => 'date'],
                ]],
                'release' => ['label' => 'Release on bail', 'icon' => 'door-open', 'confirm' => 'Release '.$record->title.'?'],
                'transfer' => $transfer, 'escape' => $escape,
            ],
            'sentenced' => ['release' => ['label' => 'Release', 'icon' => 'door-open', 'confirm' => 'Release '.$record->title.'?'], 'transfer' => $transfer, 'escape' => $escape],
            'escaped' => ['recapture' => ['label' => 'Recaptured', 'icon' => 'lock']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'visits') {
            if ($action === 'complete') {
                if ($record->occurs_on->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'A visit cannot be completed before its day.']);
                }
                $record->update(['status' => 'completed']);

                return 'Visit by '.$record->title.' done.';
            }

            $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
            $record->update(['status' => 'refused', 'data' => [...$record->data, '_reason' => $reason]]);

            return 'Visit by '.$record->title.' refused.';
        }

        switch ($action) {
            case 'sentence':
                $input = $request->validate(['sentence' => ['required', 'string'], 'release_date' => ['required', 'date', 'after:today']]);
                $release = Carbon::parse($input['release_date']);
                $record->update(['status' => 'sentenced', 'data' => [...$record->data, 'sentence' => $input['sentence'], 'release_date' => $release->toDateString()]]);

                return $record->title.' sentenced to '.$input['sentence'].'; release due '.$release->format('d M Y').'.';
            case 'release':
                if ($record->status === 'sentenced' && filled($record->value('release_date')) && Carbon::parse($record->value('release_date'))->gt(today())) {
                    throw ValidationException::withMessages(['status' => $record->title.' is not due for release until '.Carbon::parse($record->value('release_date'))->format('d M Y').'.']);
                }
                $record->update(['status' => 'released', 'data' => [...$record->data, '_left_on' => today()->toDateString(), '_custody_status' => $record->status]]);

                return $record->title.' released.';
            case 'transfer':
                $facility = $request->validate(['facility' => ['required', 'string']])['facility'];
                $record->update(['status' => 'transferred', 'data' => [...$record->data, '_left_on' => today()->toDateString(), '_transferred_to' => $facility]]);

                return $record->title.' transferred to '.$facility.'.';
            case 'escape':
                $record->update(['status' => 'escaped', 'data' => [...$record->data, '_left_on' => today()->toDateString(), '_custody_status' => $record->status, '_escapes' => (int) $record->value('_escapes') + 1]]);

                return $record->title.' recorded as escaped.';
        }

        $back = $record->value('_custody_status') ?: 'remand';
        $record->update(['status' => $back, 'data' => [...$record->data, '_left_on' => null]]);

        return $record->title.' recaptured and back on '.$back.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'inmates') {
            return [];
        }

        $visits = $this->linked('visits', 'inmate', $record)->orderByDesc('occurs_on')->get();
        $days = $record->value('_days_to_release');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Inmate', 'icon' => 'user-round', 'stats' => [
                ['label' => 'Inmate number', 'value' => $record->value('inmate_number') ?: '—'],
                ['label' => 'Cell', 'value' => (string) ($record->value('cell') ?: '—')],
                ['label' => 'Sentence', 'value' => (string) ($record->value('sentence') ?: ($record->status === 'remand' ? 'On remand' : '—'))],
                ['label' => 'Days in custody', 'value' => (string) (int) $record->value('_days_in_custody')],
                ['label' => 'Release', 'value' => filled($record->value('release_date')) ? Carbon::parse($record->value('release_date'))->format('d M Y').($days !== null ? ' · '.(int) $days.' days' : '') : '—', 'tone' => $days !== null && (int) $days <= 7 ? 'warning' : null],
                ['label' => 'Visits', 'value' => (string) (int) $record->value('_visits')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Visits', 'icon' => 'users', 'empty' => 'No visits booked.',
                'rows' => $visits->take(15)->map(fn (Record $visit) => [
                    'label' => $visit->title, 'sub' => trim(($visit->value('relationship') ?: '').' · '.$visit->occurs_on?->format('d M Y').($visit->value('time') ? ' '.$visit->value('time') : ''), ' ·'), 'value' => ucfirst($visit->status), 'href' => $visit->url(), 'tone' => $visit->status === 'refused' ? 'danger' : ($visit->status === 'completed' ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $inmates = $this->records('inmates')->get();
        $custody = $inmates->whereIn('status', self::CUSTODY);
        $due = $custody->filter(fn (Record $inmate) => $inmate->value('_days_to_release') !== null && (int) $inmate->value('_days_to_release') <= 7)->sortBy(fn (Record $inmate) => (int) $inmate->value('_days_to_release'));
        $visits = $this->records('visits')->whereDate('occurs_on', today())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Facility', 'icon' => 'lock-keyhole', 'stats' => [
                ['label' => 'In custody', 'value' => (string) $custody->count()],
                ['label' => 'On remand', 'value' => (string) $custody->where('status', 'remand')->count()],
                ['label' => 'Sentenced', 'value' => (string) $custody->where('status', 'sentenced')->count()],
                ['label' => 'Due for release this week', 'value' => (string) $due->count()],
                ['label' => 'Visits today', 'value' => (string) $visits->where('status', '!=', 'refused')->count()],
                ['label' => 'Escaped', 'value' => (string) $inmates->where('status', 'escaped')->count(), 'tone' => $inmates->where('status', 'escaped')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due for release', 'icon' => 'door-open', 'empty' => 'Nobody is due for release this week.',
                'rows' => $due->take(10)->map(fn (Record $inmate) => [
                    'label' => $inmate->title, 'sub' => ($inmate->value('inmate_number') ?: '').($inmate->value('cell') ? ' · '.$inmate->value('cell') : ''), 'value' => Carbon::parse($inmate->value('release_date'))->format('d M Y'), 'href' => $inmate->url(), 'tone' => (int) $inmate->value('_days_to_release') <= 0 ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $inmates = $this->records('inmates')->get();
        $byCell = $inmates->whereIn('status', self::CUSTODY)->groupBy(fn (Record $inmate) => $inmate->value('cell') ?: 'Unassigned')->sortKeys()->map(fn ($group, $cell) => [$cell, $group->count(), $group->where('status', 'remand')->count(), $group->where('status', 'sentenced')->count()])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($inmates) {
            $left = $inmates->filter(fn (Record $inmate) => filled($inmate->value('_left_on')) && Carbon::parse($inmate->value('_left_on'))->format('Y-m') === $month);

            return [$label, $inmates->filter(fn (Record $inmate) => $inmate->occurs_on?->format('Y-m') === $month)->count(), $left->where('status', 'released')->count(), $left->where('status', 'transferred')->count(), $left->where('status', 'escaped')->count()];
        })->values()->all();

        $visits = $this->dated('visits', $from, $to)->get();
        $visitsByMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($visits) {
            $group = $visits->filter(fn (Record $visit) => $visit->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'refused')->count()];
        })->values()->all();

        return [
            ['title' => 'Population by cell', 'columns' => ['Cell / block', 'In custody', 'Remand', 'Sentenced'], 'rows' => $byCell],
            ['title' => 'Admissions and releases by month', 'columns' => ['Month', 'Admitted', 'Released', 'Transferred', 'Escaped'], 'rows' => $byMonth],
            ['title' => 'Visits by month', 'columns' => ['Month', 'Booked', 'Completed', 'Refused'], 'rows' => $visitsByMonth],
        ];
    }
}
