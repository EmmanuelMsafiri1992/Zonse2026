<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Performance & OKRs: a review moves from self review to manager review to completed, and can't be
 * completed without a rating; nobody reviews themselves. An objective's status follows its progress
 * and due date — achieved at 100%, behind once overdue, at risk when due within two weeks and under
 * 75% — and is brought up to date each morning. Nobody gives 360 feedback about themselves.
 */
class PerformanceLogic extends AppLogic
{
    /**
     * Days before the due date that an objective short of AT_RISK_PROGRESS counts as at risk.
     */
    public const AT_RISK_DAYS = 14;

    public const AT_RISK_PROGRESS = 75;

    /**
     * The review stage after each one.
     *
     * @var array<string, string>
     */
    public const NEXT_STAGE = ['scheduled' => 'self_review', 'self_review' => 'manager_review', 'manager_review' => 'completed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'reviews') {
            if (filled($data['reviewer'] ?? null) && (int) $data['reviewer'] === (int) ($data['employee'] ?? 0)) {
                $errors['data.reviewer'] = 'Pick a reviewer other than the employee.';
            }
            if ($payload['status'] === 'completed' && blank($data['rating'] ?? null)) {
                $errors['data.rating'] = 'Give a rating before completing the review.';
            }
        }
        if ($entity->key === 'objectives' && ((float) ($data['progress'] ?? 0) < 0 || (float) ($data['progress'] ?? 0) > 100)) {
            $errors['data.progress'] = 'Progress is between 0 and 100%.';
        }
        if ($entity->key === 'feedback' && filled($data['from'] ?? null) && (int) $data['from'] === (int) ($data['about'] ?? 0)) {
            $errors['data.from'] = 'Nobody can give 360 feedback about themselves.';
        }

        return $errors;
    }

    /**
     * An objective's status for its progress and due date.
     */
    public function standing(float $progress, ?Carbon $due): string
    {
        return match (true) {
            $progress >= 100 => 'achieved',
            $due && $due->lt(today()) => 'behind',
            $due && $due->lte(today()->addDays(self::AT_RISK_DAYS)) && $progress < self::AT_RISK_PROGRESS => 'at_risk',
            default => 'on_track',
        };
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'objectives') {
            $record->status = $this->standing($this->number($record, 'progress'), $record->due_on);

            return;
        }
        $record->occurs_on ??= today();
        if ($record->entity === 'reviews' && $record->isDirty('status') && $record->status === 'completed') {
            $this->put($record, ['_completed_on' => today()->toDateString()]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'objectives') {
            return $record->status === 'achieved' ? [] : ['progress' => ['label' => 'Update progress', 'icon' => 'trending-up', 'fields' => [
                ['name' => 'progress', 'label' => 'Progress %', 'type' => 'number', 'value' => $record->value('progress')],
            ]]];
        }
        if ($record->entity === 'feedback') {
            return [];
        }

        return match ($record->status) {
            'scheduled' => ['advance' => ['label' => 'Start self review', 'icon' => 'pen-line']],
            'self_review' => ['advance' => ['label' => 'Send to manager', 'icon' => 'send']],
            'manager_review' => ['advance' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [
                ['name' => 'rating', 'label' => 'Rating (1–5)', 'type' => 'number', 'value' => $record->value('rating')],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'progress') {
            $progress = (float) $request->validate(['progress' => ['required', 'numeric', 'min:0', 'max:100']])['progress'];
            $before = $this->number($record, 'progress');
            $record->update(['data' => [...$record->data, 'progress' => $progress]]);

            return $record->title.': '.$before.'% → '.$progress.'%, '.str_replace('_', ' ', $record->status).'.';
        }
        $next = self::NEXT_STAGE[$record->status];
        $data = $record->data;
        if ($next === 'completed') {
            $rating = $request->validate(['rating' => ['nullable', 'integer', 'between:1,5']])['rating'] ?? $record->value('rating');
            if (blank($rating)) {
                throw ValidationException::withMessages(['rating' => 'Give a rating before completing the review.']);
            }
            $data['rating'] = (string) $rating;
        }
        $record->update(['status' => $next, 'data' => $data]);

        return $this->name($record->value('employee')).'\'s review '.match ($next) {
            'self_review' => 'is with them for their self review.',
            'manager_review' => 'is with the manager.',
            default => 'is complete, rated '.$record->value('rating').' out of 5.',
        };
    }

    protected function name(mixed $userId): string
    {
        return (string) (filled($userId) ? User::query()->whereKey($userId)->value('name') : null) ?: 'Unknown';
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('objectives')->where('status', '!=', 'achieved')->get() as $objective) {
            if ($objective->status !== $this->standing($this->number($objective, 'progress'), $objective->due_on)) {
                $objective->save();
                $changed++;
            }
        }

        return $changed;
    }

    public function homeCards(): array
    {
        $slipping = $this->records('objectives')->whereIn('status', ['at_risk', 'behind'])->orderBy('due_on')->get();
        $names = User::query()->whereIn('id', $slipping->map(fn (Record $objective) => $objective->value('owner'))->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Objectives slipping', 'icon' => 'target', 'empty' => 'Every objective is on track.',
            'rows' => $slipping->map(fn (Record $objective) => [
                'label' => $objective->title, 'sub' => ($names[$objective->value('owner')] ?? '—').' · due '.($objective->due_on?->format('d M') ?? '—'),
                'value' => (float) $objective->value('progress').'%', 'href' => $objective->url(), 'tone' => $objective->status === 'behind' ? 'danger' : 'warning',
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $reviews = $this->dated('reviews', $from, $to)->where('status', 'completed')->get();
        $objectives = $this->records('objectives')->get();
        $names = User::query()->whereIn('id', $reviews->map(fn (Record $review) => $review->value('employee'))->merge($objectives->map(fn (Record $objective) => $objective->value('owner')))->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Ratings by employee', 'columns' => ['Employee', 'Reviews completed', 'Latest rating', 'Average rating'], 'rows' => $reviews
                ->groupBy(fn (Record $review) => $names[$review->value('employee')] ?? 'Unknown')->sortKeys()
                ->map(fn ($group, string $name) => [$name, $group->count(), (string) $group->sortByDesc('occurs_on')->first()->value('rating'), number_format($group->avg(fn (Record $review) => (int) $review->value('rating')), 1)])
                ->values()->all()],
            ['title' => 'Objectives by owner', 'columns' => ['Owner', 'Objectives', 'Achieved', 'On track', 'At risk', 'Behind', 'Average progress'], 'rows' => $objectives
                ->groupBy(fn (Record $objective) => $names[$objective->value('owner')] ?? 'Unknown')->sortKeys()
                ->map(fn ($group, string $name) => [
                    $name, $group->count(), $group->where('status', 'achieved')->count(), $group->where('status', 'on_track')->count(), $group->where('status', 'at_risk')->count(),
                    $group->where('status', 'behind')->count(), round($group->avg(fn (Record $objective) => $this->number($objective, 'progress'))).'%',
                ])->values()->all()],
        ];
    }
}
