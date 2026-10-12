<?php

namespace Tests\Feature;

use App\Blueprints\BlueprintRegistry;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Fields an app works out itself are shown on the form without an input, and typed values for them are ignored. */
class ComputedFieldsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_every_worked_out_field_names_a_real_field(): void
    {
        $unknown = [];
        foreach (app(BlueprintRegistry::class)->all() as $key => $blueprint) {
            foreach ($blueprint->entities as $entity) {
                foreach ($blueprint->logic()->computed($entity) as $field) {
                    $exists = $field === 'amount' ? $entity->hasAmount() : $entity->field($field) !== null;
                    if (! $exists) {
                        $unknown[] = $key.'.'.$entity->key.'.'.$field;
                    }
                }
            }
        }

        $this->assertSame([], $unknown);
    }

    public function test_a_worked_out_field_is_shown_but_not_editable(): void
    {
        [$owner, $workspace] = $this->appWorkspace('payables');
        $supplier = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'ZESA']);
        $bill = Record::factory()->ofEntity('payables', 'bills', ['supplier_invoice_number' => 'INV-1', 'paid_amount' => 40])
            ->create(['workspace_id' => $workspace->id, 'title' => 'Electricity', 'status' => 'approved', 'amount' => 300, 'contact_id' => $supplier->id]);

        $this->actingAs($owner)->get(route('apps.records.create', ['payables', 'bills']))->assertOk()
            ->assertDontSee('name="data[paid_amount]"', false)->assertSee('Amount paid')->assertSee('Worked out once saved');
        $this->actingAs($owner)->get($bill->url().'/edit')->assertOk()
            ->assertDontSee('name="data[paid_amount]"', false)->assertSee('Worked out by the app.')
            ->assertSee('name="data[vat]"', false);

        $this->actingAs($owner)->put($bill->url(), [
            'title' => 'Electricity', 'status' => 'approved', 'amount' => 300, 'contact_id' => $supplier->id,
            'data' => ['supplier_invoice_number' => 'INV-1', 'vat' => 45, 'paid_amount' => 999],
        ])->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame(45.0, (float) $bill->value('vat'));
        $this->assertNotEquals(999.0, (float) $bill->value('paid_amount'));
    }

    public function test_a_worked_out_amount_is_shown_but_not_editable(): void
    {
        [$owner, $workspace] = $this->appWorkspace('budgeting');

        $this->actingAs($owner)->get(route('apps.records.create', ['budgeting', 'budgets']))->assertOk()
            ->assertDontSee('name="amount"', false);

        $this->actingAs($owner)->post(route('apps.records.store', ['budgeting', 'budgets']), [
            'title' => '2027 operating budget', 'status' => 'draft', 'amount' => 5000, 'data' => ['period_start' => '2027-01-01', 'period_end' => '2027-12-31'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(0.0, (float) Record::query()->ofEntity('budgeting', 'budgets')->firstOrFail()->amount);
    }

    public function test_a_field_can_be_typed_on_a_new_record_and_worked_out_after(): void
    {
        [$owner] = $this->appWorkspace('loyalty');

        $this->actingAs($owner)->get(route('apps.records.create', ['loyalty', 'members']))->assertOk()
            ->assertSee('name="data[points]"', false);
        $this->actingAs($owner)->post(route('apps.records.store', ['loyalty', 'members']), [
            'title' => 'Tendai Moyo', 'status' => 'active', 'data' => ['points' => 120],
        ])->assertSessionHasNoErrors();
        $member = Record::query()->ofEntity('loyalty', 'members')->firstOrFail();
        $this->assertSame(120.0, (float) $member->value('points'));

        $this->actingAs($owner)->get($member->url().'/edit')->assertOk()->assertDontSee('name="data[points]"', false);
        $this->actingAs($owner)->put($member->url(), ['title' => 'Tendai Moyo', 'status' => 'active', 'data' => ['points' => 9000]])->assertSessionHasNoErrors();
        $this->assertSame(120.0, (float) $member->fresh()->value('points'));
    }

    /** @return array{0: User, 1: Workspace} */
    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }
}
