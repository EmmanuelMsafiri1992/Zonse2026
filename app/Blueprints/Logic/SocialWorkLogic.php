<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Social work case management: a case moves from intake through assessment to active, referred and
 * closed, carries a risk level and a review date, and counts its visits. High and urgent cases need
 * a review date, visits are logged only on open cases and completed only once they have happened,
 * a completed visit with a referral moves the case to referred, and a case closes only with no
 * visit still scheduled.
 */
class SocialWorkLogic extends AppLogic
{
    public const OPEN = ['intake', 'assessment', 'active', 'referred'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'cases') {
            if (filled($data['household_size'] ?? null) && (int) $data['household_size'] < 1) {
                $errors['data.household_size'] = 'A household has at least one person.';
            }
            if (in_array($data['risk'] ?? null, ['high', 'urgent'], true) && blank($payload['due_on'] ?? null) && $payload['status'] !== 'closed') {
                $errors['due_on'] = 'A '.$data['risk'].' risk case needs a review date.';
            }
            if ($payload['status'] === 'active' && blank($data['care_plan'] ?? null)) {
                $errors['data.care_plan'] = 'Write the care plan before the case is active.';
            }
            if ($payload['status'] === 'closed' && $existing && $existing->status !== 'closed' && ($open = $this->linked('visits', 'case', $existing)->where('status', 'scheduled')->count()) > 0) {
                $errors['status'] = $open.' visits are still scheduled.';
            }

            return $errors;
        }

        $case = ! empty($data['case']) ? $this->records('cases')->find($data['case']) : null;
        if ($case && $case->status === 'closed' && (! $existing || (int) $existing->value('case') !== $case->id)) {
            $errors['data.case'] = 'Case '.$case->title.' is closed.';
        }
        if ($payload['status'] === 'completed' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['status'] = 'The visit has not happened yet.';
        }
        if ($payload['status'] === 'missed' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['status'] = 'The visit is still to come.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'visits') {
            $record->occurs_on ??= today();
            $this->put($record, ['_completed_on' => $record->status === 'completed' ? ($record->value('_completed_on') ?? today()->toDateString()) : null]);

            return;
        }

