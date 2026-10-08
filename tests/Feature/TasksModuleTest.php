<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Modules\Tasks\Models\Task;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class TasksModuleTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function taskWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['tasks'], $owner);

        return [$owner, $workspace->fresh()];
    }

    public function test_task_routes_require_the_module_to_be_enabled(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('tasks.board'))->assertRedirect(route('settings.modules.index'));
    }

    public function test_a_task_can_be_created_assigned_and_linked_to_a_contact(): void
    {
        [$owner, $workspace] = $this->taskWorkspace();
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Rudo Client', 'kind' => 'person', 'company_name' => null]);
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($owner)->post(route('tasks.store'), [
            'title' => 'Call Rudo about results', 'priority' => 'high', 'due_date' => today()->addDays(2)->format('Y-m-d'),
            'assignee_id' => $member->id, 'contact_id' => $contact->id, 'description' => 'Lab results came back.',
        ])->assertRedirect(route('tasks.show', Task::query()->first()));

        $task = Task::query()->first();
        $this->assertSame('todo', $task->status);
        $this->assertSame('high', $task->priority);
        $this->assertSame($member->id, $task->assignee_id);
        $this->assertSame($owner->id, $task->created_by);
        $this->assertNotEmpty($task->uuid);

        $this->actingAs($owner)->get(route('tasks.show', $task))->assertOk()
            ->assertSee('Call Rudo about results')->assertSee('Rudo Client')->assertSee($member->name)->assertSee('Lab results came back.');

        $this->actingAs($owner)->from(route('tasks.create'))->post(route('tasks.store'), ['title' => '', 'priority' => 'silly', 'due_date' => 'tomorrow', 'assignee_id' => 999])
            ->assertRedirect(route('tasks.create'))->assertSessionHasErrors(['title', 'priority', 'due_date', 'assignee_id']);
    }

    public function test_marking_done_sets_completed_at_and_reopening_clears_it(): void
    {
        [$owner, $workspace] = $this->taskWorkspace();
        $task = Task::factory()->for($workspace)->create();

        $this->actingAs($owner)->post(route('tasks.status', $task), ['status' => 'in_progress'])->assertRedirect();
        $this->assertSame('in_progress', $task->refresh()->status);
        $this->assertNull($task->completed_at);

        $this->actingAs($owner)->post(route('tasks.status', $task), ['status' => 'done'])->assertRedirect()->assertSessionHas('flash');
        $this->assertSame('done', $task->refresh()->status);
        $this->assertNotNull($task->completed_at);

        $this->actingAs($owner)->post(route('tasks.status', $task), ['status' => 'todo'])->assertRedirect();
        $this->assertNull($task->refresh()->completed_at);

        $this->actingAs($owner)->post(route('tasks.status', $task), ['status' => 'bogus'])->assertSessionHasErrors('status');
    }

    public function test_board_drag_moves_a_task_between_columns_and_reorders_siblings(): void
    {
        [$owner, $workspace] = $this->taskWorkspace();
        $a = Task::factory()->for($workspace)->inProgress()->create(['title' => 'A', 'position' => 0]);
        $b = Task::factory()->for($workspace)->inProgress()->create(['title' => 'B', 'position' => 1]);
        $moving = Task::factory()->for($workspace)->create(['title' => 'Moving', 'position' => 0]);

        $this->actingAs($owner)->postJson(route('tasks.status', $moving), ['status' => 'in_progress', 'position' => 1])
            ->assertOk()->assertJson(['ok' => true, 'status' => 'in_progress', 'label' => 'In progress']);

        $order = Task::query()->where('status', 'in_progress')->orderBy('position')->pluck('title')->all();
        $this->assertSame(['A', 'Moving', 'B'], $order);
        $this->assertSame([0, 1, 2], Task::query()->where('status', 'in_progress')->orderBy('position')->pluck('position')->all());

        $this->actingAs($owner)->get(route('tasks.board'))->assertOk()->assertSeeInOrder(['To do', 'In progress', 'A', 'Moving', 'B', 'Done']);
    }

    public function test_the_list_filters_and_sorts_by_urgency(): void
    {
        [$owner, $workspace] = $this->taskWorkspace();
        $me = $this->memberOf($workspace, 'member');
        Task::factory()->for($workspace)->priority('low')->due(today()->addDays(10)->format('Y-m-d'))->create(['title' => 'Low later']);
        Task::factory()->for($workspace)->priority('urgent')->create(['title' => 'Urgent no date', 'assignee_id' => $me->id]);
        Task::factory()->for($workspace)->priority('normal')->due(today()->subDays(2)->format('Y-m-d'))->create(['title' => 'Overdue normal']);
        Task::factory()->for($workspace)->priority('normal')->due(today()->format('Y-m-d'))->create(['title' => 'Today normal']);
        Task::factory()->for($workspace)->done()->create(['title' => 'Finished thing']);

        $this->actingAs($owner)->get(route('tasks.index'))->assertOk()
            ->assertSeeInOrder(['Urgent no date', 'Overdue normal', 'Today normal', 'Low later'])->assertDontSee('Finished thing');
        $this->actingAs($owner)->get(route('tasks.index', ['due' => 'overdue']))->assertOk()->assertSee('Overdue normal')->assertDontSee('Today normal');
        $this->actingAs($owner)->get(route('tasks.index', ['due' => 'today']))->assertOk()->assertSee('Today normal')->assertDontSee('Overdue normal');
        $this->actingAs($owner)->get(route('tasks.index', ['status' => 'done']))->assertOk()->assertSee('Finished thing')->assertDontSee('Urgent no date');
        $this->actingAs($owner)->get(route('tasks.index', ['assignee' => $me->id]))->assertOk()->assertSee('Urgent no date')->assertDontSee('Low later');
        $this->actingAs($owner)->get(route('tasks.index', ['assignee' => 'unassigned']))->assertOk()->assertSee('Low later')->assertDontSee('Urgent no date');
        $this->actingAs($owner)->get(route('tasks.index', ['priority' => 'low']))->assertOk()->assertSee('Low later')->assertDontSee('Urgent no date');
        $this->actingAs($owner)->get(route('tasks.index', ['q' => 'Overdue']))->assertOk()->assertSee('Overdue normal')->assertDontSee('Low later');
    }

    public function test_tasks_are_scoped_to_the_workspace_and_roles_are_enforced(): void
    {
        [$owner, $workspace] = $this->taskWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer');
        $mine = Task::factory()->for($workspace)->create(['title' => 'Private task']);
        $assignedToViewer = Task::factory()->for($workspace)->create(['title' => 'Viewer task', 'assignee_id' => $viewer->id]);

        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace();
        $otherWorkspace->enableModules(['tasks'], $otherOwner);
        $this->actingAs($otherOwner)->get(route('tasks.show', $mine))->assertNotFound();
        $this->actingAs($otherOwner)->get(route('tasks.index'))->assertOk()->assertDontSee('Private task');

        $this->actingAs($viewer)->get(route('tasks.index'))->assertOk()->assertSee('Private task');
        $this->actingAs($viewer)->get(route('tasks.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('tasks.status', $mine), ['status' => 'done'])->assertForbidden();
        // A viewer may still tick off a task assigned to them.
        $this->actingAs($viewer)->post(route('tasks.status', $assignedToViewer), ['status' => 'done'])->assertRedirect();
        $this->assertSame('done', $assignedToViewer->refresh()->status);
        $this->actingAs($viewer)->post(route('tasks.comments.store', $assignedToViewer), ['body' => 'Done it.'])->assertRedirect();
        $this->assertSame(1, $assignedToViewer->comments()->count());

        $member = $this->memberOf($workspace, 'member');
        $this->actingAs($member)->delete(route('tasks.destroy', $mine))->assertForbidden();
        $this->actingAs($owner)->delete(route('tasks.destroy', $mine))->assertRedirect(route('tasks.index'));
        $this->assertSoftDeleted('tasks', ['id' => $mine->id]);
    }

    public function test_search_widget_contact_page_and_module_sync_include_tasks(): void
    {
        [$owner, $workspace] = $this->taskWorkspace();
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Rudo Client', 'kind' => 'person', 'company_name' => null]);
        Task::factory()->for($workspace)->due(today()->subDay()->format('Y-m-d'))->create(['title' => 'Chase the lab report', 'assignee_id' => $owner->id, 'contact_id' => $contact->id]);

        $this->actingAs($owner)->get(route('search', ['q' => 'lab report']))->assertOk()->assertSee('Chase the lab report');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('My tasks')->assertSee('Chase the lab report')->assertSee('Due Yesterday', false);
        $this->actingAs($owner)->get(route('contacts.show', $contact))->assertOk()->assertSee(route('tasks.index', ['contact' => $contact->id]), false);
        $this->actingAs($owner)->get(route('tasks.index', ['contact' => $contact->id]))->assertOk()->assertSee('Chase the lab report');

        $this->artisan('zonseo:sync-modules')->assertSuccessful();
        $this->assertTrue(Module::query()->where('key', 'tasks')->first()->is_installed);

        $this->assertDatabaseHas('activity_log', ['subject_type' => Task::class, 'event' => 'created']);
    }
}
