<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceAlert;
use App\Support\Notifier;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Appointments\Models\Appointment;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Payment;
use Modules\Tasks\Models\Task;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** In-app alerts: what triggers them, the bell and inbox, and each person's choices. */
class NotificationsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace, 2: User} */
    protected function team(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['tasks', 'invoicing'], $owner);
        $member = $this->memberOf($workspace);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh(), $member];
    }

    public function test_assigning_a_task_alerts_the_assignee_in_the_app_and_by_email(): void
    {
        [$owner, $workspace, $member] = $this->team();
        Notification::fake();

        $this->actingAs($owner);
        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Order more gloves', 'assignee_id' => $member->id, 'due_date' => '2026-10-20']);

        Notification::assertSentTo($member, WorkspaceAlert::class, function (WorkspaceAlert $alert, array $channels) use ($task) {
            return $alert->kind === 'assigned' && str_contains($alert->title, 'Order more gloves')
                && $alert->url === $task->activityUrl() && $channels === ['database', 'mail'];
        });
        Notification::assertNotSentTo($owner, WorkspaceAlert::class);
    }

    public function test_reassigned_tickets_and_booked_appointments_alert_the_new_person(): void
    {
        [$owner, $workspace, $member] = $this->team();
        $this->actingAs($owner);

        $ticket = Ticket::factory()->create(['workspace_id' => $workspace->id, 'subject' => 'Printer jammed', 'assignee_id' => $owner->id]);
        $this->assertSame(0, $member->notifications()->count());
        $ticket->update(['assignee_id' => $member->id]);
        Appointment::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Check-up', 'staff_id' => $member->id]);

        $titles = $member->notifications()->get()->pluck('data.title');
        $this->assertContains('Ticket assigned to you: Printer jammed', $titles);
        $this->assertTrue($titles->contains(fn ($title) => str_starts_with($title, 'Appointment booked with you:')));
    }

    public function test_people_are_not_told_about_their_own_actions_or_unchanged_assignees(): void
    {
        [, $workspace, $member] = $this->team();
        $this->actingAs($member);

        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'assignee_id' => $member->id]);
        $task->update(['title' => 'Renamed']);

        $this->assertSame(0, $member->notifications()->count());
    }

    public function test_alerts_land_in_the_bell_and_the_inbox_and_open_their_link(): void
    {
        [$owner, $workspace, $member] = $this->team();
        $this->actingAs($owner);
        $task = Task::factory()->create(['workspace_id' => $workspace->id, 'title' => 'Count the stock', 'assignee_id' => $member->id]);

        $alert = $member->notifications()->firstOrFail();
        $this->assertSame($workspace->id, $alert->workspace_id);
        $this->assertSame('assigned', $alert->type);

        $this->actingAs($member)->get(route('dashboard'))->assertOk()
            ->assertSee('Notifications (1 unread)')->assertSee('Task assigned to you: Count the stock');
        $this->actingAs($member)->get(route('notifications.index', ['unread' => 1]))->assertOk()->assertSee('Count the stock');

        $this->actingAs($member)->get(route('notifications.open', $alert->id))->assertRedirect($task->activityUrl());
        $this->assertNotNull($alert->fresh()->read_at);
        $this->actingAs($member)->get(route('notifications.index', ['unread' => 1]))->assertSee("You're all caught up");
    }

    public function test_mark_all_read_and_remove(): void
    {
        [, $workspace, $member] = $this->team();
        Notifier::send($member, 'team', 'First', workspace: $workspace);
        Notifier::send($member, 'team', 'Second', workspace: $workspace);

        $this->actingAs($member)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $member->unreadNotifications()->count());

        $this->actingAs($member)->delete(route('notifications.destroy', $member->notifications()->first()->id))->assertRedirect();
        $this->assertSame(1, $member->notifications()->count());
    }

    public function test_alerts_stay_in_their_workspace_and_with_their_person(): void
    {
        [$owner, $workspace, $member] = $this->team();
        [, $other] = $this->ownerWithWorkspace();
        $other->members()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        Notifier::send($member, 'team', 'Alert from the other shop', workspace: $other);
        Notifier::send($member, 'team', 'Private alert', workspace: $workspace);

        $this->actingAs($member)->get(route('notifications.index'))->assertSee('Private alert')->assertDontSee('Alert from the other shop');

        $mine = $member->notifications()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->actingAs($owner)->get(route('notifications.open', $mine->id))->assertNotFound();
        $this->actingAs($owner)->delete(route('notifications.destroy', $mine->id))->assertNotFound();
    }

    public function test_off_site_links_are_never_followed(): void
    {
        [, $workspace, $member] = $this->team();
        Notifier::send($member, 'team', 'Suspicious', url: 'https://evil.example.com/steal', workspace: $workspace);

        $this->actingAs($member)->get(route('notifications.open', $member->notifications()->first()->id))
            ->assertRedirect(route('notifications.index'));
    }

    public function test_people_choose_which_alerts_they_get(): void
    {
        [$owner, $workspace, $member] = $this->team();

        $this->actingAs($member)->get(route('profile.edit'))->assertOk()->assertSee('Work assigned to me');
        $this->actingAs($member)->put(route('profile.notifications.update'), [
            'preferences' => ['assigned' => ['app' => '0', 'email' => '0'], 'comments' => ['app' => '1', 'email' => '1']],
        ])->assertRedirect();

        $member->refresh();
        $this->assertFalse(Notifier::wants($member, 'assigned', 'app'));
        $this->assertTrue(Notifier::wants($member, 'comments', 'email'));

        $this->actingAs($owner);
        Task::factory()->create(['workspace_id' => $workspace->id, 'assignee_id' => $member->id]);
        $this->assertSame(0, $member->notifications()->count());
    }

    public function test_notes_on_work_alert_the_assignee_and_creator(): void
    {
        [$owner, $workspace, $member] = $this->team();
        $workspace->enableModules(['clinic'], $owner);
        $this->actingAs($member);
        $record = Record::factory()->ofEntity('clinic', 'patients')->create(['workspace_id' => $workspace->id, 'title' => 'Tendai Moyo', 'created_by' => $member->id]);

        $this->actingAs($owner)->post(route('apps.records.comments.store', ['clinic', 'patients', $record->id]), ['body' => 'Allergic to penicillin.'])->assertRedirect();

        $alert = $member->notifications()->firstOrFail();
        $this->assertSame('comments', $alert->type);
        $this->assertStringContainsString('Tendai Moyo', $alert->data['title']);
        $this->assertSame('Allergic to penicillin.', $alert->data['body']);
        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_payments_alert_admins_but_not_regular_members(): void
    {
        [$owner, $workspace, $member] = $this->team();
        $admin = $this->memberOf($workspace, 'admin');
        app(WorkspaceContext::class)->set($workspace);
        $this->actingAs($admin);

        Payment::factory()->create(['workspace_id' => $workspace->id, 'amount' => 25]);

        $this->assertSame('payments', $owner->notifications()->firstOrFail()->type);
        $this->assertStringContainsString('Payment received', $owner->notifications()->first()->data['title']);
        $this->assertSame(0, $admin->notifications()->count());
        $this->assertSame(0, $member->notifications()->count());
    }

    public function test_admins_hear_when_someone_joins(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $invitation = Invitation::create(['workspace_id' => $workspace->id, 'email' => 'new@example.com', 'role' => 'member', 'invited_by' => $owner->id]);
        $newcomer = User::factory()->create(['email' => 'new@example.com', 'name' => 'Rudo Newcomer']);

        $this->actingAs($newcomer)->post(route('invitations.accept.store', $invitation->token))->assertRedirect(route('dashboard'));

        $alert = $owner->notifications()->firstOrFail();
        $this->assertSame('team', $alert->type);
        $this->assertSame('Rudo Newcomer joined the workspace', $alert->data['title']);
        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_owners_are_reminded_before_their_trial_ends(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->subscription->forceFill(['status' => 'trialing', 'trial_ends_at' => now()->addDays(3)])->save();
        [$quietOwner, $quiet] = $this->ownerWithWorkspace();
        $quiet->subscription->forceFill(['status' => 'trialing', 'trial_ends_at' => now()->addDays(10)])->save();

        $this->artisan('zonseo:send-plan-reminders')->assertSuccessful();

        $alert = $owner->notifications()->firstOrFail();
        $this->assertSame('billing', $alert->type);
        $this->assertStringContainsString('ends in 3 days', $alert->data['title']);
        $this->assertSame(0, $quietOwner->notifications()->count());
    }
}
