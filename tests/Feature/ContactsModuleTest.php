<?php

namespace Tests\Feature;

use App\Models\Module;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class ContactsModuleTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array<string, mixed> */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'customer', 'kind' => 'person', 'name' => 'Tendai Moyo', 'email' => 'tendai@example.com',
            'phone' => '+263 77 123 4567', 'city' => 'Harare', 'country_code' => 'ZW', 'currency_code' => 'USD',
            'tags' => 'vip, wholesale', 'is_active' => 1,
        ], $overrides);
    }

    public function test_contacts_index_lists_only_the_current_workspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Contact::factory()->for($workspace)->create(['name' => 'Mine Only', 'kind' => 'person', 'company_name' => null]);
        Contact::factory()->create(['name' => 'Elsewhere Ltd', 'kind' => 'person', 'company_name' => null]);

        $this->actingAs($owner)->get(route('contacts.index'))
            ->assertOk()
            ->assertSee('Mine Only')
            ->assertDontSee('Elsewhere Ltd');
    }

    public function test_member_can_create_show_update_and_search_a_contact(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($member)->get(route('contacts.create'))->assertOk();

        $this->actingAs($member)->post(route('contacts.store'), $this->validPayload())
            ->assertRedirect();

        $contact = Contact::query()->firstWhere('email', 'tendai@example.com');
        $this->assertNotNull($contact);
        $this->assertSame($workspace->id, $contact->workspace_id);
        $this->assertSame($member->id, $contact->created_by);
        $this->assertSame(['vip', 'wholesale'], $contact->tags);

        $this->actingAs($member)->get(route('contacts.show', $contact))->assertOk()->assertSee('Tendai Moyo')->assertSee('vip');

        $this->actingAs($member)->put(route('contacts.update', $contact), $this->validPayload(['name' => 'Tendai M. Moyo', 'kind' => 'company', 'company_name' => 'Moyo Traders']))
            ->assertRedirect(route('contacts.show', $contact));

        $this->assertSame('Moyo Traders', $contact->fresh()->displayName());

        $this->actingAs($member)->get(route('contacts.index', ['q' => 'Moyo Trad']))->assertOk()->assertSee('Moyo Traders');
        $this->actingAs($member)->get(route('contacts.index', ['q' => 'zzz-nothing']))->assertOk()->assertSee('No contacts found');
    }

    public function test_validation_rejects_bad_input(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->from(route('contacts.create'))
            ->post(route('contacts.store'), ['type' => 'alien', 'kind' => 'company', 'name' => '', 'email' => 'not-an-email'])
            ->assertRedirect(route('contacts.create'))
            ->assertSessionHasErrors(['type', 'name', 'email', 'company_name']);
    }

    public function test_viewer_cannot_create_or_edit_but_can_read(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer');
        $contact = Contact::factory()->for($workspace)->create();

        $this->actingAs($viewer)->get(route('contacts.show', $contact))->assertOk();
        $this->actingAs($viewer)->get(route('contacts.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('contacts.store'), $this->validPayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('contacts.update', $contact), $this->validPayload())->assertForbidden();
    }

    public function test_only_managers_and_admins_can_delete(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $member = $this->memberOf($workspace, 'member');
        $manager = $this->memberOf($workspace, 'manager');
        $contact = Contact::factory()->for($workspace)->create();

        $this->actingAs($member)->delete(route('contacts.destroy', $contact))->assertForbidden();
        $this->actingAs($manager)->delete(route('contacts.destroy', $contact))->assertRedirect(route('contacts.index'));
        $this->assertSoftDeleted($contact);
    }

    public function test_contacts_from_another_workspace_are_not_reachable(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $foreign = Contact::factory()->create();

        $this->actingAs($owner)->get(route('contacts.show', $foreign))->assertNotFound();
        $this->actingAs($owner)->put(route('contacts.update', $foreign), $this->validPayload())->assertNotFound();
    }

    public function test_notes_can_be_added_and_activity_is_logged_for_the_workspace(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Logged Person', 'kind' => 'person', 'company_name' => null]);

        $this->actingAs($owner)->post(route('contacts.comments.store', $contact), ['body' => 'Called about the quote.'])
            ->assertRedirect();

        $this->assertSame(1, $contact->comments()->count());
        $this->assertSame($workspace->id, $contact->comments()->first()->workspace_id);

        $this->actingAs($owner)->get(route('contacts.show', $contact))->assertOk()->assertSee('Called about the quote.');

        $activity = Activity::query()->latest('id')->first();
        $this->assertNotNull($activity);
        $this->assertSame($workspace->id, $activity->properties['workspace_id']);
        $this->assertStringContainsString('Logged Person', $activity->description);
    }

    public function test_export_streams_a_csv_of_the_filtered_list(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Contact::factory()->for($workspace)->supplier()->create(['name' => 'Supplier Sam', 'kind' => 'person', 'company_name' => null]);
        Contact::factory()->for($workspace)->customer()->create(['name' => 'Customer Cathy', 'kind' => 'person', 'company_name' => null]);

        $response = $this->actingAs($owner)->get(route('contacts.export', ['type' => 'supplier']));
        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Supplier Sam', $csv);
        $this->assertStringNotContainsString('Customer Cathy', $csv);
    }

    public function test_global_search_finds_contacts(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Contact::factory()->for($workspace)->create(['name' => 'Findable Fran', 'kind' => 'person', 'company_name' => null, 'email' => 'fran@example.com']);

        $this->actingAs($owner)->get(route('search', ['q' => 'fran@example']))->assertOk()->assertSee('Findable Fran');
        $this->actingAs($owner)->get(route('search', ['q' => 'nobody-here']))->assertOk()->assertSee('No matches');
    }

    public function test_dashboard_shows_the_recent_contacts_widget_and_links_to_the_app(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        Contact::factory()->for($workspace)->create(['name' => 'Widget Wendy', 'kind' => 'person', 'company_name' => null]);

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Recent contacts')->assertSee('Widget Wendy');
    }

    public function test_sync_command_marks_the_contacts_catalogue_row_as_installed(): void
    {
        $this->artisan('zonseo:sync-modules')->assertSuccessful();

        $this->assertTrue(Module::findByKey('contacts')->is_installed);
    }
}
