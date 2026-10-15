<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Modules\Tasks\Models\Task;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The v1 REST API: keys, workspace isolation, read/write access and validation. */
class ApiTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspaceWithModules(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'tasks', 'helpdesk', 'invoicing', 'appointments'], $owner);

        return [$owner, $workspace->fresh()];
    }

    /** Makes a key the way the settings page does and returns its plain text. */
    protected function keyFor(User $user, Workspace $workspace, string $access = 'write'): string
    {
        $token = $user->createToken('Test key', ApiToken::ACCESS[$access]['abilities']);
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $token->plainTextToken;
    }

    /** @return array<string, string> */
    protected function bearer(string $key): array
    {
        return ['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'];
    }

    public function test_an_admin_can_create_a_key_and_sees_it_only_once(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();

        $response = $this->actingAs($owner)->post(route('settings.api.keys.store'), ['name' => 'Booking site', 'access' => 'read', 'expires_in' => '30']);

        $response->assertRedirect(route('settings.api.index'))->assertSessionHas('newApiKey');
        $token = ApiToken::sole();
        $this->assertSame($workspace->id, $token->workspace_id);
        $this->assertSame(['read'], $token->abilities);
        $this->assertTrue($token->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $plainKey = (string) session('newApiKey');
        $this->assertStringStartsWith($token->id.'|', $plainKey);
        $this->actingAs($owner)->get(route('settings.api.index'))->assertOk()->assertSee($plainKey);
        $this->actingAs($owner)->get(route('settings.api.index'))->assertOk()->assertSee('Booking site')->assertDontSee($plainKey);
    }

    public function test_members_cannot_open_the_api_settings(): void
    {
        [, $workspace] = $this->workspaceWithModules();
        $member = $this->memberOf($workspace);

        $this->actingAs($member)->get(route('settings.api.index'))->assertForbidden();
        $this->actingAs($member)->post(route('settings.api.keys.store'), ['name' => 'Sneaky', 'access' => 'write'])->assertForbidden();
    }

    public function test_a_key_reads_only_its_own_workspace(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        [$otherOwner, $otherWorkspace] = $this->workspaceWithModules();
        Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Chipo Moyo']);
        $foreign = Contact::factory()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Other Company Person']);
        $key = $this->keyFor($owner, $workspace, 'read');

        $this->getJson('/api/v1/me', $this->bearer($key))->assertOk()
            ->assertJsonPath('data.workspace.id', $workspace->id)->assertJsonPath('data.key.abilities', ['read']);

        $this->getJson('/api/v1/contacts', $this->bearer($key))->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Chipo Moyo')->assertJsonPath('meta.total', 1);

        $this->getJson('/api/v1/contacts/'.$foreign->id, $this->bearer($key))->assertNotFound();
    }

    public function test_a_lapsed_plan_closes_the_api_too(): void
    {
        config()->set('zonseo.checkout.grace_days', 7);
        config()->set('zonseo.checkout.enforce_from', null);
        [$owner, $workspace] = $this->workspaceWithModules();
        Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Chipo Moyo']);
        $key = $this->keyFor($owner, $workspace);

        // Trial over, grace period spent: records are closed, "me" still answers so integrations can see why.
        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(10)])->save();
        $this->getJson('/api/v1/contacts', $this->bearer($key))->assertStatus(402)->assertJsonPath('message', 'Your plan has ended. Pay for a plan to carry on.');
        $this->postJson('/api/v1/contacts', ['name' => 'Blocked'], $this->bearer($key))->assertStatus(402);
        $this->getJson('/api/v1/me', $this->bearer($key))->assertOk();

        // Still inside the grace period: everything works.
        $workspace->subscription->forceFill(['trial_ends_at' => now()->subDays(2)])->save();
        $this->getJson('/api/v1/contacts', $this->bearer($key))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_requests_without_a_valid_key_are_refused(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();

        $this->getJson('/api/v1/contacts')->assertUnauthorized();
        $this->getJson('/api/v1/contacts', $this->bearer('1|not-a-real-key'))->assertUnauthorized();

        $key = $this->keyFor($owner, $workspace);
        ApiToken::query()->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/contacts', $this->bearer($key))->assertUnauthorized();
    }

    public function test_a_revoked_key_stops_working(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        $key = $this->keyFor($owner, $workspace);
        $this->getJson('/api/v1/me', $this->bearer($key))->assertOk();

        $this->actingAs($owner)->delete(route('settings.api.keys.destroy', ApiToken::sole()->id))->assertRedirect();
        $this->app['auth']->forgetGuards();

        $this->assertSame(0, ApiToken::count());
        $this->getJson('/api/v1/me', $this->bearer($key))->assertUnauthorized();
    }

    public function test_a_key_stops_working_once_its_owner_leaves_the_workspace(): void
    {
        [, $workspace] = $this->workspaceWithModules();
        $admin = $this->memberOf($workspace, 'admin');
        $key = $this->keyFor($admin, $workspace);

        $workspace->members()->detach($admin->id);

        $this->getJson('/api/v1/me', $this->bearer($key))->assertForbidden();
    }

    public function test_a_read_key_cannot_write(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        $key = $this->keyFor($owner, $workspace, 'read');

        $this->postJson('/api/v1/contacts', ['type' => 'customer', 'name' => 'Tendai'], $this->bearer($key))->assertForbidden();
        $this->assertSame(0, Contact::allWorkspaces()->count());
    }

    public function test_a_write_key_creates_and_partly_updates_records(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        $key = $this->keyFor($owner, $workspace);

        $id = $this->postJson('/api/v1/contacts', ['type' => 'customer', 'name' => 'Tendai Ncube', 'email' => 'tendai@example.com', 'tags' => ['vip', 'harare']], $this->bearer($key))
            ->assertCreated()->assertJsonPath('data.name', 'Tendai Ncube')->assertJsonPath('data.tags', ['vip', 'harare'])->json('data.id');

        $contact = Contact::allWorkspaces()->findOrFail($id);
        $this->assertSame($workspace->id, $contact->workspace_id);
        $this->assertSame('person', $contact->kind);

        $this->patchJson('/api/v1/contacts/'.$id, ['phone' => '+263 77 123 4567'], $this->bearer($key))
            ->assertOk()->assertJsonPath('data.phone', '+263 77 123 4567')->assertJsonPath('data.email', 'tendai@example.com');

        $taskId = $this->postJson('/api/v1/tasks', ['title' => 'Call Tendai back'], $this->bearer($key))
            ->assertCreated()->assertJsonPath('data.priority', 'normal')->json('data.id');
        $this->patchJson('/api/v1/tasks/'.$taskId, ['status' => 'done'], $this->bearer($key))->assertOk()->assertJsonPath('data.title', 'Call Tendai back');
        $this->assertSame('done', Task::allWorkspaces()->findOrFail($taskId)->status);

        $this->deleteJson('/api/v1/contacts/'.$id, [], $this->bearer($key))->assertNoContent();
        $this->assertNull(Contact::allWorkspaces()->find($id));
    }

    public function test_a_write_key_works_with_any_switched_on_apps_records(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        $workspace->enableModules(['payables'], $owner);
        $key = $this->keyFor($owner, $workspace->fresh());
        $supplier = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'ZESA']);

        $id = $this->postJson('/api/v1/apps/payables/bills', [
            'title' => 'Electricity', 'amount' => 300, 'contact_id' => $supplier->id, 'fields' => ['supplier_invoice_number' => 'INV-1', 'paid_amount' => 999],
        ], $this->bearer($key))->assertCreated()
            ->assertJsonPath('data.app', 'payables')->assertJsonPath('data.status', 'received')->assertJsonPath('data.amount', 300)
            ->assertJsonPath('data.fields.supplier_invoice_number', 'INV-1')->assertJsonPath('data.worked_out', ['paid_amount'])->json('data.id');
        $bill = Record::allWorkspaces()->findOrFail($id);
        $this->assertSame($workspace->id, $bill->workspace_id);
        $this->assertSame(0.0, (float) $bill->value('paid_amount'));

        $this->patchJson('/api/v1/apps/payables/bills/'.$id, ['fields' => ['vat' => 45]], $this->bearer($key))
            ->assertOk()->assertJsonPath('data.title', 'Electricity')->assertJsonPath('data.fields.vat', 45)->assertJsonPath('data.fields.supplier_invoice_number', 'INV-1');
        $this->getJson('/api/v1/apps/payables/bills?q=Electric', $this->bearer($key))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', $bill->number);
        $this->getJson('/api/v1/apps/payables/bills/'.$id, $this->bearer($key))->assertOk()->assertJsonPath('data.contact_id', $supplier->id);

        $this->postJson('/api/v1/apps/payables/bills', ['amount' => 'lots'], $this->bearer($key))->assertUnprocessable()->assertJsonValidationErrors(['title', 'amount', 'fields.supplier_invoice_number']);
        $this->postJson('/api/v1/apps/payables/bills', ['title' => 'Again', 'contact_id' => $supplier->id, 'fields' => ['supplier_invoice_number' => 'INV-1']], $this->bearer($key))
            ->assertUnprocessable()->assertJsonValidationErrors(['fields.supplier_invoice_number']);

        $this->getJson('/api/v1/apps/payables/nothing', $this->bearer($key))->assertNotFound();
        $this->getJson('/api/v1/apps/clinic/patients', $this->bearer($key))->assertForbidden();
        $this->getJson('/api/v1/apps/payables/payments/'.$id, $this->bearer($key))->assertNotFound();

        $this->deleteJson('/api/v1/apps/payables/bills/'.$id, [], $this->bearer($key))->assertNoContent();
        $this->assertNull(Record::allWorkspaces()->find($id));
    }

    public function test_a_read_key_reads_app_records_but_cannot_change_them(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        $workspace->enableModules(['payables'], $owner);
        $key = $this->keyFor($owner, $workspace->fresh(), 'read');
        $other = $this->ownerWithWorkspace()[1];
        Record::factory()->ofEntity('payables', 'bills', ['supplier_invoice_number' => 'X'])->create(['workspace_id' => $other->id, 'title' => 'Not mine']);
        $bill = Record::factory()->ofEntity('payables', 'bills', ['supplier_invoice_number' => 'INV-2'])->create(['workspace_id' => $workspace->id, 'title' => 'Water']);

        $this->getJson('/api/v1/apps/payables/bills', $this->bearer($key))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Water');
        $this->patchJson('/api/v1/apps/payables/bills/'.$bill->id, ['title' => 'Changed'], $this->bearer($key))->assertForbidden();
        $this->postJson('/api/v1/apps/payables/bills', ['title' => 'New'], $this->bearer($key))->assertForbidden();
        $this->assertSame('Water', $bill->fresh()->title);
    }

    public function test_validation_errors_come_back_as_json(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        $key = $this->keyFor($owner, $workspace);

        $this->postJson('/api/v1/contacts', ['type' => 'pirate', 'email' => 'not-an-email'], $this->bearer($key))
            ->assertUnprocessable()->assertJsonValidationErrors(['type', 'name', 'email']);
    }

    public function test_switched_off_modules_answer_with_json_forbidden(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts'], $owner);
        $key = $this->keyFor($owner, $workspace->fresh());

        $this->getJson('/api/v1/contacts', $this->bearer($key))->assertOk();
        $this->getJson('/api/v1/tasks', $this->bearer($key))->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_using_a_key_does_not_change_the_owners_current_workspace(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        [, $otherWorkspace] = $this->workspaceWithModules();
        $otherWorkspace->members()->attach($owner->id, ['role' => 'admin', 'joined_at' => now()]);
        $key = $this->keyFor($owner, $otherWorkspace);

        $this->getJson('/api/v1/me', $this->bearer($key))->assertOk()->assertJsonPath('data.workspace.id', $otherWorkspace->id);

        $this->assertSame($workspace->id, $owner->fresh()->current_workspace_id);
        $this->assertNotNull(ApiToken::sole()->last_used_at);
    }

    public function test_lists_are_paged_and_capped(): void
    {
        [$owner, $workspace] = $this->workspaceWithModules();
        app(WorkspaceContext::class)->set($workspace);
        Contact::factory()->count(3)->create(['workspace_id' => $workspace->id]);
        app(WorkspaceContext::class)->clear();
        $key = $this->keyFor($owner, $workspace, 'read');

        $this->getJson('/api/v1/contacts?per_page=2', $this->bearer($key))->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/v1/contacts?per_page=5000', $this->bearer($key))->assertOk()->assertJsonPath('meta.per_page', 100);
    }
}
