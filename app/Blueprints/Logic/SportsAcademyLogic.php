<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Sports academy: athletes join active squads and must fit the squad's age group, sessions are
 * held for active squads with attendance no bigger than the squad, and a session is held only
 * once its day has come. Each squad tracks its athletes, injuries, sessions, attendance and the
 * monthly fees it brings in, and a squad with athletes cannot be switched off.
 */
class SportsAcademyLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'squads') {
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The monthly fee cannot be negative.';
            }
            if ($payload['status'] === 'inactive' && $existing && ($active = $this->linked('athletes', 'squad', $existing)->whereIn('status', ['active', 'injured'])->count()) > 0) {
                $errors['status'] = $active.' athletes are still in this squad; move them first.';
            }

            return $errors;
        }

        $squad = ! empty($data['squad']) ? $this->records('squads')->find($data['squad']) : null;
        $joining = $squad && (! $existing || (int) $existing->value('squad') !== $squad->id);

        if ($entity->key === 'athletes') {
            if ($joining && $squad->status !== 'active' && $payload['status'] !== 'left') {
                $errors['data.squad'] = $squad->title.' is not active.';
            }
            if (filled($data['date_of_birth'] ?? null)) {
                $dob = Carbon::parse($data['date_of_birth']);
                $age = (int) $dob->diffInYears(today());
                if ($dob->gt(today())) {
                    $errors['data.date_of_birth'] = 'The date of birth is in the future.';
                } elseif ($squad && ($limit = $this->ageLimit($squad)) && $age >= $limit && $payload['status'] !== 'left') {
                    $errors['data.date_of_birth'] = 'Aged '.$age.', too old for '.$squad->value('age_group').'.';
                }
            }

            return $errors;
        }

        if ($joining && $squad->status !== 'active' && $payload['status'] !== 'cancelled') {
            $errors['data.squad'] = $squad->title.' is not active.';
        }
        if (filled($data['attendance'] ?? null)) {
            $size = $squad ? $this->linked('athletes', 'squad', $squad)->where('status', 'active')->count() : null;
            if ((int) $data['attendance'] < 0) {
                $errors['data.attendance'] = 'Attendance cannot be negative.';
            } elseif ($size !== null && (int) $data['attendance'] > $size) {
                $errors['data.attendance'] = 'Only '.$size.' athletes are active in '.$squad->title.'.';
            }
        }
        if ($payload['status'] === 'held' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['status'] = 'A session is held only once its day has come.';
        }

        return $errors;
    }

    /** The upper age for an age group such as "U14" or "Under 12", or null when there is none. */
    protected function ageLimit(Record $squad): ?int
    {
        return preg_match('/(\d{1,2})/', (string) $squad->value('age_group'), $match) ? (int) $match[1] : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'athletes') {
            $this->put($record, ['_age' => filled($record->value('date_of_birth')) ? (int) Carbon::parse($record->value('date_of_birth'))->diffInYears(today()) : null]);

            return;
        }

        if ($record->entity === 'sessions') {
            $squad = $this->parent($record, 'squad');
            $record->occurs_on ??= today();
            $record->assignee_id ??= $squad?->value('coach') ? (int) $squad->value('coach') : null;
            $size = $squad ? $this->linked('athletes', 'squad', $squad)->where('status', 'active')->count() : 0;
            $this->put($record, [
                '_squad_size' => $size,
                '_attendance_pct' => $record->status === 'held' && $size > 0 ? (int) round((int) $record->value('attendance') / $size * 100) : null,
            ]);

            return;
        }

        $athletes = $record->exists ? $this->linked('athletes', 'squad', $record)->get() : collect();
        $sessions = $record->exists ? $this->linked('sessions', 'squad', $record)->get() : collect();
        $held = $sessions->where('status', 'held')->sortByDesc('occurs_on');
        $this->put($record, [
            '_athletes' => $athletes->where('status', 'active')->count(),
            '_injured' => $athletes->where('status', 'injured')->count(),
            '_sessions_month' => $sessions->filter(fn (Record $session) => $session->status === 'held' && $session->occurs_on?->isCurrentMonth())->count(),
            '_average_attendance' => $held->filter(fn (Record $session) => $session->value('_attendance_pct') !== null)->isEmpty() ? null : (int) round($held->filter(fn (Record $session) => $session->value('_attendance_pct') !== null)->avg(fn (Record $session) => (int) $session->value('_attendance_pct'))),
            '_monthly_fees' => round((float) $record->amount * $athletes->whereIn('status', ['active', 'injured'])->count(), 2),
            '_last_session' => $held->first()?->occurs_on?->toDateString(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'squads') {
            $this->recalculate($this->parent($record, 'squad'));
            $this->recalculate($this->previousParent($record, 'squad'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'squads') {
            $this->recalculate($this->parent($record, 'squad'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'squads') {
            return $record->status === 'active'
                ? [
                    'schedule_session' => ['label' => 'Schedule session', 'icon' => 'calendar-plus', 'fields' => [
                        ['name' => 'focus', 'label' => 'Focus', 'type' => 'text', 'value' => 'Training'],
                        ['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->toDateString()],
                        ['name' => 'start_time', 'label' => 'Start', 'type' => 'time', 'value' => '16:00'],
                    ]],
                    'deactivate' => ['label' => 'Deactivate', 'icon' => 'pause', 'confirm' => 'Deactivate this squad? It must have no athletes.'],
                ]
                : ['activate' => ['label' => 'Activate', 'icon' => 'play']];
        }

        if ($record->entity === 'athletes') {
            $squads = $this->records('squads')->where('status', 'active')->whereKeyNot((int) $record->value('squad'))->orderBy('title')->pluck('title', 'id')->all();
            $move = ['label' => 'Move squad', 'icon' => 'arrow-right-left', 'fields' => [
                ['name' => 'squad', 'label' => 'New squad', 'type' => 'select', 'options' => $squads, 'value' => array_key_first($squads)],
            ]];

            return match ($record->status) {
                'active' => ['injure' => ['label' => 'Injured', 'icon' => 'bandage'], 'move' => $move, 'leave' => ['label' => 'Left academy', 'icon' => 'log-out', 'confirm' => $record->title.' has left the academy?']],
                'injured' => ['recover' => ['label' => 'Fit again', 'icon' => 'heart-pulse'], 'leave' => ['label' => 'Left academy', 'icon' => 'log-out', 'confirm' => $record->title.' has left the academy?']],
                default => ['rejoin' => ['label' => 'Rejoin', 'icon' => 'user-plus', 'fields' => [
                    ['name' => 'squad', 'label' => 'Squad', 'type' => 'select', 'options' => $this->records('squads')->where('status', 'active')->orderBy('title')->pluck('title', 'id')->all()],
                ]]],
            };
        }

        return $record->status === 'planned'
            ? [
                'hold' => ['label' => 'Mark held', 'icon' => 'check', 'fields' => [
                    ['name' => 'attendance', 'label' => 'Athletes present', 'type' => 'number', 'value' => (int) $record->value('_squad_size')],
                    ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $record->value('notes')],
                ]],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this session?'],
            ]
            : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'schedule_session':
                $input = $request->validate(['focus' => ['required', 'string'], 'occurs_on' => ['required', 'date', 'after_or_equal:today'], 'start_time' => ['nullable', 'string']]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'branch_id' => $record->branch_id, 'blueprint' => $record->blueprint, 'entity' => 'sessions',
                    'title' => $input['focus'], 'status' => 'planned', 'occurs_on' => Carbon::parse($input['occurs_on']),
                    'data' => ['squad' => $record->id, 'start_time' => $input['start_time'] ?? null],
                ]);

                return 'Session on '.Carbon::parse($input['occurs_on'])->format('d M').' scheduled for '.$record->title.'.';
            case 'deactivate':
                $active = $this->linked('athletes', 'squad', $record)->whereIn('status', ['active', 'injured'])->count();
                if ($active > 0) {
                    throw ValidationException::withMessages(['status' => $active.' athletes are still in this squad; move them first.']);
                }
                $record->update(['status' => 'inactive']);

                return $record->title.' is inactive.';
            case 'activate':
                $record->update(['status' => 'active']);

                return $record->title.' is active.';
            case 'injure':
                $record->update(['status' => 'injured']);

                return $record->title.' is injured.';
            case 'recover':
                $record->update(['status' => 'active']);

                return $record->title.' is fit again.';
            case 'leave':
                $record->update(['status' => 'left']);

                return $record->title.' has left the academy.';
            case 'move':
            case 'rejoin':
                $squadId = (int) $request->validate(['squad' => ['required', 'integer']])['squad'];
                $squad = $this->records('squads')->where('status', 'active')->find($squadId);
                if (! $squad) {
                    throw ValidationException::withMessages(['squad' => 'Choose an active squad.']);
                }
                if (filled($record->value('date_of_birth')) && ($limit = $this->ageLimit($squad)) && (int) Carbon::parse($record->value('date_of_birth'))->diffInYears(today()) >= $limit) {
                    throw ValidationException::withMessages(['squad' => $record->title.' is too old for '.$squad->value('age_group').'.']);
                }
                $record->update(['status' => 'active', 'data' => [...$record->data, 'squad' => $squad->id]]);

                return $record->title.($action === 'move' ? ' moved to ' : ' rejoined ').$squad->title.'.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return 'Session cancelled.';
        }

        $input = $request->validate(['attendance' => ['required', 'integer', 'min:0'], 'notes' => ['nullable', 'string']]);
        if ($record->occurs_on?->gt(today())) {
            throw ValidationException::withMessages(['status' => 'A session is held only once its day has come.']);
        }
        $size = (int) $record->value('_squad_size');
        if ((int) $input['attendance'] > $size) {
            throw ValidationException::withMessages(['attendance' => 'Only '.$size.' athletes are active in this squad.']);
        }
        $record->update(['status' => 'held', 'data' => [...$record->data, 'attendance' => (int) $input['attendance'], 'notes' => $input['notes'] ?? $record->value('notes')]]);

        return 'Session held with '.(int) $input['attendance'].' of '.$size.' athletes.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'squads') {
            return [];
        }

        $athletes = $this->linked('athletes', 'squad', $record)->whereIn('status', ['active', 'injured'])->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Squad', 'icon' => 'users', 'stats' => [
                ['label' => 'Athletes', 'value' => (string) (int) $record->value('_athletes')],
                ['label' => 'Injured', 'value' => (string) (int) $record->value('_injured'), 'tone' => (int) $record->value('_injured') > 0 ? 'warning' : null],
                ['label' => 'Sessions this month', 'value' => (string) (int) $record->value('_sessions_month')],
                ['label' => 'Attendance', 'value' => $record->value('_average_attendance') === null ? '—' : (int) $record->value('_average_attendance').'%'],
                ['label' => 'Monthly fees', 'value' => $this->money($this->number($record, '_monthly_fees'))],
                ['label' => 'Last session', 'value' => $record->value('_last_session') ? Carbon::parse($record->value('_last_session'))->format('d M Y') : 'None yet'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Athletes', 'icon' => 'user-round', 'empty' => 'No athletes in this squad.',
                'rows' => $athletes->take(15)->map(fn (Record $athlete) => [
                    'label' => $athlete->title, 'sub' => implode(' · ', array_filter([$athlete->value('position'), $athlete->value('_age') !== null ? 'Age '.$athlete->value('_age') : null])), 'value' => ucfirst($athlete->status), 'href' => $athlete->url(), 'tone' => $athlete->status === 'injured' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $squads = $this->records('squads')->get();
        $athletes = $this->records('athletes')->get();
        $sessions = $this->records('sessions')->get();
        $week = $sessions->filter(fn (Record $session) => $session->status === 'planned' && $session->occurs_on?->between(today()->startOfWeek(), today()->endOfWeek()))->sortBy(fn (Record $session) => $session->occurs_on->toDateString().' '.$session->value('start_time'));
        $overdue = $sessions->filter(fn (Record $session) => $session->status === 'planned' && $session->occurs_on?->lt(today()));
        $held = $sessions->filter(fn (Record $session) => $session->status === 'held' && $session->occurs_on?->isCurrentMonth() && $session->value('_attendance_pct') !== null);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Academy', 'icon' => 'trophy', 'stats' => [
                ['label' => 'Active squads', 'value' => (string) $squads->where('status', 'active')->count()],
                ['label' => 'Athletes', 'value' => (string) $athletes->where('status', 'active')->count()],
                ['label' => 'Injured', 'value' => (string) $athletes->where('status', 'injured')->count(), 'tone' => $athletes->where('status', 'injured')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Sessions this week', 'value' => (string) $week->count()],
                ['label' => 'Attendance this month', 'value' => $held->isEmpty() ? '—' : (int) round($held->avg(fn (Record $session) => (int) $session->value('_attendance_pct'))).'%'],
                ['label' => 'Monthly fees', 'value' => $this->money($squads->sum(fn (Record $squad) => (float) $squad->value('_monthly_fees')))],
                ['label' => 'Sessions not marked', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "This week's sessions", 'icon' => 'dumbbell', 'empty' => 'Nothing planned this week.',
                'rows' => $week->take(10)->map(fn (Record $session) => [
                    'label' => $session->title, 'sub' => $squads->firstWhere('id', (int) $session->value('squad'))?->title, 'value' => $session->occurs_on->format('D d M').($session->value('start_time') ? ' '.$session->value('start_time') : ''), 'href' => $session->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $squads = $this->records('squads')->get();
        $squadRows = $squads->sortBy('title')->map(fn (Record $squad) => [
            $squad->title, $squad->value('sport') ?: '—', $squad->value('age_group') ?: 'Open', ucfirst($squad->status), (int) $squad->value('_athletes'), (int) $squad->value('_injured'), $squad->value('_average_attendance') === null ? '—' : (int) $squad->value('_average_attendance').'%', $this->money((float) $squad->value('_monthly_fees')),
        ])->values()->all();

        $sessions = $this->dated('sessions', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($sessions) {
            $group = $sessions->filter(fn (Record $session) => $session->occurs_on?->format('Y-m') === $month);
            $held = $group->filter(fn (Record $session) => $session->status === 'held' && $session->value('_attendance_pct') !== null);

            return [$label, $group->count(), $group->where('status', 'held')->count(), $group->where('status', 'cancelled')->count(), $held->isEmpty() ? '—' : (int) round($held->avg(fn (Record $session) => (int) $session->value('_attendance_pct'))).'%'];
        })->values()->all();

        $athletes = $this->records('athletes')->get();
        $bySport = $squads->groupBy(fn (Record $squad) => $squad->value('sport') ?: 'No sport')->sortKeys()->map(fn ($group, $sport) => [
            $sport, $group->count(), $athletes->whereIn('data.squad', $group->pluck('id')->all())->where('status', 'active')->count(), $athletes->whereIn('data.squad', $group->pluck('id')->all())->where('status', 'injured')->count(), $this->money($group->sum(fn (Record $squad) => (float) $squad->value('_monthly_fees'))),
        ])->values()->all();

        return [
            ['title' => 'Squads', 'columns' => ['Squad', 'Sport', 'Age group', 'Status', 'Athletes', 'Injured', 'Attendance', 'Monthly fees'], 'rows' => $squadRows],
            ['title' => 'Sessions by month', 'columns' => ['Month', 'Planned', 'Held', 'Cancelled', 'Attendance'], 'rows' => $byMonth],
            ['title' => 'Athletes by sport', 'columns' => ['Sport', 'Squads', 'Active', 'Injured', 'Monthly fees'], 'rows' => $bySport],
        ];
    }
}
