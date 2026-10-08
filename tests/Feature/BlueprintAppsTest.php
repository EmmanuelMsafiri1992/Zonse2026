<?php

namespace Tests\Feature;

use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Field;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class BlueprintAppsTest extends TestCase
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

    public function test_every_definition_parses_and_record_fields_point_at_real_entities(): void
    {
        $registry = app(BlueprintRegistry::class);

        $this->assertNotEmpty($registry->keys());
        foreach ($registry->all() as $app) {
            $this->assertNotEmpty($app->entities, $app->key.' has no entities');
            foreach ($app->entities as $entity) {
                $this->assertNotEmpty($entity->statuses, $app->key.'.'.$entity->key.' has no statuses');
                foreach ($entity->recordFields() as $field) {
                    $this->assertNotNull($app->entity($field->relatedEntity), $app->key.'.'.$entity->key.'.'.$field->key.' points nowhere');
                }
            }
        }
    }

    public function test_every_app_and_entity_screen_renders(): void
    {
        $registry = app(BlueprintRegistry::class);

        // Small batches keep each workspace's menu realistic (and the test fast).
        foreach (array_chunk($registry->all(), 10, true) as $apps) {
            [$owner, $workspace] = $this->ownerWithWorkspace();
            $workspace->enableModules([...array_keys($apps), 'contacts'], $owner);
            $this->actingAs($owner);

            foreach ($apps as $app) {
                $this->get(route('apps.show', $app->key))->assertOk();
                foreach ($app->entities as $entity) {
                    $this->get(route('apps.records.create', [$app->key, $entity->key]))->assertOk()->assertSee($entity->titleLabel);
                }
            }
        }
    }

    public function test_field_specs_parse_into_types_labels_and_rules(): void
    {
        $field = Field::parse('frequency:select=once_daily,as_needed|How often*');
        $this->assertSame('frequency', $field->key);
        $this->assertSame('select', $field->type);
        $this->assertSame('How often', $field->label);
        $this->assertTrue($field->required);
        $this->assertSame(['once_daily', 'as_needed'], array_keys($field->options));
        $this->assertContains('required', $field->rules());

        $plain = Field::parse('next_of_kin');
        $this->assertSame('text', $plain->type);
        $this->assertSame('Next of kin', $plain->label);
        $this->assertFalse($plain->required);

        $related = Field::parse('patient:record=patients|Patient*');
        $this->assertSame('record', $related->type);
        $this->assertSame('patients', $related->relatedEntity);
    }

    public function test_app_routes_require_the_app_to_be_enabled(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('apps.show', 'clinic'))->assertRedirect(route('settings.modules.index'));
        $this->actingAs($owner)->get(route('apps.records.index', ['clinic', 'patients']))->assertRedirect(route('settings.modules.index'));
        $this->actingAs($owner)->get('/apps/not-a-real-app')->assertNotFound();
    }

    public function test_records_are_numbered_validated_and_linked_to_each_other(): void
    {
        [$owner, $workspace] = $this->clinicWorkspace();
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Rudo Moyo', 'kind' => 'person', 'company_name' => null]);

        $this->actingAs($owner)->get(route('apps.records.create', ['clinic', 'patients']))->assertOk()->assertSee('Date of birth');

        $this->actingAs($owner)->post(route('apps.records.store', ['clinic', 'patients']), [
            'title' => 'Tendai Moyo', 'status' => 'active', 'contact_id' => $contact->id,
            'data' => ['date_of_birth' => '1990-04-12', 'sex' => 'female', 'medical_aid' => 'CIMAS', 'allergies' => 'Penicillin'],
        ])->assertRedirect();

        $patient = Record::query()->ofEntity('clinic', 'patients')->firstOrFail();
        $this->assertSame('PT-0001', $patient->number);
        $this->assertSame('CIMAS', $patient->value('medical_aid'));
        $this->assertSame($contact->id, $patient->contact_id);
        $this->assertSame($owner->id, $patient->created_by);

        $this->actingAs($owner)->from(route('apps.records.create', ['clinic', 'visits']))
            ->post(route('apps.records.store', ['clinic', 'visits']), ['title' => 'Headache', 'status' => 'waiting', 'data' => ['temperature' => 'hot']])
            ->assertRedirect(route('apps.records.create', ['clinic', 'visits']))
            ->assertSessionHasErrors(['data.patient', 'data.temperature']);

        $this->actingAs($owner)->post(route('apps.records.store', ['clinic', 'visits']), [
            'title' => 'Headache', 'status' => 'waiting', 'amount' => '25', 'occurs_on' => '2026-10-08',
            'data' => ['patient' => $patient->id, 'temperature' => '37.8', 'diagnosis' => 'Migraine'],
        ])->assertRedirect();

        $visit = Record::query()->ofEntity('clinic', 'visits')->firstOrFail();
        $this->assertSame('VIS-0001', $visit->number);
        $this->assertSame($patient->id, $visit->related('patient')?->id);

        $this->actingAs($owner)->get($patient->url())->assertOk()
            ->assertSee('PT-0001')->assertSee('Penicillin')->assertSee('Rudo Moyo')->assertSee('Headache')->assertSee('Visits');
        $this->actingAs($owner)->get($visit->url())->assertOk()->assertSee('Tendai Moyo')->assertSee('Migraine');
        $this->actingAs($owner)->get(route('apps.records.index', ['clinic', 'visits']))->assertOk()->assertSee('VIS-0001')->assertSee('Tendai Moyo');
        $this->actingAs($owner)->get(route('apps.show', 'clinic'))->assertOk()->assertSee('Headache');
        $this->actingAs($owner)->get(route('apps.records.edit', ['clinic', 'visits', $visit->id]))->assertOk()->assertSee('Migraine');
    }

    public function test_a_record_field_cannot_point_at_another_workspace(): void
    {
        [$owner] = $this->clinicWorkspace();
        [, $otherWorkspace] = $this->ownerWithWorkspace();
        $foreign = Record::factory()->ofEntity('clinic', 'patients')->create(['workspace_id' => $otherWorkspace->id, 'title' => 'Someone else']);

        $this->actingAs($owner)->post(route('apps.records.store', ['clinic', 'visits']), [
            'title' => 'Checkup', 'status' => 'waiting', 'data' => ['patient' => $foreign->id],
        ])->assertSessionHasErrors('data.patient');

        $this->assertSame(0, Record::query()->withoutGlobalScopes()->where('entity', 'visits')->count());
        $this->actingAs($owner)->get(route('apps.records.show', ['clinic', 'patients', $foreign->id]))->assertNotFound();
    }

    public function test_status_notes_search_export_and_delete(): void
    {
        [$owner, $workspace] = $this->clinicWorkspace();
        $kept = Record::factory()->ofEntity('clinic', 'patients', ['medical_aid' => 'PSMAS'])->create(['workspace_id' => $workspace->id, 'title' => 'Farai Chikore', 'status' => 'active']);
        $other = Record::factory()->ofEntity('clinic', 'patients')->create(['workspace_id' => $workspace->id, 'title' => 'Blessing Ncube', 'status' => 'active']);

        $this->actingAs($owner)->post(route('apps.records.status', ['clinic', 'patients', $kept->id]), ['status' => 'inactive'])->assertRedirect();
        $this->assertSame('inactive', $kept->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.status', ['clinic', 'patients', $kept->id]), ['status' => 'bogus'])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post(route('apps.records.comments.store', ['clinic', 'patients', $kept->id]), ['body' => 'Moved to Bulawayo'])->assertRedirect();
        $this->actingAs($owner)->get($kept->url())->assertSee('Moved to Bulawayo');

        $this->actingAs($owner)->get(route('apps.records.index', ['clinic', 'patients', 'q' => 'psmas']))->assertOk()
            ->assertSee('Farai Chikore')->assertDontSee('Blessing Ncube');
        $this->actingAs($owner)->get(route('apps.records.index', ['clinic', 'patients', 'status' => 'active']))->assertOk()
            ->assertSee('Blessing Ncube')->assertDontSee('Farai Chikore');

        $csv = $this->actingAs($owner)->get(route('apps.records.export', ['clinic', 'patients']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Full name', $csv);
        $this->assertStringContainsString('Farai Chikore', $csv);
        $this->assertStringContainsString('PSMAS', $csv);

        $this->actingAs($owner)->delete(route('apps.records.destroy', ['clinic', 'patients', $other->id]))
            ->assertRedirect(route('apps.records.index', ['clinic', 'patients']));
        $this->assertSoftDeleted($other);
    }

    public function test_viewers_can_read_but_not_write(): void
    {
        [, $workspace] = $this->clinicWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer');
        $patient = Record::factory()->ofEntity('clinic', 'patients')->create(['workspace_id' => $workspace->id, 'title' => 'Read Only']);

        $this->actingAs($viewer)->get($patient->url())->assertOk()->assertSee('Read Only')->assertDontSee('Move to');
        $this->actingAs($viewer)->get(route('apps.records.create', ['clinic', 'patients']))->assertForbidden();
        $this->actingAs($viewer)->post(route('apps.records.store', ['clinic', 'patients']), ['title' => 'Nope', 'status' => 'active'])->assertForbidden();
        $this->actingAs($viewer)->delete(route('apps.records.destroy', ['clinic', 'patients', $patient->id]))->assertForbidden();
    }

    public function test_the_apps_index_and_dashboard_widget_show_enabled_apps(): void
    {
        [$owner, $workspace] = $this->clinicWorkspace();
        Record::factory()->ofEntity('clinic', 'patients')->create(['workspace_id' => $workspace->id, 'title' => 'Widget Patient']);

        $this->actingAs($owner)->get(route('apps.index'))->assertOk()->assertSee('Clinic &amp; patients', false)->assertDontSee('Driving school');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Widget Patient');
    }
}
