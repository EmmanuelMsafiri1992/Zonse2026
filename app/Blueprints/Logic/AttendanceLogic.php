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
 * Attendance & timesheets: each employee clocks in once a day, and clocking in after the start time
 * plus a grace period marks them late. Clocking out works out the hours on site. A timesheet covers a
 * Monday-to-Sunday week, one per employee, and can be filled from that week's clock-ins with anything
 * over 40 hours counted as overtime. Submitted timesheets are approved or rejected.
 */
class AttendanceLogic extends AppLogic
{
    /**
     * When the working day starts.
     */
    public const START_TIME = '08:00';

    /**
     * Minutes after the start time before a clock-in counts as late.
     */
    public const GRACE_MINUTES = 10;

    /**
     * Normal hours in a week; anything over is overtime.
     */
    public const NORMAL_WEEK_HOURS = 40;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'clockings') {
            $day = Carbon::parse($payload['occurs_on'] ?? today());
            $twin = $this->records('clockings')->whereDate('occurs_on', $day->toDateString())->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $clocking) => $this->sameEmployee($clocking, $payload['assignee_id'] ?? null, (string) $payload['title']));
            if ($twin) {
                $errors['title'] = $twin->title.' has already clocked in on '.$day->format('d M Y').'.';
            }
            if (filled($data['clock_in'] ?? null) && filled($data['clock_out'] ?? null) && $this->minutes((string) $data['clock_in'], (string) $data['clock_out']) <= 0) {
                $errors['data.clock_out'] = 'Clocking out must come after clocking in.';
            }

            return $errors;
        }
        foreach (['normal_hours', 'overtime_hours'] as $field) {
            if ((float) ($data[$field] ?? 0) < 0 || (float) ($data[$field] ?? 0) > 168) {
                $errors['data.'.$field] = 'Give between 0 and 168 hours.';
            }
        }
        if (filled($data['employee'] ?? null)) {
            $week = Carbon::parse($payload['occurs_on'] ?? today())->startOfWeek();
            $twin = $this->records('timesheets')->where('data->employee', (int) $data['employee'])->whereDate('occurs_on', $week->toDateString())
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($twin) {
                $errors['data.employee'] = User::query()->whereKey($data['employee'])->value('name').' already has a timesheet for the week of '.$week->format('d M Y').'.';
            }
        }

        return $errors;
    }

    /**
     * Whether a clock-in belongs to the employee: the same user, or the same name when there is no user.
     */
    protected function sameEmployee(Record $clocking, mixed $userId, string $name): bool
    {
        return filled($userId) ? (int) $clocking->assignee_id === (int) $userId : mb_strtolower(trim((string) $clocking->title)) === mb_strtolower(trim($name));
    }

    /**
     * Minutes between two "H:i" times on the same day.
     */
    protected function minutes(string $from, string $to): int
    {
        return (int) Carbon::createFromFormat('H:i', substr($from, 0, 5))->diffInMinutes(Carbon::createFromFormat('H:i', substr($to, 0, 5)), false);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'timesheets') {
            $record->occurs_on = $record->occurs_on->copy()->startOfWeek();
            $this->put($record, ['_total_hours' => round($this->number($record, 'normal_hours') + $this->number($record, 'overtime_hours'), 2)]);

            return;
        }
        if (in_array($record->status, ['present', 'late'], true) && filled($record->value('clock_in'))) {
            $record->status = $this->minutes(self::START_TIME, (string) $record->value('clock_in')) > self::GRACE_MINUTES ? 'late' : 'present';
            $this->put($record, ['_late_minutes' => max(0, $this->minutes(self::START_TIME, (string) $record->value('clock_in')))]);
        }
        if (filled($record->value('clock_in')) && filled($record->value('clock_out'))) {
            $this->put($record, ['_hours' => round(max(0, $this->minutes((string) $record->value('clock_in'), (string) $record->value('clock_out'))) / 60, 2)]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'clockings') {
            return in_array($record->status, ['present', 'late'], true) && blank($record->value('clock_out')) ? ['clock_out' => ['label' => 'Clock out', 'icon' => 'log-out']] : [];
        }

        return match ($record->status) {
            'draft' => ['fill' => ['label' => 'Fill from clock-ins', 'icon' => 'fingerprint'], 'submit' => ['label' => 'Submit', 'icon' => 'send']],
            'submitted' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'reject' => ['label' => 'Reject', 'icon' => 'x']],
            'rejected' => ['submit' => ['label' => 'Resubmit', 'icon' => 'send']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'clock_out':
                $out = $record->occurs_on && $record->occurs_on->lt(today()) ? '23:59' : now()->format('H:i');
                if ($this->minutes((string) $record->value('clock_in'), $out) <= 0) {
                    throw ValidationException::withMessages(['clock_out' => $record->title.' only clocked in at '.$record->value('clock_in').'.']);
                }
                $record->update(['data' => [...$record->data, 'clock_out' => $out]]);

                return $record->title.' clocked out at '.$out.' after '.$this->number($record, '_hours').' hours.';
            case 'fill':
                $clockings = $this->weekClockings($record);
                $hours = round($clockings->sum(fn (Record $clocking) => $this->number($clocking, '_hours')), 2);
                $normal = min($hours, self::NORMAL_WEEK_HOURS);
                $record->update(['data' => [...$record->data, 'normal_hours' => $normal, 'overtime_hours' => round($hours - $normal, 2)]]);

                return 'Filled from '.$clockings->count().' '.str('clock-in')->plural($clockings->count()).': '.$normal.' normal and '.round($hours - $normal, 2).' overtime hours.';
            case 'submit':
                $record->update(['status' => 'submitted']);

                return 'Timesheet submitted for approval.';
            case 'approve':
                $record->update(['status' => 'approved', 'data' => [...$record->data, '_approved_by' => $request->user()->id, '_approved_on' => today()->toDateString()]]);

                return 'Timesheet approved: '.$record->value('_total_hours').' hours.';
            default:
                $record->update(['status' => 'rejected']);

                return 'Timesheet sent back.';
        }
    }

    /**
     * The clock-ins in a timesheet's week by its employee.
     *
     * @return Collection<int, Record>
     */
    protected function weekClockings(Record $timesheet): Collection
    {
        return $this->records('clockings')->where('assignee_id', (int) $timesheet->value('employee'))
            ->whereBetween('occurs_on', [$timesheet->occurs_on->copy()->startOfDay(), $timesheet->occurs_on->copy()->endOfWeek()])->get();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'timesheets') {
            return [];
        }
        $clockings = $this->weekClockings($record);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Week of '.$record->occurs_on->format('d M'), 'icon' => 'calendar-range', 'stats' => [
            ['label' => 'Days clocked in', 'value' => $clockings->whereIn('status', ['present', 'late'])->count()],
            ['label' => 'Days late', 'value' => $clockings->where('status', 'late')->count(), 'tone' => $clockings->where('status', 'late')->isNotEmpty() ? 'warning' : null],
            ['label' => 'Clocked hours', 'value' => round($clockings->sum(fn (Record $clocking) => $this->number($clocking, '_hours')), 2)],
            ['label' => 'Claimed hours', 'value' => $this->number($record, '_total_hours')],
        ]]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('clockings')->whereDate('occurs_on', today()->toDateString())->get();
        $waiting = $this->records('timesheets')->where('status', 'submitted')->orderBy('occurs_on')->get();
        $names = User::query()->whereIn('id', $waiting->map(fn (Record $timesheet) => $timesheet->value('employee'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'fingerprint', 'stats' => [
                ['label' => 'Present', 'value' => $today->whereIn('status', ['present', 'late'])->count()],
                ['label' => 'Late', 'value' => $today->where('status', 'late')->count(), 'tone' => $today->where('status', 'late')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Absent', 'value' => $today->where('status', 'absent')->count()],
                ['label' => 'Still clocked in', 'value' => $today->whereIn('status', ['present', 'late'])->filter(fn (Record $clocking) => blank($clocking->value('clock_out')))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Timesheets to approve', 'icon' => 'calendar-range', 'empty' => 'No timesheets waiting.',
                'rows' => $waiting->map(fn (Record $timesheet) => ['label' => $names[$timesheet->value('employee')] ?? $timesheet->title, 'sub' => 'Week of '.$timesheet->occurs_on->format('d M'), 'value' => $timesheet->value('_total_hours').' h', 'href' => $timesheet->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $clockings = $this->dated('clockings', $from, $to)->get();

        return [['title' => 'Attendance by employee', 'columns' => ['Employee', 'Days present', 'Days late', 'Days absent', 'Days on leave', 'Hours on site'], 'rows' => $clockings
            ->groupBy(fn (Record $clocking) => trim((string) $clocking->title))->sortKeys()
            ->map(fn (Collection $group, string $name) => [
                $name, $group->whereIn('status', ['present', 'late'])->count(), $group->where('status', 'late')->count(), $group->where('status', 'absent')->count(),
                $group->where('status', 'on_leave')->count(), round($group->sum(fn (Record $clocking) => $this->number($clocking, '_hours')), 2),
            ])->values()->all()]];
    }
}
