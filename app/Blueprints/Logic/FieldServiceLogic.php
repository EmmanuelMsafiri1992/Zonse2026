<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Field service & job cards: the priority sets how soon a job must be done (emergency the same day, high
 * the next day, normal in 3 days, low in 7) unless a date is given. A scheduled job needs a date and a
 * technician. A completed job needs the work done and the customer's sign-off. Only completed jobs can be
 * invoiced. The report shows how many jobs were done on time.
 */
class FieldServiceLogic extends AppLogic
{
    /**
     * Days allowed to finish a job, by priority.
     *
     * @var array<string, int>
     */
    public const RESPONSE_DAYS = ['emergency' => 0, 'high' => 1, 'normal' => 3, 'low' => 7];

    /**
     * Job statuses that are still open.
     *
     * @var list<string>
     */
    public const OPEN = ['new', 'scheduled', 'in_progress', 'on_hold'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($payload['status'] === 'scheduled') {
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Give the date of the visit.';
            }
            if (blank($payload['assignee_id'] ?? null)) {
                $errors['assignee_id'] = 'Give the technician.';
            }
        }
        if (in_array($payload['status'], ['completed', 'invoiced'], true)) {
            if (blank($data['work_done'] ?? null)) {
                $errors['data.work_done'] = 'Describe the work done.';
            }
            if (empty($data['customer_signed'])) {
                $errors['data.customer_signed'] = 'The customer must sign off before the job is completed.';
            }
        }
        if ($payload['status'] === 'invoiced' && $existing && ! in_array($existing->status, ['completed', 'invoiced'], true)) {
            $errors['status'] = 'Only completed jobs can be invoiced.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if (! $record->exists && ! $record->due_on) {
            $record->due_on = ($record->occurs_on && $record->occurs_on->gt(today()) ? $record->occurs_on->copy() : today())->addDays(self::RESPONSE_DAYS[$record->value('priority') ?: 'normal'] ?? 3);
        }
        $done = in_array($record->status, ['completed', 'invoiced'], true);
        $this->put($record, ['_completed_on' => $done ? ($record->value('_completed_on') ?? today()->toDateString()) : null]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'new' => ['schedule' => ['label' => 'Schedule', 'icon' => 'calendar', 'fields' => [['name' => 'date', 'label' => 'Visit date', 'type' => 'date', 'value' => today()->toDateString()]]]],
            'scheduled' => ['start' => ['label' => 'Start work', 'icon' => 'play']],
            'in_progress' => [
                'complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [
                    ['name' => 'work_done', 'label' => 'Work done', 'type' => 'textarea', 'value' => $record->value('work_done')],
                    ['name' => 'hours', 'label' => 'Labour hours', 'type' => 'number', 'value' => $record->value('hours')],
                    ['name' => 'customer_signed', 'label' => 'Customer signed off', 'type' => 'checkbox', 'value' => $record->value('customer_signed')],
                ]],
                'hold' => ['label' => 'On hold', 'icon' => 'pause'],
            ],
            'on_hold' => ['start' => ['label' => 'Resume', 'icon' => 'play']],
            'completed' => ['invoice' => ['label' => 'Invoiced', 'icon' => 'receipt']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'schedule':
                $date = Carbon::parse($request->validate(['date' => ['required', 'date', 'after_or_equal:today']])['date']);
                $record->update(['status' => 'scheduled', 'occurs_on' => $date, 'assignee_id' => $record->assignee_id ?? $request->user()->id]);

                return $record->title.' scheduled for '.$date->format('d M Y').' with '.User::query()->whereKey($record->assignee_id)->value('name').'.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return $record->title.' in progress.';
            case 'hold':
                $record->update(['status' => 'on_hold']);

                return $record->title.' on hold.';
            case 'complete':
                $input = $request->validate(['work_done' => ['nullable', 'string'], 'hours' => ['nullable', 'numeric', 'min:0'], 'customer_signed' => ['nullable']]);
                $work = trim((string) ($input['work_done'] ?? '')) ?: (string) $record->value('work_done');
                if ($work === '') {
                    throw ValidationException::withMessages(['work_done' => 'Describe the work done.']);
                }
                if (! $request->boolean('customer_signed')) {
                    throw ValidationException::withMessages(['customer_signed' => 'The customer must sign off before the job is completed.']);
                }
                $record->update(['status' => 'completed', 'data' => [...$record->data, 'work_done' => $work, 'hours' => (float) ($input['hours'] ?? $record->value('hours')), 'customer_signed' => true]]);

                return $record->title.' completed'.($record->due_on && $record->due_on->lt(today()) ? ', '.($late = (int) $record->due_on->diffInDays(today())).' '.str('day')->plural($late).' late' : ' on time').'.';
            default:
                $record->update(['status' => 'invoiced']);

                return $record->title.' invoiced.';
        }
    }

    public function homeCards(): array
    {
        $open = $this->records('jobs')->whereIn('status', self::OPEN)->get();
        $names = User::query()->whereIn('id', $open->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Jobs', 'icon' => 'clipboard-list', 'stats' => [
                ['label' => 'Open', 'value' => $open->count()],
                ['label' => 'Emergencies open', 'value' => $open->filter(fn (Record $job) => $job->value('priority') === 'emergency')->count()],
                ['label' => 'Not scheduled', 'value' => $open->where('status', 'new')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue jobs', 'icon' => 'alarm-clock', 'empty' => 'No overdue jobs.',
                'rows' => $open->filter(fn (Record $job) => $job->due_on && $job->due_on->lt(today()))->sortBy('due_on')
                    ->map(fn (Record $job) => ['label' => $job->title, 'sub' => $names[$job->assignee_id] ?? 'No technician', 'value' => 'Due '.$job->due_on->format('d M'), 'href' => $job->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Jobs by category', 'columns' => ['Category', 'Jobs', 'Completed', 'On time', 'Labour hours', 'Value'], 'rows' => $this->records('jobs')
            ->whereDate('created_at', '>=', $from->toDateString())->whereDate('created_at', '<=', $to->toDateString())->get()
            ->groupBy(fn (Record $job) => ucfirst(str_replace('_', ' ', (string) ($job->value('category') ?: 'other'))))->sortKeys()
            ->map(function ($group, string $category) {
                $done = $group->filter(fn (Record $job) => filled($job->value('_completed_on')));
                $onTime = $done->filter(fn (Record $job) => ! $job->due_on || Carbon::parse($job->value('_completed_on'))->lte($job->due_on));

                return [$category, $group->count(), $done->count(), $done->isNotEmpty() ? round($onTime->count() / $done->count() * 100).'%' : '—', round($group->sum(fn (Record $job) => $this->number($job, 'hours')), 1), $this->money($group->sum('amount'))];
            })->values()->all()]];
    }
}
