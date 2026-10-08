<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AutomationEmail;
use App\Notifications\WorkspaceAlert;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Tasks\Models\Task;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** "When this happens, do that" rules: matching, each action, the run log, loop guard and the settings screens. */
class AutomationsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspace(array $modules = ['contacts', 'tasks', 'helpdesk']): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules($modules, $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param array<string, mixed> $attributes */
    protected function automation(Workspace $workspace, array $attributes = []): Automation
    {
        return Automation::factory()->create(['workspace_id' => $workspace->id] + $attributes);
    }

    public function test_a_new_lead_gets_a_follow_up_task_linked_to_it(): void
    {
        [$owner, $workspace] = $this->workspace();
        $automation = $this->automation($workspace, [
            'trigger' => 'contact.created',
            'conditions' => [['field' => 'type', 'operator' => 'equals', 'value' => 'lead']],
            'actions' => [['type' => 'create_task', 'title' => 'Call {{name}}', 'description' => null, 'assignee' => 'user', 'user_id' => $owner->id, 'due_in_days' => 1, 'priority' => 'high']],
        ]);

        $this->actingAs($owner);
        $contact = Contact::factory()->lead()->create(['workspace_id' => $workspace->id, 'name' => 'Rudo Banda']);

        $task = Task::sole();
        $this->assertSame('Call Rudo Banda', $task->title);
        $this->assertSame('high', $task->priority);
        $this->assertSame($owner->id, $task->assignee_id);
        $this->assertSame(today()->addDay()->toDateString(), $task->due_date->toDateString());
        $this->assertSame($contact->getMorphClass(), $task->taskable_type);
        $this->assertSame($contact->id, $task->taskable_id);

        $run = AutomationRun::sole();
        $this->assertSame('succeeded', $run->status);
        $this->assertSame('Rudo Banda', $run->subject_label);
        $this->assertSame(1, $automation->fresh()->run_count);
        $this->assertNotNull($automation->fresh()->last_run_at);
    }

    public function test_conditions_that_do_not_match_do_nothing(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->automation($workspace, ['conditions' => [['field' => 'type', 'operator' => 'equals', 'value' => 'lead']]]);

        $this->actingAs($owner);
        Contact::factory()->customer()->create(['workspace_id' => $workspace->id]);

        $this->assertSame(0, AutomationRun::count());
    }

    public function test_inactive_automations_are_skipped(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->automation($workspace, ['is_active' => false]);

        $this->actingAs($owner);
        Contact::factory()->create(['workspace_id' => $workspace->id]);

        $this->assertSame(0, AutomationRun::count());
    }

    public function test_notify_alerts_the_owners_and_admins_including_whoever_made_the_change(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->workspace();
        $admin = $this->memberOf($workspace, 'admin');
        $member = $this->memberOf($workspace);
        $this->automation($workspace, [
            'trigger' => 'ticket.created',
            'conditions' => [['field' => 'priority', 'operator' => 'equals', 'value' => 'URGENT']],
            'actions' => [['type' => 'notify', 'to' => 'admins', 'message' => 'Urgent ticket: {{subject}}']],
        ]);

        $this->actingAs($owner);
        Ticket::factory()->priority('urgent')->create(['workspace_id' => $workspace->id, 'subject' => 'Printer on fire']);

        Notification::assertSentTo([$owner, $admin], WorkspaceAlert::class, fn (WorkspaceAlert $alert) => $alert->kind === 'automations' && $alert->title === 'Urgent ticket: Printer on fire');
        Notification::assertNotSentTo($member, WorkspaceAlert::class);
        $this->assertSame('succeeded', AutomationRun::sole()->status);
    }

    public function test_send_email_writes_to_the_customer(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->workspace();
        $this->automation($workspace, [
            'actions' => [['type' => 'send_email', 'subject' => 'Welcome, {{name}}', 'body' => "Hello {{name}},\n\nThanks for choosing {{workspace_name}}."]],
        ]);

        $this->actingAs($owner);
        Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Tendai Moyo', 'email' => 'tendai@example.com']);

        Notification::assertSentOnDemand(AutomationEmail::class, function (AutomationEmail $email, array $channels, object $notifiable) use ($workspace) {
            return $notifiable->routes['mail'] === 'tendai@example.com'
                && $email->subject === 'Welcome, Tendai Moyo'
                && str_contains($email->body, 'Thanks for choosing '.$workspace->name);
        });
    }

    public function test_a_failed_action_is_logged_and_the_others_still_run(): void
    {
        Notification::fake();
        [$owner, $workspace] = $this->workspace();
        $this->automation($workspace, [
            'actions' => [
                ['type' => 'send_email', 'subject' => 'Hi', 'body' => 'Hello'],
                ['type' => 'notify', 'to' => 'admins', 'message' => 'New contact {{name}}'],
            ],
        ]);

        $this->actingAs($owner);
        Contact::factory()->create(['workspace_id' => $workspace->id, 'email' => null]);

        $run = AutomationRun::sole();
        $this->assertSame('failed', $run->status);
        $this->assertFalse($run->log[0]['ok']);
        $this->assertStringContainsString('no email address', $run->log[0]['text']);
        $this->assertTrue($run->log[1]['ok']);
        Notification::assertSentTo($owner, WorkspaceAlert::class);
    }

    public function test_the_changed_operator_and_update_record_without_looping(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->automation($workspace, [
            'trigger' => 'ticket.updated',
            'conditions' => [['field' => 'status', 'operator' => 'changed', 'value' => null], ['field' => 'status', 'operator' => 'equals', 'value' => 'pending']],
            'actions' => [['type' => 'update_record', 'field' => 'priority', 'value' => 'high']],
        ]);
        $this->automation($workspace, [
            'trigger' => 'ticket.updated',
            'actions' => [['type' => 'notify', 'to' => 'admins', 'message' => 'Ticket changed']],
        ]);

        $this->actingAs($owner);
        $ticket = Ticket::factory()->create(['workspace_id' => $workspace->id]);
        $ticket->update(['subject' => 'New subject']);
        $this->assertSame('normal', $ticket->fresh()->priority);

        $ticket->update(['status' => 'pending']);
        $this->assertSame('high', $ticket->fresh()->priority);

        // Two saves by a person each set off the catch-all rule once; the engine's own change does not.
        $this->assertSame(3, AutomationRun::count());
    }

    public function test_tasks_actions_fail_when_the_tasks_app_is_off(): void
    {
        [$owner, $workspace] = $this->workspace(['contacts']);
        $this->automation($workspace, ['actions' => [['type' => 'create_task', 'title' => 'Call {{name}}', 'priority' => 'normal']]]);

        $this->actingAs($owner);
        Contact::factory()->create(['workspace_id' => $workspace->id]);

        $this->assertSame(0, Task::allWorkspaces()->count());
        $this->assertSame('failed', AutomationRun::sole()->status);
    }

    public function test_automations_only_react_to_their_own_workspace(): void
    {
        [$owner, $workspace] = $this->workspace();
        [$otherOwner, $other] = $this->ownerWithWorkspace();
        $other->enableModules(['contacts'], $otherOwner);
        $this->automation($workspace);

        $this->actingAs($otherOwner);
        app(WorkspaceContext::class)->set($other);
        Contact::factory()->create(['workspace_id' => $other->id]);

        $this->assertSame(0, AutomationRun::allWorkspaces()->count());
    }

    public function test_admins_can_create_edit_toggle_and_delete_automations(): void
    {
        [$owner, $workspace] = $this->workspace();
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->get(route('settings.automations.index'))->assertOk()->assertSee('Follow up new leads');
        $this->get(route('settings.automations.create', ['recipe' => 'lead-follow-up']))->assertOk()->assertSee('Follow up new leads');

        $this->post(route('settings.automations.store'), [
            'name' => 'Lead follow-up',
            'trigger' => 'contact.created',
            'is_active' => '1',
            'conditions' => [['field' => 'type', 'operator' => 'equals', 'value' => 'lead'], ['field' => '', 'operator' => 'equals', 'value' => '']],
            'actions' => [['type' => 'create_task', 'title' => 'Call {{name}}', 'assignee' => 'user', 'user_id' => (string) $member->id, 'due_in_days' => '2', 'priority' => 'high', 'message' => 'stray']],
        ])->assertRedirect(route('settings.automations.index'));

        $automation = Automation::sole();
        $this->assertSame([['field' => 'type', 'operator' => 'equals', 'value' => 'lead']], $automation->conditions);
        $this->assertSame(['type' => 'create_task', 'title' => 'Call {{name}}', 'assignee' => 'user', 'user_id' => $member->id, 'due_in_days' => 2, 'priority' => 'high'], $automation->actions[0]);
        $this->assertSame($owner->id, $automation->created_by);

        $this->get(route('settings.automations.edit', $automation))->assertOk()->assertSee('Lead follow-up');
        $this->put(route('settings.automations.update', $automation), [
            'name' => 'Lead follow-up v2', 'trigger' => 'contact.created', 'is_active' => '0',
            'actions' => [['type' => 'notify', 'to' => 'admins', 'message' => 'New lead']],
        ])->assertRedirect(route('settings.automations.edit', $automation));
        $this->assertFalse($automation->fresh()->is_active);
        $this->assertSame('Lead follow-up v2', $automation->fresh()->name);

        $this->post(route('settings.automations.toggle', $automation))->assertRedirect();
        $this->assertTrue($automation->fresh()->is_active);

        $this->delete(route('settings.automations.destroy', $automation))->assertRedirect(route('settings.automations.index'));
        $this->assertSame(0, Automation::count());
    }

    public function test_invalid_rules_are_rejected(): void
    {
        [$owner, $workspace] = $this->workspace();
        [, $other] = $this->ownerWithWorkspace();
        $outsider = $this->memberOf($other);
        app(WorkspaceContext::class)->set($workspace);

        $this->actingAs($owner)->post(route('settings.automations.store'), [
            'name' => '',
            'trigger' => 'contact.exploded',
            'actions' => [],
        ])->assertSessionHasErrors(['name', 'trigger', 'actions']);

        $this->post(route('settings.automations.store'), [
            'name' => 'Bad',
            'trigger' => 'ticket.created',
            'conditions' => [['field' => 'title', 'operator' => 'equals', 'value' => 'x'], ['field' => 'priority', 'operator' => 'equals', 'value' => '']],
            'actions' => [
                ['type' => 'notify', 'to' => 'user', 'user_id' => $outsider->id, 'message' => 'Hi'],
                ['type' => 'update_record', 'field' => 'channel', 'value' => 'email'],
                ['type' => 'update_record', 'field' => 'status', 'value' => 'exploded'],
            ],
        ])->assertSessionHasErrors(['conditions.0.field', 'conditions.1.value', 'actions.0.user_id', 'actions.1.field', 'actions.2.value']);

        $this->assertSame(0, Automation::count());
    }

    public function test_members_cannot_manage_automations_or_see_other_workspaces(): void
    {
        [, $workspace] = $this->workspace();
        $member = $this->memberOf($workspace);
        $automation = $this->automation($workspace);

        $this->actingAs($member)->get(route('settings.automations.index'))->assertForbidden();
        $this->post(route('settings.automations.toggle', $automation))->assertForbidden();

        [$otherOwner] = $this->ownerWithWorkspace();
        $this->actingAs($otherOwner)->get(route('settings.automations.edit', $automation))->assertNotFound();
        $this->delete(route('settings.automations.destroy', $automation))->assertNotFound();
        $this->assertSame(1, Automation::allWorkspaces()->count());
    }
}
