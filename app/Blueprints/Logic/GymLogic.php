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
 * Gym & fitness: a membership renews one, three or twelve months after it starts, by plan; pay-as-you-go
 * members have no renewal date. Active memberships past their renewal date expire each night. Only active
 * members whose membership hasn't run out can check in or book personal training, and a trainer can't
 * have two sessions at the same time. Freezing a membership adds the frozen days back when it is unfrozen.
 */
class GymLogic extends AppLogic
{
    /**
     * Months each plan runs for.
     *
     * @var array<string, int>
     */
    public const PLAN_MONTHS = ['monthly' => 1, 'quarterly' => 3, 'annual' => 12];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'members') {
            return $errors;
        }
        if (! $existing && filled($data['member'] ?? null) && ($member = $this->records('members')->find($data['member'])) && ($problem = $this->cannotTrain($member))) {
            $errors['data.member'] = $problem;
        }
        if ($entity->key === 'pt_sessions' && $payload['status'] === 'booked' && filled($payload['assignee_id'] ?? null) && filled($data['start_time'] ?? null)) {
            $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on'])->toDateString() : today()->toDateString();
            $clash = $this->records('pt_sessions')->where('status', 'booked')->where('assignee_id', $payload['assignee_id'])->whereDate('occurs_on', $date)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $session) => substr((string) $session->value('start_time'), 0, 5) === substr((string) $data['start_time'], 0, 5));
            if ($clash) {
                $errors['data.start_time'] = 'The trainer already has '.$clash->title.' at '.substr((string) $data['start_time'], 0, 5).'.';
            }
        }

        return $errors;
    }

    /**
     * Why a member can't use the gym today, if there is a reason.
     */
    protected function cannotTrain(Record $member): ?string
    {
        return match (true) {
            $member->status !== 'active' => $member->title.'\'s membership is '.$member->status.'.',
            $member->due_on && $member->due_on->lt(today()) => $member->title.'\'s membership ran out on '.$member->due_on->format('d M Y').'.',
            default => null,
        };
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'check_ins') {
            if ($member = $this->parent($record, 'member')) {
                $record->title = $member->title;
            }
            if (blank($record->value('time_in'))) {
                $this->put($record, ['time_in' => now()->format('H:i')]);
            }

            return;
        }
        if ($record->entity !== 'members') {
            return;
        }
        $months = self::PLAN_MONTHS[$record->value('plan')] ?? null;
        if ($months && ! $record->due_on) {
            $record->due_on = $record->occurs_on->copy()->addMonthsNoOverflow($months);
        }
        if ($record->status === 'active' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('members')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $member) => $member->save())->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'pt_sessions') {
            return $record->status === 'booked' ? ['complete' => ['label' => 'Completed', 'icon' => 'check'], 'no_show' => ['label' => 'No-show', 'icon' => 'user-x']] : [];
        }
        if ($record->entity !== 'members') {
            return [];
        }
        $renew = isset(self::PLAN_MONTHS[$record->value('plan')]) ? ['renew' => ['label' => 'Renew', 'icon' => 'refresh-cw']] : [];

        return match ($record->status) {
            'active' => ['check_in' => ['label' => 'Check in', 'icon' => 'log-in'], ...$renew, 'freeze' => ['label' => 'Freeze', 'icon' => 'snowflake']],
            'frozen' => ['unfreeze' => ['label' => 'Unfreeze', 'icon' => 'sun']],
            'expired' => $renew,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'complete':
                $record->update(['status' => 'completed']);

                return $record->title.' completed.';
            case 'no_show':
                $record->update(['status' => 'no_show']);

                return $record->title.' marked as a no-show.';
            case 'check_in':
                if ($problem = $this->cannotTrain($record)) {
                    throw ValidationException::withMessages(['member' => $problem]);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'check_ins',
                    'title' => $record->title, 'status' => 'checked_in', 'occurs_on' => today(), 'data' => ['member' => $record->id, 'area' => 'gym_floor'],
                ]);

                return $record->title.' checked in.';
            case 'renew':
                $from = $record->due_on && $record->due_on->gte(today()) ? $record->due_on->copy() : today();
                $record->update(['status' => 'active', 'due_on' => $from->addMonthsNoOverflow(self::PLAN_MONTHS[$record->value('plan')])]);

                return $record->title.' renewed until '.$record->due_on->format('d M Y').'.';
            case 'freeze':
                $record->update(['status' => 'frozen', 'data' => [...$record->data, '_frozen_on' => today()->toDateString()]]);

                return $record->title.'\'s membership is frozen.';
            default:
                $days = filled($record->value('_frozen_on')) ? (int) Carbon::parse($record->value('_frozen_on'))->diffInDays(today()) : 0;
                $record->update(['status' => 'active', 'due_on' => $record->due_on?->copy()->addDays($days), 'data' => [...$record->data, '_frozen_on' => null]]);

                return $record->title.' is back'.($record->due_on ? '; renews on '.$record->due_on->format('d M Y') : '').'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'members') {
            return [];
        }
        $visits = $this->linked('check_ins', 'member', $record)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Attendance', 'icon' => 'log-in', 'stats' => [
            ['label' => 'Visits this month', 'value' => $visits->filter(fn (Record $visit) => $visit->occurs_on->isSameMonth(today()))->count()],
            ['label' => 'All visits', 'value' => $visits->count()],
            ['label' => 'Last visit', 'value' => $visits->max('occurs_on')?->format('d M Y') ?? '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $members = $this->records('members')->get();
        $renewing = $members->where('status', 'active')->filter(fn (Record $member) => $member->due_on && $member->due_on->lte(today()->addDays(7)))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'dumbbell', 'stats' => [
                ['label' => 'Check-ins', 'value' => $this->records('check_ins')->whereDate('occurs_on', today()->toDateString())->count()],
                ['label' => 'Active members', 'value' => $members->where('status', 'active')->count()],
                ['label' => 'PT sessions', 'value' => $this->records('pt_sessions')->where('status', 'booked')->whereDate('occurs_on', today()->toDateString())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals in the next 7 days', 'icon' => 'refresh-cw', 'empty' => 'No renewals due this week.',
                'rows' => $renewing->map(fn (Record $member) => ['label' => $member->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $member->value('plan'))), 'value' => $member->due_on->format('d M'), 'href' => $member->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $checkIns = $this->dated('check_ins', $from, $to)->get();

        return [
            ['title' => 'Check-ins by month', 'columns' => ['Month', 'Check-ins', 'Members who came'], 'rows' => collect($this->months($from, $to))
                ->map(function (string $label, string $month) use ($checkIns) {
                    $inMonth = $checkIns->filter(fn (Record $visit) => $visit->occurs_on->format('Y-m') === $month);

                    return [$label, $inMonth->count(), $inMonth->map(fn (Record $visit) => $visit->value('member'))->unique()->count()];
                })->values()->all()],
            ['title' => 'Members by plan', 'columns' => ['Plan', 'Members', 'Active', 'Active fees'], 'rows' => $this->records('members')->get()
                ->groupBy(fn (Record $member) => ucfirst(str_replace('_', ' ', (string) $member->value('plan'))))->sortKeys()
                ->map(fn ($group, string $plan) => [$plan, $group->count(), $group->where('status', 'active')->count(), $this->money($group->where('status', 'active')->sum('amount'))])
                ->values()->all()],
        ];
    }
}
