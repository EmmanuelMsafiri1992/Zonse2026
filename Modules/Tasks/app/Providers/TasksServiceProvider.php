<?php

namespace Modules\Tasks\Providers;

use App\Registries\MenuItem;
use App\Registries\MenuRegistry;
use App\Registries\ModuleRegistry;
use App\Registries\SearchRegistry;
use App\Registries\WidgetRegistry;
use Illuminate\Support\Facades\Gate;
use Modules\Tasks\Models\Task;
use Modules\Tasks\Policies\TaskPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TasksServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Tasks';

    protected string $nameLower = 'tasks';

    /** @var list<class-string> */
    protected array $providers = [RouteServiceProvider::class];

    public function boot(): void
    {
        parent::boot();

        Gate::policy(Task::class, TaskPolicy::class);

        $this->app->make(ModuleRegistry::class)->entry('tasks', 'tasks.board');

        $this->registerMenu();
        $this->registerWidgets();
        $this->registerSearch();
    }

    protected function registerMenu(): void
    {
        $this->app->make(MenuRegistry::class)->section('apps', 'Apps', 10)
            ->add(MenuItem::make('Tasks', 'tasks.board', 'check-square')->module('tasks')->order(40)
                ->active('tasks.*')
                ->children([
                    MenuItem::make('Board', 'tasks.board', 'kanban-square')->module('tasks')->order(1)->active('tasks.board'),
                    MenuItem::make('All tasks', 'tasks.index', 'list-checks')->module('tasks')->order(2)->active(['tasks.index', 'tasks.show', 'tasks.create', 'tasks.edit']),
                ]));
    }

    protected function registerWidgets(): void
    {
        $this->app->make(WidgetRegistry::class)->register('tasks.mine', 'tasks::widgets.mine', [
            'module' => 'tasks', 'width' => 4, 'order' => 40, 'title' => 'My tasks',
            'data' => function () {
                $userId = auth()->id();

                return [
                    'tasks' => Task::query()->with('contact')->open()->where('assignee_id', $userId)->orderByUrgency()->limit(6)->get(),
                    'openCount' => Task::query()->open()->where('assignee_id', $userId)->count(),
                    'overdueCount' => Task::query()->overdue()->where('assignee_id', $userId)->count(),
                ];
            },
        ]);
    }

    protected function registerSearch(): void
    {
        $this->app->make(SearchRegistry::class)->register('tasks', 'Tasks', function (string $q, int $limit) {
            return Task::query()->with('contact')->search($q)->orderByUrgency()->limit($limit)->get()->map(fn (Task $task) => [
                'title' => $task->title,
                'sub' => implode(' · ', array_filter([$task->priorityLabel(), $task->dueLabel() ? 'Due '.$task->dueLabel() : null, $task->contact?->displayName()])),
                'url' => route('tasks.show', $task),
                'badge' => $task->status,
            ]);
        }, module: 'tasks', icon: 'check-square');
    }
}
