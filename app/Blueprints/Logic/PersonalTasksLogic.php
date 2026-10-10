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
 * Personal tasks & habits: finishing a to-do records the day it was done. Ticking off a habit adds to
 * its streak when it was kept up on time (the day before for daily habits, the last weekday for weekday
 * ones, within a week for weekly ones) and starts again at one otherwise; the best streak is kept. Each
 * night, streaks that were missed drop back to zero.
 */
class PersonalTasksLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        if ($entity->key === 'habits' && ((float) ($data['streak'] ?? 0) < 0 || (float) ($data['best_streak'] ?? 0) < 0)) {
            return ['data.streak' => 'Streaks cannot be negative.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'todos') {
            $this->put($record, ['_done_on' => $record->status === 'done' ? ($record->value('_done_on') ?? today()->toDateString()) : null]);

            return;
        }
        $record->occurs_on ??= today();
        if ($this->number($record, 'streak') > $this->number($record, 'best_streak')) {
            $this->put($record, ['best_streak' => (int) $this->number($record, 'streak')]);
        }
    }

    /**
     * The latest day a habit can last have been done and still keep its streak on the given day.
     */
    protected function keptSince(string $frequency, Carbon $day): Carbon
    {
        return match ($frequency) {
            'weekly' => $day->copy()->subDays(7),
            'weekdays' => $day->copy()->subWeekday(),
            default => $day->copy()->subDay(),
        };
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'todos' && $record->status === 'to_do' => ['start' => ['label' => 'Start', 'icon' => 'play'], 'done' => ['label' => 'Done', 'icon' => 'check']],
            $record->entity === 'todos' && $record->status === 'doing' => ['done' => ['label' => 'Done', 'icon' => 'check']],
            $record->entity === 'habits' && $record->status === 'active' && $record->value('_last_done') !== today()->toDateString() => ['check' => ['label' => 'Done today', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'start') {
            $record->update(['status' => 'doing']);

            return 'Started '.$record->title.'.';
        }
        if ($action === 'done') {
            $record->update(['status' => 'done']);

            return $record->title.' done.';
        }
        if ($record->value('_last_done') === today()->toDateString()) {
            throw ValidationException::withMessages(['habit' => $record->title.' is already ticked off today.']);
        }
        $last = filled($record->value('_last_done')) ? Carbon::parse($record->value('_last_done')) : null;
        $streak = $last && $last->gte($this->keptSince((string) $record->value('frequency'), today())) ? (int) $this->number($record, 'streak') + 1 : 1;
        $record->update(['data' => [...$record->data, 'streak' => $streak, '_last_done' => today()->toDateString()]]);

        return $record->title.': '.$streak.' in a row'.($streak > 1 && $streak >= $this->number($record, 'best_streak') ? ', your best yet' : '').'.';
    }

    public function daily(Workspace $workspace): int
    {
        $broken = 0;
        foreach ($this->records('habits')->where('status', 'active')->get() as $habit) {
            $last = filled($habit->value('_last_done')) ? Carbon::parse($habit->value('_last_done')) : null;
            if ($this->number($habit, 'streak') > 0 && (! $last || $last->lt($this->keptSince((string) $habit->value('frequency'), today())))) {
                $habit->update(['data' => [...$habit->data, 'streak' => 0]]);
                $broken++;
            }
        }

        return $broken;
    }

    public function homeCards(): array
    {
        $todos = $this->records('todos')->where('status', '!=', 'done')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->toDateString())->orderBy('due_on')->get();
        $habits = $this->records('habits')->where('status', 'active')->orderBy('title')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Due today or late', 'icon' => 'list-todo', 'empty' => 'Nothing due today.',
                'rows' => $todos->map(fn (Record $todo) => ['label' => $todo->title, 'sub' => ucfirst((string) $todo->value('list')), 'value' => $todo->due_on->isToday() ? 'today' : $todo->due_on->format('d M'), 'href' => $todo->url(), 'tone' => $todo->due_on->lt(today()) ? 'danger' : ($todo->value('priority') === 'high' ? 'warning' : null)])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Habits', 'icon' => 'repeat', 'empty' => 'No habits yet.',
                'rows' => $habits->map(fn (Record $habit) => ['label' => $habit->title, 'sub' => $habit->value('_last_done') === today()->toDateString() ? 'Done today' : 'Not yet today', 'value' => (int) $this->number($habit, 'streak').' in a row', 'href' => $habit->url(), 'tone' => $habit->value('_last_done') === today()->toDateString() ? 'success' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $todos = $this->records('todos')->get();
        $done = $todos->filter(fn (Record $todo) => filled($todo->value('_done_on')) && Carbon::parse($todo->value('_done_on'))->between($from->copy()->startOfDay(), $to->copy()->endOfDay()));

        return [['title' => 'To-dos by list', 'columns' => ['List', 'Done in period', 'Still open', 'Late'], 'rows' => $todos
            ->groupBy(fn (Record $todo) => ucfirst((string) ($todo->value('list') ?: 'none')))->sortKeys()
            ->map(fn ($group, string $list) => [$list, $done->whereIn('id', $group->pluck('id'))->count(), $group->where('status', '!=', 'done')->count(),
                $group->filter(fn (Record $todo) => $todo->status !== 'done' && $todo->due_on && $todo->due_on->lt(today()))->count()])
            ->values()->all()]];
    }
}
