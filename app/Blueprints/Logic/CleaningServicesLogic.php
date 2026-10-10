<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Cleaning & home services: jobs are only booked at active addresses, and a cleaner can't start two jobs at
 * the same time on the same day. A done job needs its hours; finishing a job at a weekly, fortnightly or
 * monthly address books the next visit. Scheduled jobs whose day has passed are marked missed each night.
 */
class CleaningServicesLogic extends AppLogic
{
    /**
     * Days until the next visit, by frequency.
     *
     * @var array<string, int>
     */
    public const REPEAT_DAYS = ['weekly' => 7, 'fortnightly' => 14];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'jobs') {
            return $errors;
        }
        if (! $existing && filled($data['customer'] ?? null) && ($address = $this->records('customers')->find($data['customer'])) && $address->status !== 'active') {
            $errors['data.customer'] = $address->title.' is '.$address->status.'.';
        }
        if (in_array($payload['status'], ['done', 'invoiced'], true) && (float) ($data['hours'] ?? 0) <= 0) {
            $errors['data.hours'] = 'Give the hours worked.';
        }
        if ($payload['status'] === 'scheduled' && filled($payload['assignee_id'] ?? null) && filled($data['start_time'] ?? null)) {
            $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on'])->toDateString() : today()->toDateString();
            $clash = $this->records('jobs')->whereIn('status', ['scheduled', 'in_progress'])->where('assignee_id', $payload['assignee_id'])->whereDate('occurs_on', $date)
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $job) => substr((string) $job->value('start_time'), 0, 5) === substr((string) $data['start_time'], 0, 5));
            if ($clash) {
                $errors['data.start_time'] = 'This cleaner already has '.$clash->title.' at '.substr((string) $data['start_time'], 0, 5).'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'jobs') {
            $record->occurs_on ??= today();
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('jobs')->where('status', 'scheduled')->whereDate('occurs_on', '<', today()->toDateString())->get()
            ->each(fn (Record $job) => $job->update(['status' => 'missed']))->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'customers') {
            return match ($record->status) {
                'active' => ['pause' => ['label' => 'Pause service', 'icon' => 'pause']],
                'paused' => ['resume' => ['label' => 'Resume service', 'icon' => 'play']],
                default => [],
            };
        }

        return match ($record->status) {
            'scheduled' => ['start' => ['label' => 'Start', 'icon' => 'play'], 'missed' => ['label' => 'Missed', 'icon' => 'calendar-x']],
            'in_progress' => ['done' => ['label' => 'Done', 'icon' => 'check', 'fields' => [['name' => 'hours', 'label' => 'Hours worked', 'type' => 'number', 'value' => $record->value('hours')]]]],
            'done' => ['invoice' => ['label' => 'Invoiced', 'icon' => 'receipt']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'pause':
                $record->update(['status' => 'paused']);

                return $record->title.' paused.';
            case 'resume':
                $record->update(['status' => 'active']);

                return $record->title.' resumed.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return $record->title.' started.';
            case 'missed':
                $record->update(['status' => 'missed']);

                return $record->title.' marked as missed.';
            case 'done':
                $hours = (float) $request->validate(['hours' => ['required', 'numeric', 'gt:0']], ['hours.required' => 'Give the hours worked.'])['hours'];
                $record->update(['status' => 'done', 'data' => [...$record->data, 'hours' => $hours]]);
                $next = $this->bookNext($record);

                return $record->title.' done'.($next ? '; next visit booked for '.$next->occurs_on->format('d M Y') : '').'.';
            default:
                $record->update(['status' => 'invoiced']);

                return $record->title.' invoiced.';
        }
    }

    /**
     * Book the next visit for a recurring address.
     */
    protected function bookNext(Record $job): ?Record
    {
        $address = $this->parent($job, 'customer');
        $frequency = $address?->value('frequency');
        if (! $address || $address->status !== 'active' || ! in_array($frequency, ['weekly', 'fortnightly', 'monthly'], true)) {
            return null;
        }
        $date = $frequency === 'monthly' ? $job->occurs_on->copy()->addMonthNoOverflow() : $job->occurs_on->copy()->addDays(self::REPEAT_DAYS[$frequency]);

        return Record::create([
            'workspace_id' => $job->workspace_id, 'blueprint' => $job->blueprint, 'entity' => 'jobs', 'title' => $job->title, 'status' => 'scheduled',
            'occurs_on' => $date, 'assignee_id' => $job->assignee_id, 'amount' => $job->amount,
            'data' => ['customer' => $address->id, 'service' => $job->value('service'), 'start_time' => $job->value('start_time')],
        ]);
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'customers') {
            return [];
        }
        $jobs = $this->linked('jobs', 'customer', $record)->orderByDesc('occurs_on')->get();
        $next = $jobs->where('status', 'scheduled')->sortBy('occurs_on')->first();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Visits', 'icon' => 'spray-can', 'stats' => [
            ['label' => 'Next visit', 'value' => $next?->occurs_on->format('d M Y') ?? '—'],
            ['label' => 'Visits done', 'value' => $jobs->whereIn('status', ['done', 'invoiced'])->count()],
            ['label' => 'Missed', 'value' => $jobs->where('status', 'missed')->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('jobs')->whereDate('occurs_on', today()->toDateString())->whereIn('status', ['scheduled', 'in_progress', 'done'])->get()->sortBy(fn (Record $job) => (string) $job->value('start_time'));
        $names = User::query()->whereIn('id', $today->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Today\'s jobs', 'icon' => 'spray-can', 'empty' => 'No jobs today.',
            'rows' => $today->map(fn (Record $job) => [
                'label' => $job->title, 'sub' => ($names[$job->assignee_id] ?? 'No cleaner').' · '.str_replace('_', ' ', $job->status),
                'value' => substr((string) $job->value('start_time'), 0, 5) ?: '—', 'href' => $job->url(), 'tone' => $job->status === 'done' ? 'success' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('jobs', $from, $to)->get();
        $names = User::query()->whereIn('id', $jobs->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [['title' => 'Jobs by cleaner', 'columns' => ['Cleaner', 'Jobs', 'Done', 'Missed', 'Hours', 'Value'], 'rows' => $jobs
            ->groupBy(fn (Record $job) => $names[$job->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(function ($group, string $cleaner) {
                $done = $group->whereIn('status', ['done', 'invoiced']);

                return [$cleaner, $group->count(), $done->count(), $group->where('status', 'missed')->count(), round($done->sum(fn (Record $job) => $this->number($job, 'hours')), 1), $this->money($done->sum('amount'))];
            })->values()->all()]];
    }
}
