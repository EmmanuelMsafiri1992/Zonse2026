<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\CustomField;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The workspace's own extra fields: managing them, filling them in on forms and the API, and keeping them private. */
class CustomFieldsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'tasks', 'helpdesk', 'appointments'], $owner);

        return [$owner, $workspace->fresh()];
    }

    /** @param array<string, mixed> $attributes */
    protected function field(Workspace $workspace, array $attributes = []): CustomField
    {
        return CustomField::factory()->create(['workspace_id' => $workspace->id] + $attributes);
    }

    /** @return array<string, mixed> */
    protected function contactPayload(array $overrides = []): array
    {
        return array_merge(['type' => 'customer', 'kind' => 'person', 'name' => 'Tendai Moyo', 'is_active' => 1], $overrides);
    }

    public function test_an_admin_can_add_edit_reorder_and_remove_fields(): void
    {
        [$owner, $workspace] = $this->workspace();

        $this->actingAs($owner)->get(route('settings.custom-fields.index'))->assertOk()->assertSee('No extra fields on contacts yet.');
        $this->get(route('settings.custom-fields.create', ['entity' => 'ticket']))->assertOk();

        $this->post(route('settings.custom-fields.store'), ['entity' => 'contact', 'label' => 'Medical aid number', 'type' => 'text', 'is_required' => '1', 'help' => 'As printed on the card'])
            ->assertRedirect(route('settings.custom-fields.index'));
        $this->post(route('settings.custom-fields.store'), ['entity' => 'contact', 'label' => 'Membership', 'type' => 'select', 'options' => "Gold\nSilver\n\nGold\n Bronze "])
            ->assertRedirect(route('settings.custom-fields.index'));

        [$aid, $membership] = CustomField::query()->orderBy('position')->get()->all();
        $this->assertSame(['medical_aid_number', 1, true], [$aid->key, $aid->position, $aid->is_required]);
        $this->assertSame(['membership', 2, ['Gold', 'Silver', 'Bronze']], [$membership->key, $membership->position, $membership->options]);

        $this->post(route('settings.custom-fields.move', $membership), ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['membership', 'medical_aid_number'], CustomField::query()->orderBy('position')->pluck('key')->all());

        $this->get(route('settings.custom-fields.edit', $aid))->assertOk()->assertSee('Medical aid number');
        $this->put(route('settings.custom-fields.update', $aid), ['label' => 'Medical aid no.', 'type' => 'text', 'is_required' => '0'])
            ->assertRedirect(route('settings.custom-fields.index'));
        $this->assertSame(['Medical aid no.', 'medical_aid_number', false], [$aid->fresh()->label, $aid->fresh()->key, $aid->fresh()->is_required]);

        $this->delete(route('settings.custom-fields.destroy', $aid))->assertRedirect(route('settings.custom-fields.index'));
        $this->assertSame(['membership'], CustomField::query()->pluck('key')->all());
    }

    public function test_field_definitions_are_validated(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->field($workspace, ['label' => 'Allergies']);

        $this->actingAs($owner)->post(route('settings.custom-fields.store'), ['entity' => 'invoice', 'label' => '', 'type' => 'colour'])
            ->assertSessionHasErrors(['entity', 'label', 'type']);
        $this->post(route('settings.custom-fields.store'), ['entity' => 'contact', 'label' => 'Allergies', 'type' => 'select', 'options' => "\n"])
            ->assertSessionHasErrors(['label', 'options']);

        $same = $this->field($workspace, ['label' => 'Size']);
        $this->put(route('settings.custom-fields.update', $same), ['entity' => 'ticket', 'label' => 'Size', 'type' => 'text'])
            ->assertSessionHasErrors('entity');

        $this->post(route('settings.custom-fields.store'), ['entity' => 'ticket', 'label' => 'Allergies', 'type' => 'text'])->assertSessionHasNoErrors();
        $this->assertSame(3, CustomField::count());
    }

    public function test_forms_show_validate_and_save_the_fields(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->field($workspace, ['label' => 'Medical aid number', 'is_required' => true, 'help' => 'As printed on the card']);
        $this->field($workspace, ['label' => 'Membership', 'type' => 'select', 'options' => ['Gold', 'Silver']]);
        $this->field($workspace, ['label' => 'Visits', 'type' => 'number']);
        $this->field($workspace, ['label' => 'Joined', 'type' => 'date']);
        $this->field($workspace, ['label' => 'Wants reminders', 'type' => 'checkbox']);
        $this->field($workspace, ['label' => 'Website', 'type' => 'url']);
        $this->field($workspace, ['entity' => 'ticket', 'label' => 'Vehicle registration']);

        $this->actingAs($owner)->get(route('contacts.create'))->assertOk()
            ->assertSee('More details')->assertSee('name="custom[medical_aid_number]"', false)->assertSee('As printed on the card')
            ->assertSee('Gold')->assertDontSee('Vehicle registration');

        $this->post(route('contacts.store'), $this->contactPayload(['custom' => [
            'medical_aid_number' => '', 'membership' => 'Platinum', 'visits' => 'many', 'joined' => '31/12/2025', 'website' => 'javascript:alert(1)',
        ]]))->assertSessionHasErrors(['custom.medical_aid_number', 'custom.membership', 'custom.visits', 'custom.joined', 'custom.website']);
        $this->assertSame(0, Contact::count());

        $this->post(route('contacts.store'), $this->contactPayload(['custom' => [
            'medical_aid_number' => ' CIMAS-123 ', 'membership' => 'Gold', 'visits' => '12', 'joined' => '2025-12-31', 'wants_reminders' => '1', 'website' => '', 'stray' => 'ignored',
        ]]))->assertSessionHasNoErrors();

        $contact = Contact::sole();
        $this->assertSame(['medical_aid_number' => 'CIMAS-123', 'membership' => 'Gold', 'visits' => 12, 'joined' => '2025-12-31', 'wants_reminders' => true], $contact->custom_fields);

        $this->get(route('contacts.show', $contact))->assertOk()
            ->assertSeeInOrder(['More details', 'Medical aid number', 'CIMAS-123', 'Membership', 'Gold', 'Joined', '31 Dec 2025', 'Wants reminders', 'Yes']);
        $this->get(route('contacts.edit', $contact))->assertOk()->assertSee('value="CIMAS-123"', false);

        $this->put(route('contacts.update', $contact), $this->contactPayload(['custom' => ['medical_aid_number' => 'CIMAS-999', 'wants_reminders' => '0']]))->assertSessionHasNoErrors();
        $this->assertSame(['medical_aid_number' => 'CIMAS-999', 'membership' => 'Gold', 'visits' => 12, 'joined' => '2025-12-31', 'wants_reminders' => false], $contact->fresh()->custom_fields);
    }

    public function test_tickets_tasks_and_appointments_carry_their_own_fields(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->field($workspace, ['entity' => 'ticket', 'label' => 'Vehicle registration', 'is_required' => true]);
        $this->field($workspace, ['entity' => 'task', 'label' => 'Cost centre']);
        $this->field($workspace, ['entity' => 'appointment', 'label' => 'Room']);

        $this->actingAs($owner)->get(route('tickets.create'))->assertOk()->assertSee('Vehicle registration');
        $this->get(route('tasks.create'))->assertOk()->assertSee('Cost centre');
        $this->get(route('appointments.create'))->assertOk()->assertSee('Room');

        $ticket = ['subject' => 'Brakes squeal', 'requester_name' => 'Rudo', 'channel' => 'phone', 'priority' => 'normal'];
        $this->post(route('tickets.store'), $ticket)->assertSessionHasErrors('custom.vehicle_registration');
        $this->post(route('tickets.store'), $ticket + ['custom' => ['vehicle_registration' => 'AEZ 1234']])->assertSessionHasNoErrors();

        $saved = Ticket::sole();
        $this->assertSame('AEZ 1234', $saved->customField('vehicle_registration'));
        $this->get(route('tickets.show', $saved))->assertOk()->assertSee('AEZ 1234');

        // A quick change elsewhere that does not send the fields leaves them alone.
        $saved->update(['priority' => 'high']);
        $this->assertSame('AEZ 1234', $saved->fresh()->customField('vehicle_registration'));
    }

    public function test_the_contact_export_adds_a_column_per_field(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->field($workspace, ['label' => 'Medical aid number']);
        $this->field($workspace, ['label' => 'Wants reminders', 'type' => 'checkbox']);
        Contact::factory()->for($workspace)->create(['name' => 'Tendai Moyo', 'custom_fields' => ['medical_aid_number' => 'CIMAS-123', 'wants_reminders' => true]]);

        $csv = $this->actingAs($owner)->get(route('contacts.export'))->assertOk()->streamedContent();

        [$header, $row] = array_map('str_getcsv', array_slice(preg_split('/\r?\n/', trim($csv)), 0, 2));
        $this->assertSame(['Medical aid number', 'Wants reminders'], array_slice($header, -2));
        $this->assertSame(['CIMAS-123', 'Yes'], array_slice($row, -2));
    }

    public function test_the_api_reads_and_writes_custom_fields(): void
    {
        [$owner, $workspace] = $this->workspace();
        $this->field($workspace, ['label' => 'Medical aid number', 'is_required' => true]);
        $this->field($workspace, ['label' => 'Visits', 'type' => 'number']);
        $token = $owner->createToken('Test key', ApiToken::ACCESS['write']['abilities']);
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();
        $headers = ['Authorization' => 'Bearer '.$token->plainTextToken, 'Accept' => 'application/json'];

        $this->postJson('/api/v1/contacts', ['type' => 'customer', 'name' => 'Tendai'], $headers)->assertUnprocessable()->assertJsonValidationErrors('custom.medical_aid_number');

        $id = $this->postJson('/api/v1/contacts', ['type' => 'customer', 'name' => 'Tendai', 'custom_fields' => ['medical_aid_number' => 'PSMAS-1']], $headers)
            ->assertCreated()->assertJsonPath('data.custom_fields', ['medical_aid_number' => 'PSMAS-1', 'visits' => null])->json('data.id');

        $this->patchJson('/api/v1/contacts/'.$id, ['custom_fields' => ['visits' => 3]], $headers)->assertOk()
            ->assertJsonPath('data.custom_fields', ['medical_aid_number' => 'PSMAS-1', 'visits' => 3]);
        $this->patchJson('/api/v1/contacts/'.$id, ['city' => 'Harare'], $headers)->assertOk()
            ->assertJsonPath('data.custom_fields.medical_aid_number', 'PSMAS-1');
    }

    public function test_fields_stay_inside_their_workspace_and_members_cannot_manage_them(): void
    {
        [$owner, $workspace] = $this->workspace();
        $member = $this->memberOf($workspace);
        $field = $this->field($workspace, ['label' => 'Medical aid number', 'is_required' => true]);
        [$otherOwner, $other] = $this->workspace();

        $this->actingAs($member)->get(route('settings.custom-fields.index'))->assertForbidden();
        $this->post(route('settings.custom-fields.store'), ['entity' => 'contact', 'label' => 'X', 'type' => 'text'])->assertForbidden();

        $this->actingAs($otherOwner)->get(route('settings.custom-fields.edit', $field))->assertNotFound();
        $this->get(route('contacts.create'))->assertOk()->assertDontSee('Medical aid number');
        $this->post(route('contacts.store'), $this->contactPayload())->assertSessionHasNoErrors();
        $this->assertSame([], Contact::allWorkspaces()->where('workspace_id', $other->id)->sole()->custom_fields);

        // Members still fill the fields in on records.
        app(WorkspaceContext::class)->clear();
        $this->actingAs($member)->post(route('contacts.store'), $this->contactPayload(['custom' => ['medical_aid_number' => 'M-1']]))->assertSessionHasNoErrors();
        $this->assertSame('M-1', Contact::allWorkspaces()->where('workspace_id', $workspace->id)->sole()->customField('medical_aid_number'));
    }
}
