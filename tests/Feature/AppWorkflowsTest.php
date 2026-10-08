<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The real workflows behind the first six apps: billing, payments, actions, documents, reports and the daily run. */
class AppWorkflowsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param  array<string, mixed>  $data  @param  array<string, mixed>  $attributes */
    protected function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create(['workspace_id' => $workspace->id, 'title' => $title, 'status' => $status, ...$attributes]);
    }

    public function test_apps_pull_in_contacts_and_invoicing(): void
    {
        [, $workspace] = $this->appWorkspace('clinic');

        $this->assertTrue($workspace->hasModule('invoicing'));
        $this->assertTrue($workspace->hasModule('contacts'));
    }

    public function test_clinic_bills_a_visit_to_the_patient_and_takes_payment(): void
    {
        [$owner, $workspace] = $this->appWorkspace('clinic');
        $patient = $this->record($workspace, 'clinic', 'patients', 'Tendai Moyo', 'active', ['phone' => '+263771000000', 'allergies' => 'Penicillin']);

        $this->actingAs($owner)->post(route('apps.records.store', ['clinic', 'visits']), [
            'title' => 'Headache', 'status' => 'waiting', 'amount' => 50, 'occurs_on' => today()->toDateString(),
            'data' => ['patient' => $patient->id, 'diagnosis' => "Migraine\nRest advised"],
        ])->assertRedirect();
        $visit = Record::query()->ofEntity('clinic', 'visits')->firstOrFail();

        $this->actingAs($owner)->get($visit->url())->assertOk()->assertSee('Penicillin')->assertSee('Create invoice');
        $this->actingAs($owner)->get(route('apps.show', 'clinic'))->assertOk()->assertSee('Waiting room')->assertSee('Tendai Moyo');

        $this->actingAs($owner)->post(route('apps.records.bill', ['clinic', 'visits', $visit->id]))->assertSessionHasNoErrors();
        $invoice = $visit->openInvoice();
        $this->assertNotNull($invoice);
        $this->assertSame(50.0, (float) $invoice->total);
        $this->assertSame('Tendai Moyo', $invoice->contact->name);
        $this->assertSame($invoice->contact_id, $patient->fresh()->contact_id, 'the new contact is linked back to the patient');

        // A second invoice is refused while one is open.
        $this->actingAs($owner)->post(route('apps.records.bill', ['clinic', 'visits', $visit->id]))->assertSessionHasErrors('billing');

        $this->actingAs($owner)->post(route('apps.records.payments.store', ['clinic', 'visits', $visit->id]), [
            'invoice_id' => $invoice->id, 'amount' => 50, 'method' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', $invoice->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', 'clinic'))->assertOk()->assertSee('Migraine');
    }

    public function test_school_bills_a_class_for_a_term_and_fee_status_follows_payments(): void
    {
        [$owner, $workspace] = $this->appWorkspace('school');
        $class = $this->record($workspace, 'school', 'classes', 'Form 1A', 'active', ['grade' => 'Form 1']);
        $this->record($workspace, 'school', 'students', 'Rudo Banda', 'enrolled', ['class' => $class->id, 'guardian_name' => 'Grace Banda', 'guardian_phone' => '+263772000000']);
        $this->record($workspace, 'school', 'students', 'Tafadzwa Ncube', 'enrolled', ['class' => $class->id, 'guardian_name' => 'Peter Ncube']);
        $this->record($workspace, 'school', 'students', 'Left Already', 'left', ['class' => $class->id]);

        $bill = ['term' => 'term_1', 'description' => 'Tuition fees', 'amount' => 300, 'due_on' => today()->addDays(14)->toDateString()];
        $this->actingAs($owner)->post(route('apps.records.action', ['school', 'classes', $class->id, 'bill_term']), $bill)
            ->assertSessionHas('flash.message', 'Billed 2 students in Form 1A for Term 1.');
        $this->actingAs($owner)->post(route('apps.records.action', ['school', 'classes', $class->id, 'bill_term']), $bill)
            ->assertSessionHas('flash.message', 'Billed 0 students in Form 1A for Term 1 (2 already billed).');

        $fees = Record::query()->ofEntity('school', 'fees')->get();
        $this->assertCount(2, $fees);
        $this->assertSame(2, Invoice::query()->whereIn('record_id', $fees->pluck('id'))->count());
        $this->assertTrue(Contact::query()->where('name', 'Grace Banda')->where('phone', '+263772000000')->exists(), 'the guardian becomes the paying contact');

        $fee = $fees->first();
        $invoice = $fee->openInvoice();
        $this->actingAs($owner)->post(route('apps.records.payments.store', ['school', 'fees', $fee->id]), ['invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'mobile_money']);
        $this->assertSame('part_paid', $fee->fresh()->status);
        $this->assertSame(100.0, (float) $fee->fresh()->value('paid_to_date'));

        $this->actingAs($owner)->post(route('apps.records.payments.store', ['school', 'fees', $fee->id]), ['invoice_id' => $invoice->id, 'amount' => 200, 'method' => 'cash']);
        $this->assertSame('paid', $fee->fresh()->status);

        $this->actingAs($owner)->get($class->url())->assertOk()->assertSee('Class fees');
        $this->actingAs($owner)->get(route('apps.reports', 'school'))->assertOk()->assertSee('Term 1');
    }

    public function test_rentals_keep_one_live_lease_per_unit_and_bill_rent_once_a_month(): void
    {
        [$owner, $workspace] = $this->appWorkspace('tenants');
        $tenant = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Chipo Dube']);
        $property = $this->record($workspace, 'tenants', 'properties', 'Avondale Flats', 'active', ['address' => '1 King George Rd']);
        $unit = $this->record($workspace, 'tenants', 'units', '2B', 'vacant', ['property' => $property->id], ['amount' => 450]);

        $lease = [
            'title' => 'Chipo Dube · 2B', 'status' => 'active', 'contact_id' => $tenant->id, 'amount' => 450,
            'occurs_on' => today()->subMonths(2)->toDateString(), 'data' => ['unit' => $unit->id, 'payment_day' => 5],
        ];
        $this->actingAs($owner)->post(route('apps.records.store', ['tenants', 'leases']), $lease)->assertSessionHasNoErrors();
        $this->assertSame('occupied', $unit->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', ['tenants', 'leases']), [...$lease, 'title' => 'Someone else'])->assertSessionHasErrors('data.unit');

        $leaseRecord = Record::query()->ofEntity('tenants', 'leases')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', ['tenants', 'leases', $leaseRecord->id, 'bill_rent']))->assertSessionHasNoErrors();
        $this->assertSame(today()->format('Y-m'), $leaseRecord->invoices()->firstOrFail()->period);
        $this->actingAs($owner)->post(route('apps.records.action', ['tenants', 'leases', $leaseRecord->id, 'bill_rent']))->assertSessionHasErrors('billing');

        // Rent is billed one month at a time, never through a period-less invoice.
        $this->actingAs($owner)->get($leaseRecord->url())->assertOk()->assertDontSee('Create invoice')->assertDontSee('Invoice again');
        $this->actingAs($owner)->post(route('apps.records.bill', ['tenants', 'leases', $leaseRecord->id]))->assertSessionHasErrors('billing');
        $this->assertSame(1, $leaseRecord->invoices()->count());

        // The daily run does not bill the month again.
        Artisan::call('zonseo:run-app-schedules');
        $this->assertSame(1, $leaseRecord->invoices()->count());

        $this->actingAs($owner)->get($unit->url())->assertOk()->assertSee('Chipo Dube');
        $this->actingAs($owner)->get(route('apps.reports', 'tenants'))->assertOk()->assertSee('Avondale Flats');

        $this->actingAs($owner)->put(route('apps.records.update', ['tenants', 'leases', $leaseRecord->id]), [...$lease, 'status' => 'ended'])->assertSessionHasNoErrors();
        $this->assertSame('vacant', $unit->fresh()->status);
    }

    public function test_the_daily_run_bills_rent_and_applies_the_anniversary_escalation(): void
    {
        [$owner, $workspace] = $this->appWorkspace('tenants');
        $tenant = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $unit = $this->record($workspace, 'tenants', 'units', '3A', 'occupied');
        $lease = $this->record($workspace, 'tenants', 'leases', 'Long-standing tenant', 'active', ['unit' => $unit->id, 'escalation_percent' => 10], [
            'contact_id' => $tenant->id, 'amount' => 500, 'occurs_on' => today()->subYear()->startOfMonth(),
        ]);
        $this->actingAs($owner)->get($lease->url())->assertOk()->assertSeeInOrder(['Next increase', today()->format('M Y')]);
        $future = $this->record($workspace, 'tenants', 'leases', 'Starts next month', 'active', ['unit' => $unit->id], [
            'contact_id' => $tenant->id, 'amount' => 300, 'occurs_on' => today()->addMonth(),
        ]);

        $this->assertSame(0, Artisan::call('zonseo:run-app-schedules'));

        $this->assertSame(550.0, (float) $lease->fresh()->amount);
        $this->assertSame(550.0, (float) $lease->invoices()->firstOrFail()->total);
        $this->assertSame(0, $future->invoices()->count());

        Artisan::call('zonseo:run-app-schedules');
        $this->assertSame(550.0, (float) $lease->fresh()->amount, 'escalation happens once a year');
        $this->assertSame(1, $lease->invoices()->count());
        $this->actingAs($owner)->get($lease->url())->assertOk()->assertSeeInOrder(['Next increase', today()->addYear()->format('M Y')]);
    }

    public function test_salon_prices_visits_from_the_menu_and_works_out_commission(): void
    {
        [$owner, $workspace] = $this->appWorkspace('salon');
        $service = $this->record($workspace, 'salon', 'services', 'Braids', 'active', ['commission_percent' => 10], ['amount' => 40]);
        $client = $this->record($workspace, 'salon', 'client_cards', 'Nyasha Phiri', 'active', ['allergies' => 'Ammonia']);

        $this->actingAs($owner)->post(route('apps.records.store', ['salon', 'visits']), [
            'title' => 'Braids', 'status' => 'completed', 'assignee_id' => $owner->id, 'occurs_on' => today()->toDateString(),
            'data' => ['client' => $client->id, 'service' => $service->id, 'tip' => 5],
        ])->assertSessionHasNoErrors();

        $visit = Record::query()->ofEntity('salon', 'visits')->firstOrFail();
        $this->assertSame(40.0, (float) $visit->amount);
        $this->assertSame(4.0, (float) $visit->value('_commission'));

        // Editing through the form keeps the internal commission figure.
        $this->actingAs($owner)->put(route('apps.records.update', ['salon', 'visits', $visit->id]), [
            'title' => 'Braids', 'status' => 'completed', 'amount' => 60, 'assignee_id' => $owner->id, 'occurs_on' => today()->toDateString(),
            'data' => ['client' => $client->id, 'service' => $service->id, 'tip' => 5],
        ])->assertSessionHasNoErrors();
        $this->assertSame(6.0, (float) $visit->fresh()->value('_commission'));

        $this->actingAs($owner)->get($client->url())->assertOk()->assertSee('Ammonia')->assertSee('Client spend')->assertSee('Braids');
        $this->actingAs($owner)->get(route('apps.reports', 'salon'))->assertOk()->assertSee($owner->name);
    }

    public function test_church_prints_a_giving_statement_and_shows_birthdays(): void
    {
        [$owner, $workspace] = $this->appWorkspace('church');
        $member = $this->record($workspace, 'church', 'members', 'Ruth Zulu', 'member', ['date_of_birth' => today()->subYears(30)->toDateString()]);
        $this->record($workspace, 'church', 'giving', 'Tithe', 'received', ['member' => $member->id, 'type' => 'tithe'], ['amount' => 100, 'occurs_on' => today()]);
        $this->record($workspace, 'church', 'giving', 'Offering', 'received', ['member' => $member->id, 'type' => 'offering'], ['amount' => 25, 'occurs_on' => today()]);
        $this->record($workspace, 'church', 'giving', 'Old tithe', 'received', ['member' => $member->id, 'type' => 'tithe'], ['amount' => 70, 'occurs_on' => today()->subYear()]);

        $this->actingAs($owner)->get($member->url())->assertOk()->assertSee('Giving statement');
        $this->actingAs($owner)->get(route('apps.records.document', ['church', 'members', $member->id, 'statement']))
            ->assertOk()->assertSee('Ruth Zulu')->assertSee('125.00')->assertDontSee('Old tithe');
        $this->actingAs($owner)->get(route('apps.records.document', ['church', 'members', $member->id, 'nope']))->assertNotFound();
        $this->actingAs($owner)->get(route('apps.show', 'church'))->assertOk()->assertSee('Birthdays in '.today()->format('F'))->assertSee('Ruth Zulu');
        $this->actingAs($owner)->get(route('apps.reports', 'church'))->assertOk()->assertSee('Tithe');
    }

    public function test_pos_rings_up_a_cash_sale_with_stock_invoice_payment_and_receipt(): void
    {
        [$owner, $workspace] = $this->appWorkspace('pos');
        $bread = Item::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Bread', 'type' => 'product', 'price' => 1.50, 'stock_qty' => 10, 'reorder_level' => 9, 'tax_rate_id' => null]);
        $till = $this->record($workspace, 'pos', 'tills', 'Front counter', 'active', ['mode' => 'retail', 'receipt_footer' => 'Come again!']);

        $this->actingAs($owner)->get(route('apps.pos.till'))->assertOk()->assertSee('Bread')->assertSee('Front counter');

        $this->actingAs($owner)->post(route('apps.pos.sell'), [
            'lines' => [['item_id' => $bread->id, 'quantity' => 2]], 'payment_method' => 'cash', 'tendered' => 5, 'till' => $till->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $sale = Record::query()->ofEntity('pos', 'sales')->firstOrFail();
        $this->assertSame(3.0, (float) $sale->amount);
        $this->assertSame(2.0, (float) $sale->value('change'));
        $this->assertSame($owner->id, $sale->assignee_id);
        $this->assertSame(8.0, (float) $bread->fresh()->stock_qty);
        $invoice = $sale->invoices()->firstOrFail();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('Walk-in customer', $invoice->contact->name);

        // The till's empty choice already means walk-in, so the contact is not listed twice.
        $this->actingAs($owner)->get(route('apps.pos.till'))->assertOk()->assertSeeTextInOrder(['Walk-in customer'])
            ->assertViewHas('contacts', fn ($contacts) => ! $contacts->contains('Walk-in customer'));

        // A sale is invoiced once, at the till, even after it is paid.
        $this->actingAs($owner)->get($sale->url())->assertOk()->assertDontSee('Invoice again');
        $this->actingAs($owner)->post(route('apps.records.bill', ['pos', 'sales', $sale->id]))->assertSessionHasErrors('billing');
        $this->assertSame(1, $sale->invoices()->count());

        $this->actingAs($owner)->get(route('apps.records.document', ['pos', 'sales', $sale->id, 'receipt']))->assertOk()->assertSee('Bread')->assertSee('Come again!');
        $this->actingAs($owner)->get(route('apps.show', 'pos'))->assertOk()->assertSee('Low on stock')->assertSee('Bread');
        $this->actingAs($owner)->get(route('apps.reports', 'pos'))->assertOk()->assertSee('Bread');
    }

    public function test_pos_refuses_short_cash_missing_stock_and_anonymous_account_sales(): void
    {
        [$owner, $workspace] = $this->appWorkspace('pos');
        $milk = Item::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Milk', 'type' => 'product', 'price' => 2, 'stock_qty' => 1, 'tax_rate_id' => null]);

        $this->actingAs($owner)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $milk->id, 'quantity' => 1]], 'payment_method' => 'cash', 'tendered' => 1])
            ->assertSessionHasErrors('tendered');
        $this->actingAs($owner)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $milk->id, 'quantity' => 3]], 'payment_method' => 'card'])
            ->assertSessionHasErrors('lines');
        $this->actingAs($owner)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $milk->id, 'quantity' => 1]], 'payment_method' => 'account'])
            ->assertSessionHasErrors('contact_id');

        $this->assertSame(0, Record::query()->ofEntity('pos', 'sales')->count());
        $this->assertSame(1.0, (float) $milk->fresh()->stock_qty);
    }

    public function test_pos_shift_closes_against_the_cash_the_till_expects(): void
    {
        [$owner, $workspace] = $this->appWorkspace('pos');
        $item = Item::factory()->create(['workspace_id' => $workspace->id, 'type' => 'service', 'price' => 20, 'tax_rate_id' => null]);
        $till = $this->record($workspace, 'pos', 'tills', 'Till 1', 'active', ['mode' => 'retail']);
        $shift = $this->record($workspace, 'pos', 'shifts', 'Morning', 'open', ['till' => $till->id, 'cashier' => $owner->id, 'opening_float' => 100]);

        $this->actingAs($owner)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $item->id, 'quantity' => 1]], 'payment_method' => 'cash', 'till' => $till->id]);
        $this->actingAs($owner)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $item->id, 'quantity' => 1]], 'payment_method' => 'card', 'till' => $till->id]);

        $this->actingAs($owner)->get($shift->url())->assertOk()->assertSee('Close shift')->assertSee('120.00');
        $this->actingAs($owner)->post(route('apps.records.action', ['pos', 'shifts', $shift->id, 'close_shift']), ['counted_cash' => 115])->assertSessionHasNoErrors();

        $shift->refresh();
        $this->assertSame('short', $shift->status);
        $this->assertSame(120.0, (float) $shift->value('expected_cash'));
        $this->actingAs($owner)->post(route('apps.records.action', ['pos', 'shifts', $shift->id, 'close_shift']), ['counted_cash' => 120])->assertNotFound();
    }
}
