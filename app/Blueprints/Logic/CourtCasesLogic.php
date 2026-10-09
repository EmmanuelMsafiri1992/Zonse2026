<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Court case management: every case file has one case number, hearings are set down only on open
 * cases and never two in the same courtroom at the same time, and a hearing is heard or postponed
 * only once its day has come. A heard hearing makes the case part heard, postponing one sets the
 * next down on the new date, and the cause list shows the day's hearings by time and courtroom.
 */
class CourtCasesLogic extends AppLogic
{
    public const OPEN = ['filed', 'pending', 'part_heard', 'judgment_reserved'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'cases') {
            $number = strtoupper(trim((string) ($data['case_number'] ?? '')));
            if ($number !== '' && $this->records('cases')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $case) => strtoupper(trim((string) $case->value('case_number'))) === $number)) {
                $errors['data.case_number'] = 'Case '.$number.' is already on file.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'The filing date cannot be in the future.';
            }

            return $errors;
        }

        $case = ! empty($data['case']) ? $this->records('cases')->find($data['case']) : null;
        if ($case && ! in_array($case->status, self::OPEN, true) && (! $existing || (int) $existing->value('case') !== $case->id)) {
            $errors['data.case'] = 'Case '.$case->title.' is '.str_replace('_', ' ', $case->status).'.';
        }
        if (in_array($payload['status'], ['heard', 'postponed'], true) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['status'] = 'The hearing day has not come yet.';
        }
        if ($payload['status'] === 'scheduled' && filled($payload['occurs_on'] ?? null) && filled($data['courtroom'] ?? null) && filled($data['time'] ?? null)) {
            $clash = $this->clash(Carbon::parse($payload['occurs_on']), (string) $data['courtroom'], (string) $data['time'], $existing?->id);
            if ($clash) {
                $errors['data.time'] = $data['courtroom'].' already has '.$clash->title.' at '.$data['time'].'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'hearings') {
            $record->occurs_on ??= today();
            $this->put($record, [
                'time' => filled($record->value('time')) ? substr((string) $record->value('time'), 0, 5) : null,
                '_heard_on' => $record->status === 'heard' ? ($record->value('_heard_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $record->occurs_on ??= today();
        $hearings = $record->exists ? $this->linked('hearings', 'case', $record)->get() : collect();
        $next = $hearings->filter(fn (Record $hearing) => $hearing->status === 'scheduled' && $hearing->occurs_on?->gte(today()))->sortBy('occurs_on')->first();
        $last = $hearings->where('status', 'heard')->sortByDesc('occurs_on')->first();
        $this->put($record, [
            'case_number' => strtoupper(trim((string) $record->value('case_number'))) ?: null,
            '_hearings' => $hearings->count(),
            '_heard' => $hearings->where('status', 'heard')->count(),
            '_postponed' => $hearings->where('status', 'postponed')->count(),
            '_next_hearing' => $next?->occurs_on?->toDateString(),
            '_next_courtroom' => $next?->value('courtroom'),
            '_last_outcome' => $last?->value('outcome'),
            '_days_pending' => in_array($record->status, self::OPEN, true) ? (int) $record->occurs_on->diffInDays(today()) : null,
            '_decided_on' => in_array($record->status, ['decided', 'appealed', 'closed'], true) ? ($record->value('_decided_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'hearings') {
            return;
        }

        $case = $this->parent($record, 'case');
        if ($case && $record->status === 'heard' && in_array($case->status, ['filed', 'pending'], true)) {
            $case->update(['status' => 'part_heard']);
        } elseif ($case && $record->status === 'scheduled' && $case->status === 'filed') {
            $case->update(['status' => 'pending']);
        } else {
            $this->recalculate($case);
        }
        $this->recalculate($this->previousParent($record, 'case'));
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'hearings') {
            $this->recalculate($this->parent($record, 'case'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'hearings') {
            return $record->status === 'scheduled'
                ? [
                    'heard' => ['label' => 'Heard', 'icon' => 'gavel', 'fields' => [['name' => 'outcome', 'label' => 'Outcome', 'type' => 'textarea', 'value' => $record->value('outcome')]]],
                    'postpone' => ['label' => 'Postpone', 'icon' => 'calendar-clock', 'fields' => [['name' => 'occurs_on', 'label' => 'New date', 'type' => 'date', 'value' => $record->occurs_on?->copy()->addDays(14)->toDateString()]]],
                    'strike_off' => ['label' => 'Strike off', 'icon' => 'x', 'confirm' => 'Strike this hearing off the roll?'],
                ]
                : [];
        }

        $setDown = ['label' => 'Set down', 'icon' => 'calendar-plus', 'fields' => [
            ['name' => 'title', 'label' => 'Purpose', 'type' => 'text', 'value' => 'Mention'],
            ['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->addDays(14)->toDateString()],
            ['name' => 'time', 'label' => 'Time', 'type' => 'time', 'value' => '09:00'],
            ['name' => 'courtroom', 'label' => 'Courtroom', 'type' => 'text'],
        ]];
        $decide = ['label' => 'Decided', 'icon' => 'scale'];
        $close = ['label' => 'Close file', 'icon' => 'folder-check', 'confirm' => 'Close '.$record->title.'?'];

        return match ($record->status) {
            'filed' => ['set_down' => $setDown, 'close' => $close],
            'pending', 'part_heard' => ['set_down' => $setDown, 'reserve_judgment' => ['label' => 'Reserve judgment', 'icon' => 'hourglass'], 'decide' => $decide],
            'judgment_reserved' => ['decide' => $decide, 'set_down' => $setDown],
            'decided' => ['appeal' => ['label' => 'Appealed', 'icon' => 'arrow-up'], 'close' => $close],
            'appealed' => ['close' => $close],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'folder-open']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'heard':
                $outcome = $request->validate(['outcome' => ['nullable', 'string']])['outcome'] ?? $record->value('outcome');
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The hearing day has not come yet.']);
                }
                $record->update(['status' => 'heard', 'data' => [...$record->data, 'outcome' => $outcome]]);

                return $record->title.' heard'.(filled($outcome) ? ': '.$outcome : '').'.';
            case 'postpone':
                $day = Carbon::parse($request->validate(['occurs_on' => ['required', 'date', 'after:today']])['occurs_on']);
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The hearing day has not come yet.']);
                }
                if ($clash = $this->clash($day, (string) $record->value('courtroom'), (string) $record->value('time'), $record->id)) {
                    throw ValidationException::withMessages(['occurs_on' => $record->value('courtroom').' already has '.$clash->title.' at '.$record->value('time').' that day.']);
                }
                $record->update(['status' => 'postponed']);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'hearings', 'title' => $record->title, 'status' => 'scheduled',
                    'occurs_on' => $day, 'assignee_id' => $record->assignee_id,
                    'data' => ['case' => $record->value('case'), 'time' => $record->value('time'), 'courtroom' => $record->value('courtroom')],
                ]);

                return $record->title.' postponed to '.$day->format('d M Y').'.';
            case 'strike_off':
                $record->update(['status' => 'struck_off']);

                return $record->title.' struck off the roll.';
            case 'set_down':
                $input = $request->validate(['title' => ['required', 'string'], 'occurs_on' => ['required', 'date', 'after_or_equal:today'], 'time' => ['nullable', 'string'], 'courtroom' => ['nullable', 'string']]);
                $day = Carbon::parse($input['occurs_on']);
                if (filled($input['courtroom'] ?? null) && filled($input['time'] ?? null) && ($clash = $this->clash($day, $input['courtroom'], $input['time']))) {
                    throw ValidationException::withMessages(['courtroom' => $input['courtroom'].' already has '.$clash->title.' at '.$input['time'].' that day.']);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'hearings', 'title' => $input['title'], 'status' => 'scheduled',
                    'occurs_on' => $day, 'assignee_id' => $record->assignee_id,
                    'data' => ['case' => $record->id, 'time' => $input['time'] ?? null, 'courtroom' => $input['courtroom'] ?? null],
                ]);

                return $input['title'].' in '.$record->title.' set down for '.$day->format('d M Y').(filled($input['time'] ?? null) ? ' at '.$input['time'] : '').(filled($input['courtroom'] ?? null) ? ' in '.$input['courtroom'] : '').'.';
            case 'reserve_judgment':
                $record->update(['status' => 'judgment_reserved']);

                return 'Judgment reserved in '.$record->title.'.';
            case 'decide':
                $record->update(['status' => 'decided']);

                return $record->title.' decided.';
            case 'appeal':
                $record->update(['status' => 'appealed']);

                return $record->title.' taken on appeal.';
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
        }

        $record->update(['status' => 'pending']);

        return $record->title.' reopened.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'cases') {
            return [];
        }

        $hearings = $this->linked('hearings', 'case', $record)->orderByDesc('occurs_on')->get();
        $types = $this->app->entities['cases']->field('type')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Case file', 'icon' => 'scale', 'stats' => [
                ['label' => 'Case number', 'value' => $record->value('case_number') ?: '—'],
                ['label' => 'Court', 'value' => (string) ($record->value('court') ?: '—')],
                ['label' => 'Type', 'value' => $types[$record->value('type')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('type')))],
                ['label' => 'Presiding officer', 'value' => (string) ($record->value('judge') ?: '—')],
                ['label' => 'Hearings', 'value' => (int) $record->value('_heard').' heard, '.(int) $record->value('_postponed').' postponed'],
                ['label' => 'Next hearing', 'value' => $record->value('_next_hearing') ? Carbon::parse($record->value('_next_hearing'))->format('d M Y').($record->value('_next_courtroom') ? ' · '.$record->value('_next_courtroom') : '') : 'Not set down', 'tone' => in_array($record->status, self::OPEN, true) && ! $record->value('_next_hearing') ? 'warning' : null],
                ['label' => 'Days since filing', 'value' => $record->value('_days_pending') === null ? '—' : (string) (int) $record->value('_days_pending')],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Hearings', 'icon' => 'gavel', 'empty' => 'No hearings set down.',
                'rows' => $hearings->take(15)->map(fn (Record $hearing) => [
                    'label' => $hearing->title, 'sub' => $hearing->occurs_on?->format('d M Y').($hearing->value('time') ? ' '.$hearing->value('time') : '').($hearing->value('courtroom') ? ' · '.$hearing->value('courtroom') : '').($hearing->value('outcome') ? ' · '.str($hearing->value('outcome'))->limit(50) : ''), 'value' => ucfirst(str_replace('_', ' ', $hearing->status)), 'href' => $hearing->url(), 'tone' => $hearing->status === 'heard' ? 'success' : ($hearing->status === 'scheduled' ? null : 'warning'),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $cases = $this->records('cases')->get();
        $hearings = $this->records('hearings')->get();
        $open = $cases->whereIn('status', self::OPEN);
        $today = $hearings->filter(fn (Record $hearing) => $hearing->status === 'scheduled' && $hearing->occurs_on?->isToday())->sortBy(fn (Record $hearing) => (string) $hearing->value('time'));
        $week = $hearings->filter(fn (Record $hearing) => $hearing->status === 'scheduled' && $hearing->occurs_on?->between(today(), today()->addDays(7)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Court', 'icon' => 'gavel', 'stats' => [
                ['label' => 'Open cases', 'value' => (string) $open->count()],
                ['label' => 'Judgment reserved', 'value' => (string) $open->where('status', 'judgment_reserved')->count()],
                ['label' => 'Not set down', 'value' => (string) $open->filter(fn (Record $case) => blank($case->value('_next_hearing')) && $case->status !== 'judgment_reserved')->count(), 'tone' => 'warning'],
                ['label' => "Today's cause list", 'value' => (string) $today->count()],
                ['label' => 'This week', 'value' => (string) $week->count()],
                ['label' => 'Postponed this month', 'value' => (string) $hearings->filter(fn (Record $hearing) => $hearing->status === 'postponed' && $hearing->occurs_on?->isCurrentMonth())->count()],
                ['label' => 'Decided this year', 'value' => (string) $cases->filter(fn (Record $case) => filled($case->value('_decided_on')) && Carbon::parse($case->value('_decided_on'))->isCurrentYear())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's cause list", 'icon' => 'list-ordered', 'empty' => 'Nothing on the roll today.',
                'rows' => $today->take(20)->map(fn (Record $hearing) => [
                    'label' => ($cases->firstWhere('id', (int) $hearing->value('case'))?->title ?? 'Case').' · '.$hearing->title, 'sub' => ($cases->firstWhere('id', (int) $hearing->value('case'))?->value('case_number') ?: '').($hearing->value('courtroom') ? ' · '.$hearing->value('courtroom') : ''), 'value' => (string) ($hearing->value('time') ?: 'Time not set'), 'href' => $hearing->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $cases = $this->records('cases')->get();
        $types = $this->app->entities['cases']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [
            $label, $cases->where('data.type', $type)->whereIn('status', self::OPEN)->count(), $cases->where('data.type', $type)->where('status', 'decided')->count(), $cases->where('data.type', $type)->where('status', 'appealed')->count(), $cases->where('data.type', $type)->whereIn('status', self::OPEN)->isEmpty() ? '—' : round($cases->where('data.type', $type)->whereIn('status', self::OPEN)->avg(fn (Record $case) => (int) $case->value('_days_pending'))),
        ])->values()->all();
        $byCourt = $cases->groupBy(fn (Record $case) => $case->value('court') ?: 'Unspecified')->sortKeys()->map(fn ($group, $court) => [
            $court, $group->whereIn('status', self::OPEN)->count(), $group->where('status', 'judgment_reserved')->count(), $group->whereIn('status', ['decided', 'appealed', 'closed'])->count(),
        ])->values()->all();

        $hearings = $this->dated('hearings', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($hearings) {
            $group = $hearings->filter(fn (Record $hearing) => $hearing->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'heard')->count(), $group->where('status', 'postponed')->count(), $group->where('status', 'struck_off')->count()];
        })->values()->all();
        $byCourtroom = $hearings->groupBy(fn (Record $hearing) => $hearing->value('courtroom') ?: 'Unspecified')->sortKeys()->map(fn ($group, $courtroom) => [
            $courtroom, $group->count(), $group->where('status', 'heard')->count(), $group->where('status', 'postponed')->count(),
        ])->values()->all();

        return [
            ['title' => 'Cases by type', 'columns' => ['Type', 'Open', 'Decided', 'Appealed', 'Average days pending'], 'rows' => $byType],
            ['title' => 'Cases by court', 'columns' => ['Court', 'Open', 'Judgment reserved', 'Concluded'], 'rows' => $byCourt],
            ['title' => 'Hearings by month', 'columns' => ['Month', 'Set down', 'Heard', 'Postponed', 'Struck off'], 'rows' => $byMonth],
            ['title' => 'Hearings by courtroom', 'columns' => ['Courtroom', 'Hearings', 'Heard', 'Postponed'], 'rows' => $byCourtroom],
        ];
    }

    private function clash(Carbon $day, string $courtroom, string $time, ?int $except = null): ?Record
    {
        if ($courtroom === '' || $time === '') {
            return null;
        }

        return $this->records('hearings')->where('status', 'scheduled')->whereDate('occurs_on', $day)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $hearing) => strcasecmp(trim((string) $hearing->value('courtroom')), trim($courtroom)) === 0 && substr((string) $hearing->value('time'), 0, 5) === substr($time, 0, 5));
    }
}
