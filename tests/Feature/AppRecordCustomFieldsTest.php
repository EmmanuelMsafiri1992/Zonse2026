<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The workspace's own extra fields on the records of blueprint apps (clinic patients, school pupils and so on). */
class AppRecordCustomFieldsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function clinicWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['clinic', 'contacts'], $owner);

        return [$owner, $workspace->fresh()];
    }

    public function test_an_admin_can_add_fields_to_an_apps_records(): void
    {
        [$owner] = $this->clinicWorkspace();

        $this->actingAs($owner)->get(route('settings.custom-fields.index'))->assertOk()
            ->assertSee('Your apps')->assertSee('No extra fields on patients yet.');
        $this->get(route('settings.custom-fields.create', ['entity' => 'clinic.patients']))->assertOk()
            ->assertSee('<option value="clinic.patients" selected', false);

        $this->post(route('settings.custom-fields.store'), ['entity' => 'clinic.patients', 'label' => 'Next of kin', 'type' => 'text', 'is_required' => '1'])
            ->assertRedirect(route('settings.custom-fields.index'));

        $field = CustomField::query()->firstOrFail();
        $this->assertSame(['clinic.patients', 'next_of_kin'], [$field->entity, $field->key]);
        $this->assertStringContainsString('Patients', $field->entityLabel());
        $this->get(route('settings.custom-fields.index'))->assertSee('Next of kin');
    }

    public function test_fields_can_only_target_apps_that_are_switched_on(): void
    {
        [$owner] = $this->clinicWorkspace();

        foreach (['school.pupils', 'clinic.nonsense', 'nowhere'] as $entity) {
            $this->actingAs($owner)->post(route('settings.custom-fields.store'), ['entity' => $entity, 'label' => 'Shoe size', 'type' => 'text'])
                ->assertSessionHasErrors('entity');
        }

        $this->assertSame(0, CustomField::query()->count());
    }

    public function test_record_forms_show_validate_save_and_display_the_fields(): void
    {
        [$owner, $workspace] = $this->clinicWorkspace();
        CustomField::factory()->create(['workspace_id' => $workspace->id, 'entity' => 'clinic.patients', 'label' => 'Next of kin', 'key' => 'next_of_kin', 'type' => 'text', 'is_required' => true]);
        CustomField::factory()->create(['workspace_id' => $workspace->id, 'entity' => 'clinic.visits', 'label' => 'Room', 'key' => 'room', 'type' => 'text']);

        $this->actingAs($owner)->get(route('apps.records.create', ['clinic', 'patients']))->assertOk()
            ->assertSee('Next of kin')->assertSee('custom[next_of_kin]', false)->assertDontSee('custom[room]', false);

        $this->post(route('apps.records.store', ['clinic', 'patients']), ['title' => 'Tendai Moyo', 'status' => 'active'])
            ->assertSessionHasErrors('custom.next_of_kin');
        $this->assertSame(0, Record::query()->count());

        $this->post(route('apps.records.store', ['clinic', 'patients']), [
            'title' => 'Tendai Moyo', 'status' => 'active', 'custom' => ['next_of_kin' => 'Rudo Moyo (sister)'],
        ])->assertRedirect();

        $patient = Record::query()->firstOrFail();
        $this->assertSame('Rudo Moyo (sister)', $patient->customField('next_of_kin'));

        $this->get($patient->url())->assertOk()->assertSee('Next of kin')->assertSee('Rudo Moyo (sister)');
        $this->get(route('apps.records.edit', ['clinic', 'patients', $patient->id]))->assertOk()->assertSee('Rudo Moyo (sister)');
        $this->get(route('apps.records.index', ['clinic', 'patients', 'q' => 'sister']))->assertOk()->assertSee('Tendai Moyo');

        $this->put(route('apps.records.update', ['clinic', 'patients', $patient->id]), [
            'title' => 'Tendai Moyo', 'status' => 'active', 'custom' => ['next_of_kin' => 'Farai Moyo'],
        ])->assertRedirect();
        $this->assertSame('Farai Moyo', $patient->fresh()->customField('next_of_kin'));

        $csv = $this->get(route('apps.records.export', ['clinic', 'patients']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Next of kin', $csv);
        $this->assertStringContainsString('Farai Moyo', $csv);
    }

    public function test_fields_from_another_workspace_never_appear(): void
    {
        [$owner] = $this->clinicWorkspace();
        [, $other] = $this->clinicWorkspace();
        CustomField::factory()->create(['workspace_id' => $other->id, 'entity' => 'clinic.patients', 'label' => 'Secret code', 'key' => 'secret_code', 'type' => 'text', 'is_required' => true]);

        $this->actingAs($owner)->get(route('apps.records.create', ['clinic', 'patients']))->assertOk()->assertDontSee('Secret code');
        $this->post(route('apps.records.store', ['clinic', 'patients']), ['title' => 'Tendai Moyo', 'status' => 'active'])
            ->assertSessionHasNoErrors()->assertRedirect();
    }
}
