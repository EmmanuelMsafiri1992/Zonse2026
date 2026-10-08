<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Payment;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Models\TaxRate;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class InvoicingModuleTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace, 2: Contact} */
    protected function salesWorkspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing', 'quotes'], $owner);
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Acme Buyer', 'kind' => 'person', 'company_name' => null, 'currency_code' => 'USD']);

        return [$owner, $workspace->fresh(), $contact];
    }

    /** @return array<string, mixed> */
    protected function invoicePayload(Contact $contact, array $overrides = []): array
    {
        return array_merge([
            'contact_id' => $contact->id,
            'issue_date' => today()->format('Y-m-d'),
            'due_date' => today()->addDays(14)->format('Y-m-d'),
            'currency_code' => 'USD',
            'reference' => 'PO-77',
            'discount_type' => 'percent',
            'discount_value' => 10,
            'lines' => [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 15],
                ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0],
            ],
        ], $overrides);
    }

    public function test_invoice_routes_require_the_module_to_be_enabled(): void
    {
        [$owner] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->get(route('invoices.index'))->assertRedirect(route('settings.modules.index'));
    }

    public function test_owner_can_create_an_invoice_with_correct_totals_and_numbering(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();

        $this->actingAs($owner)->get(route('invoices.create'))->assertOk()->assertSee('New invoice');

        $response = $this->actingAs($owner)->post(route('invoices.store'), $this->invoicePayload($contact));

        $invoice = Invoice::query()->first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('INV-0001', $invoice->number);
        $this->assertSame('draft', $invoice->status);
        $this->assertSame($workspace->id, $invoice->workspace_id);
        $this->assertCount(2, $invoice->lines);
        $this->assertEqualsWithDelta(250.0, $invoice->subtotal, 0.001);
        $this->assertEqualsWithDelta(25.0, $invoice->discount_amount, 0.001);
        // Tax of 15% on 200 is 30, scaled by (250 - 25) / 250 gives 27.
        $this->assertEqualsWithDelta(27.0, $invoice->tax_total, 0.001);
        $this->assertEqualsWithDelta(252.0, $invoice->total, 0.001);
        $this->assertEqualsWithDelta(252.0, $invoice->balance, 0.001);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))
            ->assertOk()->assertSee('INV-0001')->assertSee('Acme Buyer')->assertSee('Consulting')->assertSee('252.00');

        $this->actingAs($owner)->get(route('invoices.print', $invoice))->assertOk()->assertSee('INV-0001')->assertSee('Bill to');

        $second = Invoice::factory()->for($workspace)->create(['contact_id' => $contact->id]);
        $this->assertSame('INV-0002', $second->number);
    }

    public function test_invoice_requires_at_least_one_line_and_a_customer(): void
    {
        [$owner, , $contact] = $this->salesWorkspace();

        $this->actingAs($owner)->from(route('invoices.create'))
            ->post(route('invoices.store'), $this->invoicePayload($contact, ['lines' => [], 'contact_id' => null]))
            ->assertRedirect(route('invoices.create'))
            ->assertSessionHasErrors(['lines', 'contact_id']);

        $this->assertSame(0, Invoice::count());
    }

    public function test_invoices_are_scoped_to_the_workspace(): void
    {
        [$owner, $workspace] = $this->salesWorkspace();
        $mine = Invoice::factory()->for($workspace)->withLines()->create(['reference' => 'MINE-REF']);
        $theirs = Invoice::factory()->withLines()->create(['reference' => 'THEIRS-REF']);

        $this->actingAs($owner)->get(route('invoices.index'))->assertOk()->assertSee('MINE-REF')->assertDontSee('THEIRS-REF');
        $this->actingAs($owner)->get(route('invoices.show', $mine))->assertOk();
        $this->actingAs($owner)->get(route('invoices.show', $theirs->id))->assertNotFound();
        $this->actingAs($owner)->get(route('invoices.edit', $theirs->id))->assertNotFound();
    }

    public function test_viewer_cannot_create_or_edit_invoices(): void
    {
        [, $workspace, $contact] = $this->salesWorkspace();
        $viewer = $this->memberOf($workspace, 'viewer');
        $invoice = Invoice::factory()->for($workspace)->withLines()->create(['contact_id' => $contact->id]);

        $this->actingAs($viewer)->get(route('invoices.index'))->assertOk()->assertSee($invoice->number);
        $this->actingAs($viewer)->get(route('invoices.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('invoices.store'), $this->invoicePayload($contact))->assertForbidden();
        $this->actingAs($viewer)->post(route('invoices.payments.store', $invoice), ['amount' => 10, 'paid_on' => today()->format('Y-m-d'), 'method' => 'cash'])->assertForbidden();
    }

    public function test_recording_payments_moves_the_invoice_from_sent_to_partial_to_paid(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines([
            ['description' => 'Design', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0],
        ])->create(['contact_id' => $contact->id]);

        $this->actingAs($owner)->post(route('invoices.send', $invoice))->assertRedirect();
        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->sent_at);

        $this->actingAs($owner)->post(route('invoices.payments.store', $invoice), [
            'amount' => 100, 'paid_on' => today()->format('Y-m-d'), 'method' => 'mobile_money', 'reference' => 'ECO123',
        ])->assertRedirect(route('invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame('partial', $invoice->status);
        $this->assertEqualsWithDelta(100.0, $invoice->amount_paid, 0.001);
        $this->assertEqualsWithDelta(200.0, $invoice->balance, 0.001);
        $this->assertSame('PAY-0001', $invoice->payments()->first()->number);

        $this->actingAs($owner)->post(route('invoices.payments.store', $invoice), [
            'amount' => 200, 'paid_on' => today()->format('Y-m-d'), 'method' => 'bank',
        ])->assertRedirect();

        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEqualsWithDelta(0.0, $invoice->balance, 0.001);
        $this->assertNotNull($invoice->paid_at);

        $this->actingAs($owner)->get(route('payments.index'))->assertOk()->assertSee('PAY-0001')->assertSee('ECO123');

        $payment = $invoice->payments()->first();
        $this->actingAs($owner)->delete(route('payments.destroy', $payment))->assertRedirect();
        $this->assertSame('partial', $invoice->fresh()->status);
    }

    public function test_overpayment_is_rejected(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $contact->id]);

        $this->actingAs($owner)->from(route('invoices.show', $invoice))
            ->post(route('invoices.payments.store', $invoice), ['amount' => 1000, 'paid_on' => today()->format('Y-m-d'), 'method' => 'cash'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    public function test_sent_invoices_past_due_become_overdue(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $contact->id, 'due_date' => today()->subDays(3)]);

        $this->actingAs($owner)->get(route('invoices.index', ['status' => 'overdue']))->assertOk()->assertSee($invoice->number);
        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertTrue($invoice->fresh()->isOverdue());
    }

    public function test_invoice_with_payments_cannot_be_edited_or_deleted(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $contact->id]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'workspace_id' => $workspace->id, 'amount' => 10]);

        $this->assertFalse($invoice->fresh()->isEditable());
        $this->actingAs($owner)->get(route('invoices.edit', $invoice))->assertRedirect(route('invoices.show', $invoice));
        $this->actingAs($owner)->delete(route('invoices.destroy', $invoice))->assertRedirect();
        $this->assertNotNull(Invoice::find($invoice->id));
    }

    public function test_quote_can_be_accepted_and_converted_into_an_invoice(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();

        $this->actingAs($owner)->post(route('quotes.store'), [
            'contact_id' => $contact->id,
            'issue_date' => today()->format('Y-m-d'),
            'valid_until' => today()->addDays(30)->format('Y-m-d'),
            'currency_code' => 'USD',
            'lines' => [
                ['description' => 'Website design', 'quantity' => 1, 'unit_price' => 800, 'tax_rate' => 15],
                ['description' => 'Logo', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 15],
            ],
        ])->assertRedirect();

        $quote = Quote::query()->first();
        $this->assertSame('QT-0001', $quote->number);
        $this->assertEqualsWithDelta(1150.0, $quote->total, 0.001);

        $this->actingAs($owner)->post(route('quotes.send', $quote))->assertRedirect();
        $this->actingAs($owner)->post(route('quotes.accept', $quote))->assertRedirect();
        $this->assertSame('accepted', $quote->fresh()->status);

        $response = $this->actingAs($owner)->post(route('quotes.convert', $quote));
        $invoice = Invoice::query()->first();
        $this->assertNotNull($invoice);
        $response->assertRedirect(route('invoices.show', $invoice));

        $this->assertSame('converted', $quote->fresh()->status);
        $this->assertSame($invoice->id, $quote->fresh()->invoice_id);
        $this->assertSame($quote->id, $invoice->quote_id);
        $this->assertSame($contact->id, $invoice->contact_id);
        $this->assertCount(2, $invoice->lines);
        $this->assertEqualsWithDelta(1150.0, $invoice->total, 0.001);
        $this->assertSame('QT-0001', $invoice->reference);

        $this->actingAs($owner)->post(route('quotes.convert', $quote))->assertRedirect();
        $this->assertSame(1, Invoice::count());

        $this->actingAs($owner)->get(route('quotes.index'))->assertOk()->assertSee('QT-0001')->assertSee('Invoiced');
        $this->actingAs($owner)->get(route('quotes.show', $quote))->assertOk()->assertSee($invoice->number);
    }

    public function test_rejected_quotes_cannot_be_converted(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();
        $quote = Quote::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $contact->id]);

        $this->actingAs($owner)->post(route('quotes.reject', $quote))->assertRedirect();
        $this->assertSame('rejected', $quote->fresh()->status);
        $this->actingAs($owner)->post(route('quotes.convert', $quote))->assertRedirect();
        $this->assertSame(0, Invoice::count());
    }

    public function test_public_links_work_for_guests_and_cross_workspace_ids_do_not_leak(): void
    {
        [, $workspace, $contact] = $this->salesWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $contact->id]);
        $quote = Quote::factory()->for($workspace)->withLines()->create(['contact_id' => $contact->id]);

        $this->get(route('invoices.public', $invoice->uuid))->assertOk()->assertSee($invoice->number)->assertSee('Acme Buyer')->assertDontSee('Back to invoice');
        $this->get(route('quotes.public', $quote->uuid))->assertOk()->assertSee($quote->number);
        $this->get(route('invoices.public', 'not-a-real-uuid'))->assertNotFound();
        $this->get(route('invoices.show', $invoice))->assertRedirect(route('login'));
    }

    public function test_items_and_tax_rates_are_managed_and_feed_the_invoice_form(): void
    {
        [$owner, $workspace] = $this->salesWorkspace();

        $this->actingAs($owner)->post(route('settings.invoicing.tax-rates.store'), ['name' => 'VAT', 'rate' => 15, 'is_default' => 1])->assertRedirect();
        $vat = TaxRate::query()->firstWhere('name', 'VAT');
        $this->assertTrue($vat->is_default);
        $this->assertEqualsWithDelta(15.0, TaxRate::defaultRate(), 0.001);

        $this->actingAs($owner)->post(route('settings.invoicing.tax-rates.store'), ['name' => 'Zero', 'rate' => 0, 'is_default' => 1])->assertRedirect();
        $this->assertFalse($vat->fresh()->is_default);

        $this->actingAs($owner)->post(route('items.store'), [
            'type' => 'service', 'name' => 'Hourly consulting', 'price' => 120, 'unit' => 'hour', 'tax_rate_id' => $vat->id, 'sku' => 'CONS-1',
        ])->assertRedirect(route('items.index'));
        $item = Item::query()->firstWhere('sku', 'CONS-1');
        $this->assertSame($workspace->id, $item->workspace_id);

        $this->actingAs($owner)->put(route('items.update', $item), ['type' => 'service', 'name' => 'Hourly consulting', 'price' => 150, 'is_active' => 0])->assertRedirect();
        $this->assertEqualsWithDelta(150.0, $item->fresh()->price, 0.001);
        $this->assertFalse($item->fresh()->is_active);

        $this->actingAs($owner)->get(route('items.index', ['status' => 'all']))->assertOk()->assertSee('Hourly consulting')->assertSee('CONS-1');
        $this->actingAs($owner)->get(route('items.index'))->assertOk()->assertDontSee('CONS-1');

        $item->refresh()->update(['is_active' => true]);
        $this->actingAs($owner)->get(route('invoices.create'))->assertOk()->assertSee('Hourly consulting');

        $this->actingAs($owner)->delete(route('items.destroy', $item))->assertRedirect();
        $this->assertNull(Item::find($item->id));
    }

    public function test_sales_settings_change_numbering_and_defaults(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();

        $this->actingAs($owner)->get(route('settings.invoicing.edit'))->assertOk()->assertSee('Sales settings');

        $this->actingAs($owner)->put(route('settings.invoicing.update'), [
            'invoice_prefix' => 'ZN-', 'invoice_next' => 500,
            'quote_prefix' => 'EST-', 'quote_next' => 1,
            'payment_prefix' => 'RC-', 'payment_next' => 1,
            'due_days' => 30, 'quote_valid_days' => 10,
            'terms' => 'Pay within 30 days.', 'notes' => 'Bank: 1234', 'footer' => 'Thanks!',
        ])->assertRedirect();

        $this->assertSame('ZN-', Sequence::current('invoice', 'INV-')['prefix']);
        $this->assertSame(30, (int) $workspace->fresh()->setting('invoicing.due_days'));

        $invoice = Invoice::factory()->for($workspace)->create(['contact_id' => $contact->id, 'due_date' => null]);
        $this->assertSame('ZN-0500', $invoice->number);
        $this->assertTrue($invoice->due_date->isSameDay(today()->addDays(30)));

        $this->actingAs($owner)->get(route('invoices.create'))->assertOk()->assertSee('Pay within 30 days.');

        $member = $this->memberOf($workspace, 'member');
        $this->actingAs($member)->get(route('settings.invoicing.edit'))->assertForbidden();
    }

    public function test_global_search_dashboard_widget_and_sync_cover_invoicing(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines()->sent()->create(['contact_id' => $contact->id, 'reference' => 'FIND-ME-REF']);
        Quote::factory()->for($workspace)->withLines()->create(['contact_id' => $contact->id, 'reference' => 'QUOTE-REF-9']);

        $this->actingAs($owner)->get(route('search', ['q' => 'FIND-ME-REF']))->assertOk()->assertSee($invoice->number);
        $this->actingAs($owner)->get(route('search', ['q' => 'QUOTE-REF-9']))->assertOk()->assertSee('QT-0001');

        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Sales')->assertSee($invoice->number)->assertSee('Invoices');

        $this->artisan('zonseo:sync-modules')->assertSuccessful();
        $this->assertTrue(Module::findByKey('invoicing')->fresh()->is_installed);
        $this->assertTrue(Module::findByKey('quotes')->fresh()->is_installed);
    }

    public function test_activity_and_internal_notes_are_recorded_for_invoices(): void
    {
        [$owner, $workspace, $contact] = $this->salesWorkspace();

        $this->actingAs($owner)->post(route('invoices.store'), $this->invoicePayload($contact))->assertRedirect();
        $invoice = Invoice::query()->first();

        $this->assertDatabaseHas('activity_log', ['subject_id' => $invoice->id, 'subject_type' => Invoice::class]);
        $this->actingAs($owner)->post(route('invoices.comments.store', $invoice), ['body' => 'Chased by phone'])->assertRedirect();
        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()->assertSee('Chased by phone');
    }
}