        $visits = $record->exists ? $this->linked('visits', 'case', $record)->get() : collect();
        $completed = $visits->where('status', 'completed')->sortByDesc('occurs_on');
        $next = $visits->filter(fn (Record $visit) => $visit->status === 'scheduled' && $visit->occurs_on?->gte(today()))->sortBy('occurs_on')->first();
        $open = in_array($record->status, self::OPEN, true);
        $this->put($record, [
            '_visits' => $visits->count(),
            '_completed' => $completed->count(),
            '_missed' => $visits->where('status', 'missed')->count(),
            '_scheduled' => $visits->where('status', 'scheduled')->count(),
            '_referrals' => $completed->filter(fn (Record $visit) => filled($visit->value('referral')))->count(),
            '_last_visit' => $completed->first()?->occurs_on?->toDateString(),
            '_next_visit' => $next?->occurs_on?->toDateString(),
            '_days_open' => $record->occurs_on && $open ? (int) $record->occurs_on->diffInDays(today()) : null,
            '_review_overdue' => $open && $record->due_on?->lt(today()),
            '_closed_on' => $record->status === 'closed' ? ($record->value('_closed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'visits') {
            return;
        }

        $case = $this->parent($record, 'case');
        if ($case && $record->status === 'completed' && filled($record->value('referral')) && $case->status === 'active') {
            $case->update(['status' => 'referred']);
        } else {
            $this->recalculate($case);
        }
        $this->recalculate($this->previousParent($record, 'case'));
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'visits') {
            $this->recalculate($this->parent($record, 'case'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'visits') {
            return $record->status === 'scheduled'
                ? [
                    'complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [
                        ['name' => 'notes', 'label' => 'Notes', 'type' => 'textarea', 'value' => $record->value('notes')],
                        ['name' => 'referral', 'label' => 'Referred to', 'type' => 'text', 'value' => $record->value('referral')],
                    ]],
                    'miss' => ['label' => 'Missed', 'icon' => 'x'],
                ]
                : [];
        }

        $types = $this->app->entities['visits']->field('type')?->options ?? [];
        $schedule = ['label' => 'Schedule visit', 'icon' => 'calendar-plus', 'fields' => [
            ['name' => 'title', 'label' => 'Purpose', 'type' => 'text'],
            ['name' => 'occurs_on', 'label' => 'Date', 'type' => 'date', 'value' => today()->addDays(7)->toDateString()],
            ['name' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $types, 'value' => 'home_visit'],
        ]];
        $close = ['label' => 'Close case', 'icon' => 'folder-check', 'confirm' => 'Close case '.$record->title.'?'];

        return match ($record->status) {
            'intake' => ['assess' => ['label' => 'Start assessment', 'icon' => 'clipboard-list'], 'schedule_visit' => $schedule, 'close' => $close],
            'assessment' => ['activate' => ['label' => 'Open case', 'icon' => 'folder-heart', 'fields' => [
                ['name' => 'risk', 'label' => 'Risk', 'type' => 'select', 'options' => $this->app->entities['cases']->field('risk')?->options ?? [], 'value' => $record->value('risk') ?: 'medium'],
                ['name' => 'care_plan', 'label' => 'Care plan', 'type' => 'textarea', 'value' => $record->value('care_plan')],
                ['name' => 'due_on', 'label' => 'Review date', 'type' => 'date', 'value' => $record->due_on?->toDateString() ?? today()->addMonth()->toDateString()],
            ]], 'schedule_visit' => $schedule, 'close' => $close],
            'active', 'referred' => ['schedule_visit' => $schedule, 'review' => ['label' => 'Reviewed', 'icon' => 'calendar-check', 'fields' => [
                ['name' => 'risk', 'label' => 'Risk', 'type' => 'select', 'options' => $this->app->entities['cases']->field('risk')?->options ?? [], 'value' => $record->value('risk') ?: 'medium'],
                ['name' => 'due_on', 'label' => 'Next review', 'type' => 'date', 'value' => today()->addMonth()->toDateString()],
            ]], 'close' => $close],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'folder-open']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'complete':
                $input = $request->validate(['notes' => ['nullable', 'string'], 'referral' => ['nullable', 'string']]);
                if ($record->occurs_on?->gt(today())) {
                    throw ValidationException::withMessages(['status' => 'The visit has not happened yet.']);
                }
                $record->update(['status' => 'completed', 'data' => [...$record->data, 'notes' => $input['notes'] ?? $record->value('notes'), 'referral' => $input['referral'] ?? $record->value('referral')]]);

                return filled($input['referral'] ?? null) ? 'Visit completed; referred to '.$input['referral'].'.' : 'Visit completed.';
            case 'miss':
                $record->update(['status' => 'missed']);

                return 'Visit missed.';
            case 'assess':
                $record->update(['status' => 'assessment']);

                return 'Assessment of '.$record->title.' started.';
            case 'activate':
                $input = $request->validate(['risk' => ['required', 'string'], 'care_plan' => ['required', 'string'], 'due_on' => ['required', 'date', 'after:today']]);
                $record->update(['status' => 'active', 'due_on' => Carbon::parse($input['due_on']), 'data' => [...$record->data, 'risk' => $input['risk'], 'care_plan' => $input['care_plan']]]);

                return 'Case '.$record->title.' is active; review on '.Carbon::parse($input['due_on'])->format('d M Y').'.';
            case 'schedule_visit':
                $input = $request->validate(['title' => ['required', 'string'], 'occurs_on' => ['required', 'date', 'after_or_equal:today'], 'type' => ['nullable', 'string']]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'visits', 'title' => $input['title'], 'status' => 'scheduled',
                    'occurs_on' => Carbon::parse($input['occurs_on']), 'assignee_id' => $record->assignee_id,
                    'data' => ['case' => $record->id, 'type' => $input['type'] ?? 'home_visit', 'notes' => $input['title']],
                ]);

                return $input['title'].' scheduled for '.Carbon::parse($input['occurs_on'])->format('d M Y').'.';
            case 'review':
                $input = $request->validate(['risk' => ['required', 'string'], 'due_on' => ['required', 'date', 'after:today']]);
                $record->update(['status' => 'active', 'due_on' => Carbon::parse($input['due_on']), 'data' => [...$record->data, 'risk' => $input['risk']]]);

                return 'Case '.$record->title.' reviewed; next review on '.Carbon::parse($input['due_on'])->format('d M Y').'.';
            case 'close':
                $open = $this->linked('visits', 'case', $record)->where('status', 'scheduled')->count();
                if ($open > 0) {
                    throw ValidationException::withMessages(['status' => $open.' visits are still scheduled.']);
                }
                $record->update(['status' => 'closed']);

                return 'Case '.$record->title.' closed after '.(int) $record->fresh()->value('_completed').' visits.';
        }

        $record->update(['status' => 'active']);

