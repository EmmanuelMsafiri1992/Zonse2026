<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Leave: a request ends on or after it starts and asks for no more days than it spans, one
 * person's leave cannot overlap, and annual leave is capped at the yearly entitlement. Approved
 * leave becomes taken once its last day has passed.
 */
class LeaveLogic extends AppLogic
{
    public const ANNUAL_DAYS = 21;

    public const BOOKED = ['requested', 'approved', 'taken'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $days = (float) ($data['days'] ?? 0);
        $errors = [];

        if ($days <= 0) {
            $errors['data.days'] = 'Enter how many days are asked for.';
        }
        if (blank($payload['occurs_on'] ?? null)) {
            return $errors + ['occurs_on' => 'Set the first day of leave.'];
        }

        $from = Carbon::parse($payload['occurs_on']);
        $to = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : $from->copy();
        if ($to->lt($from)) {
            return $errors + ['due_on' => 'The last day cannot be before the first.'];
        }
        if ($days > (int) $from->diffInDays($to) + 1) {
            $errors['data.days'] = 'The dates only cover '.((int) $from->diffInDays($to) + 1).' days.';
        }

        if (! in_array($payload['status'], self::BOOKED, true)) {
            return $errors;
        }

        $others = $this->records('requests')->where('title', $payload['title'])->whereIn('status', self::BOOKED)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
        $clash = $others->first(fn (Record $other) => $other->occurs_on && $other->occurs_on->lte($to) && ($other->due_on ?? $other->occurs_on)->gte($from));
        if ($clash) {
            $errors['occurs_on'] = $payload['title'].' already has leave from '.$clash->occurs_on->format('d M').' to '.($clash->due_on ?? $clash->occurs_on)->format('d M Y').'.';
        }

        if (($data['type'] ?? null) === 'annual' && $days > 0) {
            $used = $this->annualDaysUsed($payload['title'], $from->year, $existing);
            if ($used + $days > self::ANNUAL_DAYS) {
                $errors['data.days'] = $payload['title'].' has '.max(0, self::ANNUAL_DAYS - $used).' annual days left in '.$from->year.'.';
            }
        }

        return $errors;
    }

    /** Annual days booked (requested, approved or taken) by one person in a year. */
    public function annualDaysUsed(string $employee, int $year, ?Record $except = null): float
    {
        return (float) $this->records('requests')->where('title', $employee)->where('data->type', 'annual')->whereIn('status', self::BOOKED)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->get()
            ->filter(fn (Record $request) => $request->occurs_on?->year === $year)->sum(fn (Record $request) => $this->number($request, 'days'));
    }

    public function saving(Record $record): void
    {
        if (! $record->due_on && $record->occurs_on) {
            $record->due_on = $record->occurs_on->copy();
        }
    }

    public function daily(Workspace $workspace): int
    {
        $taken = 0;
        foreach ($this->records('requests')->where('status', 'approved')->whereDate('due_on', '<', today())->get() as $request) {
            $request->update(['status' => 'taken']);
            $taken++;
        }

        return $taken;
    }

    public function actions(Record $record): array
    {
        if ($record->status !== 'requested') {
            return [];
        }

        return [
            'approve' => ['label' => 'Approve', 'icon' => 'check', 'confirm' => 'Approve '.$this->number($record, 'days').' days for '.$record->title.'?'],
            'decline' => ['label' => 'Decline', 'icon' => 'x', 'confirm' => 'Decline this request?'],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $status = ['approve' => 'approved', 'decline' => 'declined'][$action] ?? abort(404);
        $record->update(['status' => $status]);

        return $record->title.'\'s leave is '.$status.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->value('type') !== 'annual' || ! $record->occurs_on) {
            return [];
        }

        $used = $this->annualDaysUsed($record->title, $record->occurs_on->year);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Annual leave '.$record->occurs_on->year, 'icon' => 'calendar-days', 'stats' => [
            ['label' => 'Entitlement', 'value' => self::ANNUAL_DAYS.' days'],
            ['label' => 'Booked', 'value' => $used.' days'],
            ['label' => 'Left', 'value' => max(0, self::ANNUAL_DAYS - $used).' days', 'tone' => $used >= self::ANNUAL_DAYS ? 'warning' : 'success'],
        ]]]];
    }

    public function homeCards(): array
    {
        $weekStart = today()->startOfWeek();
        $weekEnd = today()->endOfWeek();
        $away = $this->records('requests')->whereIn('status', ['approved', 'taken'])->whereDate('occurs_on', '<=', $weekEnd)->whereDate('due_on', '>=', $weekStart)->orderBy('occurs_on')->get();
        $waiting = $this->records('requests')->where('status', 'requested')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Away this week', 'icon' => 'plane', 'empty' => 'Everyone is in this week.',
                'rows' => $away->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => ucfirst((string) $request->value('type')), 'value' => $request->occurs_on->format('d M').' – '.$request->due_on?->format('d M'), 'href' => $request->url(),
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for a decision', 'icon' => 'clock', 'empty' => 'No requests waiting.',
                'rows' => $waiting->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => ucfirst((string) $request->value('type')).' · '.$this->number($request, 'days').' days', 'value' => $request->occurs_on?->format('d M') ?? '', 'href' => $request->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $year = $to->year;
        $balances = $this->records('requests')->pluck('title')->unique()->sort()->values()->map(fn (string $employee) => [
            $employee, self::ANNUAL_DAYS, $this->annualDaysUsed($employee, $year), max(0, self::ANNUAL_DAYS - $this->annualDaysUsed($employee, $year)),
        ])->all();

        $requests = $this->dated('requests', $from, $to)->whereIn('status', ['approved', 'taken'])->get();
        $types = $requests->groupBy(fn (Record $request) => (string) $request->value('type'))->sortKeys()->map(fn ($group, $type) => [
            ucfirst($type), $group->count(), $group->sum(fn (Record $request) => $this->number($request, 'days')),
        ])->values()->all();

        return [
            ['title' => 'Annual leave balances '.$year, 'columns' => ['Employee', 'Entitlement', 'Booked', 'Left'], 'rows' => $balances],
            ['title' => 'Days by type', 'columns' => ['Type', 'Requests', 'Days'], 'rows' => $types],
        ];
    }
}