        return 'Case '.$record->title.' reopened.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'cases') {
            return [];
        }

        $visits = $this->linked('visits', 'case', $record)->orderByDesc('occurs_on')->get();
        $categories = $this->app->entities['cases']->field('category')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Case', 'icon' => 'folder-heart', 'stats' => [
                ['label' => 'Category', 'value' => $categories[$record->value('category')] ?? ucfirst(str_replace('_', ' ', (string) $record->value('category')))],
                ['label' => 'Risk', 'value' => ucfirst((string) ($record->value('risk') ?: 'not set')), 'tone' => in_array($record->value('risk'), ['high', 'urgent'], true) ? 'danger' : null],
                ['label' => 'Days open', 'value' => $record->value('_days_open') === null ? '—' : (string) (int) $record->value('_days_open')],
                ['label' => 'Review', 'value' => $record->due_on?->format('d M Y') ?? 'Not set', 'tone' => $record->value('_review_overdue') ? 'danger' : null],
                ['label' => 'Visits', 'value' => (int) $record->value('_completed').' done, '.(int) $record->value('_missed').' missed'],
                ['label' => 'Last visit', 'value' => $record->value('_last_visit') ? Carbon::parse($record->value('_last_visit'))->format('d M Y') : 'None'],
                ['label' => 'Next visit', 'value' => $record->value('_next_visit') ? Carbon::parse($record->value('_next_visit'))->format('d M Y') : 'None'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Visits', 'icon' => 'notebook-pen', 'empty' => 'No visits yet.',
                'rows' => $visits->take(15)->map(fn (Record $visit) => [
                    'label' => $visit->title, 'sub' => $visit->occurs_on?->format('d M Y').' · '.ucfirst(str_replace('_', ' ', (string) ($visit->value('type') ?: 'visit'))).($visit->value('referral') ? ' · referred to '.$visit->value('referral') : ''), 'value' => ucfirst($visit->status), 'href' => $visit->url(), 'tone' => $visit->status === 'missed' ? 'danger' : ($visit->status === 'completed' ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $cases = $this->records('cases')->get();
        $open = $cases->whereIn('status', self::OPEN);
        $reviews = $open->filter(fn (Record $case) => $case->due_on?->lte(today()->addDays(7)))->sortBy('due_on');
        $visits = $this->records('visits')->get();
        $today = $visits->filter(fn (Record $visit) => $visit->status === 'scheduled' && $visit->occurs_on?->isToday());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Caseload', 'icon' => 'hand-helping', 'stats' => [
                ['label' => 'Open cases', 'value' => (string) $open->count()],
                ['label' => 'Urgent', 'value' => (string) $open->where('data.risk', 'urgent')->count(), 'tone' => $open->where('data.risk', 'urgent')->isNotEmpty() ? 'danger' : null],
                ['label' => 'High risk', 'value' => (string) $open->where('data.risk', 'high')->count()],
                ['label' => 'Reviews due', 'value' => (string) $reviews->count(), 'tone' => $reviews->isNotEmpty() ? 'warning' : null],
                ['label' => 'Visits today', 'value' => (string) $today->count()],
                ['label' => 'Missed this month', 'value' => (string) $visits->filter(fn (Record $visit) => $visit->status === 'missed' && $visit->occurs_on?->isCurrentMonth())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reviews due', 'icon' => 'calendar-clock', 'empty' => 'No reviews due this week.',
                'rows' => $reviews->take(10)->map(fn (Record $case) => [
                    'label' => $case->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $case->value('category'))).' · '.ucfirst((string) ($case->value('risk') ?: 'risk not set')), 'value' => $case->due_on->format('d M Y'), 'href' => $case->url(), 'tone' => $case->due_on->lt(today()) ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's visits", 'icon' => 'notebook-pen', 'empty' => 'No visits scheduled today.',
                'rows' => $today->take(10)->map(fn (Record $visit) => [
                    'label' => $visit->title, 'sub' => ($cases->firstWhere('id', (int) $visit->value('case'))?->title ?? '').' · '.ucfirst(str_replace('_', ' ', (string) ($visit->value('type') ?: 'visit'))), 'value' => 'Today', 'href' => $visit->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $cases = $this->records('cases')->get();
        $categories = $this->app->entities['cases']->field('category')?->options ?? [];
        $byCategory = collect($categories)->map(fn (string $label, string $category) => [
            $label, $cases->where('data.category', $category)->whereIn('status', self::OPEN)->count(), $cases->where('data.category', $category)->where('status', 'closed')->count(), $cases->where('data.category', $category)->sum(fn (Record $case) => (int) $case->value('_completed')),
        ])->values()->all();
        $risks = $this->app->entities['cases']->field('risk')?->options ?? [];
        $byRisk = collect($risks)->map(fn (string $label, string $risk) => [
            $label, $cases->where('data.risk', $risk)->whereIn('status', self::OPEN)->count(), $cases->where('data.risk', $risk)->filter(fn (Record $case) => $case->value('_review_overdue'))->count(),
        ])->values()->all();

        $visits = $this->dated('visits', $from, $to)->get();
        $types = $this->app->entities['visits']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [
            $label, $visits->where('data.type', $type)->where('status', 'completed')->count(), $visits->where('data.type', $type)->where('status', 'missed')->count(), $visits->where('data.type', $type)->where('status', 'scheduled')->count(),
        ])->values()->all();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($visits) {
            $group = $visits->filter(fn (Record $visit) => $visit->occurs_on?->format('Y-m') === $month);

            return [$label, $group->where('status', 'completed')->count(), $group->where('status', 'missed')->count(), $group->filter(fn (Record $visit) => $visit->status === 'completed' && filled($visit->value('referral')))->count()];
        })->values()->all();

        return [
            ['title' => 'Cases by category', 'columns' => ['Category', 'Open', 'Closed', 'Visits'], 'rows' => $byCategory],
            ['title' => 'Cases by risk', 'columns' => ['Risk', 'Open', 'Review overdue'], 'rows' => $byRisk],
            ['title' => 'Visits by type', 'columns' => ['Type', 'Completed', 'Missed', 'Scheduled'], 'rows' => $byType],
            ['title' => 'Visits by month', 'columns' => ['Month', 'Completed', 'Missed', 'Referrals'], 'rows' => $byMonth],
        ];
    }
}
