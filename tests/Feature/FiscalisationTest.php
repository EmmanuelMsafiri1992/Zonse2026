<?php

namespace Tests\Feature;

use App\Models\FiscalDocument;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Fiscal\Authorities;
use App\Support\Fiscal\Fiscaliser;
use App\Support\Fiscal\FiscalSettings;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class FiscalisationTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace, 2: Contact} */
    protected function fiscalWorkspace(array $fiscal = ['enabled' => true, 'authority' => 'zimra', 'taxpayer_id' => '2000123456', 'device_id' => 'DEV7'], array $modules = ['invoicing']): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules($modules, $owner);
        $workspace = $workspace->fresh();
        FiscalSettings::save($workspace, $fiscal);
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Acme Buyer', 'kind' => 'person', 'company_name' => null, 'currency_code' => 'USD', 'tax_number' => 'B-991']);

        return [$owner, $workspace->fresh(), $contact];
    }

    protected function issueInvoice(User $owner, Contact $contact, float $price = 100): Invoice
    {
        $this->actingAs($owner)->post(route('invoices.store'), [
            'contact_id' => $contact->id, 'issue_date' => today()->format('Y-m-d'), 'due_date' => today()->addDays(14)->format('Y-m-d'), 'currency_code' => 'USD',
            'lines' => [['description' => 'Consulting', 'quantity' => 2, 'unit_price' => $price, 'tax_rate' => 15], ['description' => 'Hosting', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0]],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->forWorkspace($contact->workspace_id)->latest('id')->first();
        $this->assertSame(0, $invoice->fiscalDocuments()->count(), 'Drafts are not reported');
        $this->actingAs($owner)->post(route('invoices.send', $invoice))->assertSessionHasNoErrors();

        return $invoice->fresh();
    }

    public function test_owner_sets_up_reporting_and_numbers_are_checked_per_authority(): void
    {
        [$owner, $workspace] = $this->fiscalWorkspace(['enabled' => false]);
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->get(route('settings.fiscal.edit'))->assertOk()->assertSee('Report invoices to the tax authority')->assertSee('KRA · eTIMS');

        $this->actingAs($owner)->put(route('settings.fiscal.update'), ['enabled' => '1', 'authority' => '', 'taxpayer_id' => '', 'mode' => 'test'])
            ->assertSessionHasErrors(['authority', 'taxpayer_id']);
        $this->actingAs($owner)->put(route('settings.fiscal.update'), ['enabled' => '1', 'authority' => 'kra', 'taxpayer_id' => '12345', 'mode' => 'test'])
            ->assertSessionHasErrors(['taxpayer_id' => 'That does not look like a KRA KRA PIN (like P051234567X).']);
        $this->actingAs($owner)->put(route('settings.fiscal.update'), ['enabled' => '1', 'authority' => 'kra', 'taxpayer_id' => 'P051234567X', 'device_id' => 'bad id!', 'mode' => 'live'])
            ->assertSessionHasErrors(['device_id', 'mode']);

        $this->actingAs($owner)->put(route('settings.fiscal.update'), ['enabled' => '1', 'authority' => 'kra', 'taxpayer_id' => ' p051234567x ', 'device_id' => 'KRACU-01', 'mode' => 'test', 'simulate_outage' => '0'])
            ->assertSessionHasNoErrors()->assertSessionHas('flash.message', 'Saved. Invoices are now reported to KRA as they are issued.');
        $this->assertSame(
            ['enabled' => true, 'authority' => 'kra', 'taxpayer_id' => 'P051234567X', 'device_id' => 'KRACU-01', 'mode' => 'test', 'simulate_outage' => false],
            FiscalSettings::for($workspace->fresh()),
        );

        // Authorities without a fixed format take any number; switching off needs nothing else.
        $this->actingAs($owner)->put(route('settings.fiscal.update'), ['enabled' => '1', 'authority' => 'mra', 'taxpayer_id' => 'MW-77', 'mode' => 'test'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('settings.fiscal.update'), ['enabled' => '0', 'mode' => 'test'])->assertSessionHasNoErrors();
        $this->assertFalse(FiscalSettings::enabled($workspace->fresh()));

        $this->actingAs($member)->get(route('settings.fiscal.edit'))->assertForbidden();
        $this->actingAs($member)->get(route('settings.fiscal.log'))->assertForbidden();
        $this->actingAs($member)->post(route('settings.fiscal.retry'))->assertForbidden();
    }

    public function test_issued_invoices_are_numbered_chained_and_accepted(): void
    {
        [$owner, $workspace, $contact] = $this->fiscalWorkspace();

        $first = $this->issueInvoice($owner, $contact);
        $document = $first->fiscalDocuments()->sole();
        $this->assertSame('signed', $document->status);
        $this->assertSame(1, $document->counter);
        $this->assertSame('ZIMRA-DEV7-00000001', $document->fiscal_number);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}$/', $document->verification_code);
        $this->assertStringStartsWith('TEST-ZIMRA-', $document->authority_reference);
        $this->assertNull($document->previous_hash);
        $this->assertSame(Fiscaliser::hashFor($document->payload, null), $document->hash);
        $this->assertSame(number_format($first->total, 2, '.', ''), $document->payload['total']);
        $this->assertSame(number_format($first->tax_total, 2, '.', ''), $document->payload['tax_total']);
        $this->assertSame(['name' => $workspace->name, 'tax_id' => '2000123456', 'device_id' => 'DEV7'], $document->payload['seller']);
        $this->assertSame(['name' => 'Acme Buyer', 'tax_id' => 'B-991'], $document->payload['buyer']);
        $this->assertCount(2, $document->payload['lines']);

        $second = $this->issueInvoice($owner, $contact, 80);
        $next = $second->fiscalDocuments()->sole();
        $this->assertSame(2, $next->counter);
        $this->assertSame($document->hash, $next->previous_hash);

        // Paying an issued invoice reports nothing new.
        $this->actingAs($owner)->post(route('invoices.payments.store', $second), ['amount' => $second->total, 'method' => 'cash', 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(2, FiscalDocument::query()->forWorkspace($workspace)->count());

        // Each workspace has its own chain; workspaces without reporting have none.
        [$otherOwner, $other, $otherContact] = $this->fiscalWorkspace(['enabled' => true, 'authority' => 'zra', 'taxpayer_id' => '1001234567']);
        $this->assertSame(1, $this->issueInvoice($otherOwner, $otherContact)->fiscalDocuments()->sole()->counter);
        [$plainOwner, , $plainContact] = $this->fiscalWorkspace(['enabled' => false]);
        $this->assertSame(0, $this->issueInvoice($plainOwner, $plainContact)->fiscalDocuments()->count());

        $this->assertTrue(app(Fiscaliser::class)->verifyChain($workspace)->isEmpty());
    }

    public function test_reported_invoices_are_final_and_cancelling_issues_a_credit_note(): void
    {
        [$owner, , $contact] = $this->fiscalWorkspace();
        $invoice = $this->issueInvoice($owner, $contact);
        $original = $invoice->fiscalDocuments()->sole();

        $this->actingAs($owner)->get(route('invoices.edit', $invoice))->assertRedirect(route('invoices.show', $invoice))
            ->assertSessionHas('flash.message', 'This invoice has been reported to the tax authority and can no longer be edited. Cancel it and issue a new one instead.');
        $this->actingAs($owner)->delete(route('invoices.destroy', $invoice))->assertSessionHas('flash.type', 'danger');
        $this->assertNotSoftDeleted($invoice);
        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()
            ->assertSee('Tax authority')->assertSee($original->fiscal_number)->assertSee($original->verification_code)
            ->assertDontSee(route('invoices.edit', $invoice));

        $this->actingAs($owner)->post(route('invoices.cancel', $invoice))->assertSessionHas('flash.type', 'success');
        $credit = FiscalDocument::query()->where('invoice_id', $invoice->id)->where('type', 'credit_note')->sole();
        $this->assertSame('signed', $credit->status);
        $this->assertSame(2, $credit->counter);
        $this->assertSame($original->fiscal_number, $credit->payload['original_fiscal_number']);
        $this->assertSame($original->hash, $credit->previous_hash);

        $this->actingAs($owner)->post(route('invoices.cancel', $invoice));
        $this->assertSame(2, FiscalDocument::query()->count());

        $this->get(route('fiscal.verify', $original->verification_code))->assertOk()
            ->assertSee('This invoice was cancelled by credit note '.$credit->fiscal_number, false);
    }

    public function test_documents_wait_through_an_outage_and_are_sent_later(): void
    {
        [$owner, $workspace, $contact] = $this->fiscalWorkspace(['enabled' => true, 'authority' => 'zimra', 'taxpayer_id' => '2000123456', 'simulate_outage' => true]);

        $invoice = $this->issueInvoice($owner, $contact);
        $document = $invoice->fiscalDocuments()->sole();
        $this->assertSame('pending', $document->status);
        $this->assertSame(1, $document->attempts);
        $this->assertStringContainsString('could not be reached', $document->last_error);

        $this->actingAs($owner)->get(route('settings.fiscal.log'))->assertOk()->assertSee('Waiting to send')->assertSee('Send waiting documents (1)');
        $this->actingAs($owner)->post(route('settings.fiscal.retry'))->assertSessionHas('flash.type', 'warning');
        $this->assertSame(2, $document->fresh()->attempts);

        FiscalSettings::save($workspace, ['simulate_outage' => false]);
        $this->actingAs($owner)->post(route('settings.fiscal.retry'))->assertSessionHas('flash.message', '1 document accepted by the tax authority.');
        $this->assertSame('signed', $document->fresh()->status);
        $this->actingAs($owner)->post(route('settings.fiscal.retry'))->assertSessionHas('flash.message', 'Nothing is waiting to be sent.');

        // The scheduled command sends whatever is still waiting, in every workspace.
        FiscalSettings::save($workspace, ['simulate_outage' => true]);
        $later = $this->issueInvoice($owner, $contact)->fiscalDocuments()->sole();
        FiscalSettings::save($workspace, ['simulate_outage' => false]);
        $this->artisan('zonseo:fiscal-retry')->expectsOutput('1 accepted, 0 still waiting.')->assertSuccessful();
        $this->assertSame('signed', $later->fresh()->status);

        $this->actingAs($owner)->get(route('settings.fiscal.log', ['q' => $invoice->number]))->assertOk()
            ->assertSee($document->fiscal_number)->assertDontSee($later->fiscal_number);
    }

    public function test_chain_check_catches_changed_and_missing_documents(): void
    {
        [$owner, , $contact] = $this->fiscalWorkspace();
        $documents = collect([$this->issueInvoice($owner, $contact), $this->issueInvoice($owner, $contact), $this->issueInvoice($owner, $contact)])
            ->map(fn (Invoice $invoice) => $invoice->fiscalDocuments()->sole());

        $this->actingAs($owner)->post(route('settings.fiscal.verify-chain'))
            ->assertSessionHas('flash', ['type' => 'success', 'message' => 'All 3 fiscal documents check out: nothing has been changed or removed.']);

        $payload = $documents[1]->payload;
        $payload['total'] = '1.00';
        DB::table('fiscal_documents')->where('id', $documents[1]->id)->update(['payload' => json_encode($payload)]);
        $this->actingAs($owner)->post(route('settings.fiscal.verify-chain'))->assertSessionHas('flash.type', 'danger')
            ->assertSessionHas('flash.message', fn (string $message) => str_contains($message, $documents[1]->fiscal_number.' has been changed since it was issued.'));

        DB::table('fiscal_documents')->where('id', $documents[1]->id)->delete();
        $problems = app(Fiscaliser::class)->verifyChain($contact->workspace);
        $this->assertContains('Document 2 is missing before '.$documents[2]->fiscal_number.'.', $problems->all());
        $this->assertContains($documents[2]->fiscal_number.' does not link to the document before it.', $problems->all());
    }

    public function test_anyone_can_verify_a_document_and_invoices_carry_the_fiscal_block(): void
    {
        [$owner, $workspace, $contact] = $this->fiscalWorkspace();
        $invoice = $this->issueInvoice($owner, $contact);
        $document = $invoice->fiscalDocuments()->sole();

        $this->get(route('fiscal.verify', $document->verification_code))->assertOk()
            ->assertSee($workspace->name)->assertSee($document->fiscal_number)->assertSee($invoice->number)
            ->assertSee('Reported to ZIMRA')->assertSee('USD '.number_format($invoice->total, 2))->assertSee('Issued in test mode');
        $this->get(route('fiscal.verify', strtolower($document->verification_code)))->assertOk();
        $this->get('/verify/0000-0000-0000-0000')->assertNotFound();

        $this->actingAs($owner)->get(route('invoices.print', $invoice))->assertOk()
            ->assertSee('ZIMRA fiscal invoice')->assertSee($document->verification_code)->assertSee('data:image/svg+xml;base64,', false);
        $this->get($invoice->publicUrl())->assertOk()->assertSee($document->fiscal_number);
        $this->actingAs($owner)->get(route('invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_zatca_documents_carry_the_tlv_qr_code(): void
    {
        [$owner, $workspace, $contact] = $this->fiscalWorkspace(['enabled' => true, 'authority' => 'zatca', 'taxpayer_id' => '310122393500003']);
        $invoice = $this->issueInvoice($owner, $contact);
        $document = $invoice->fiscalDocuments()->sole();

        $bytes = base64_decode($document->qrData(), true);
        $fields = [];
        for ($offset = 0; $offset < strlen($bytes);) {
            $tag = ord($bytes[$offset]);
            $length = ord($bytes[$offset + 1]);
            $fields[$tag] = substr($bytes, $offset + 2, $length);
            $offset += 2 + $length;
        }

        $this->assertSame([
            1 => $workspace->name,
            2 => '310122393500003',
            3 => $document->payload['issued_at'],
            4 => number_format($invoice->total, 2, '.', ''),
            5 => number_format($invoice->tax_total, 2, '.', ''),
        ], $fields);
        $zimra = FiscalDocument::factory()->create();
        $this->assertSame($zimra->verifyUrl(), Authorities::qrData($zimra));
    }

    public function test_till_sales_are_reported_and_the_receipt_shows_the_fiscal_number(): void
    {
        [$owner, $workspace] = $this->fiscalWorkspace(modules: ['pos']);
        app(WorkspaceContext::class)->set($workspace);
        $bread = Item::factory()->for($workspace)->create(['type' => 'product', 'name' => 'Bread', 'price' => 1.50, 'stock_qty' => 20, 'tax_rate_id' => null]);

        $this->actingAs($owner)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $bread->id, 'quantity' => 2]], 'payment_method' => 'cash', 'tendered' => 5])->assertSessionHasNoErrors();
        $sale = Record::query()->ofEntity('pos', 'sales')->sole();
        $document = FiscalDocument::query()->where('invoice_id', $sale->invoices()->sole()->id)->sole();
        $this->assertSame('signed', $document->status);
        $this->assertSame('3.00', $document->payload['total']);

        $this->actingAs($owner)->get(route('apps.pos.receipt', $sale))->assertOk()
            ->assertSee($document->fiscal_number)->assertSee($document->verification_code)->assertSee('Scan to verify with ZIMRA');
        $bytes = $this->actingAs($owner)->get(route('apps.pos.receipt.escpos', $sale))->getContent();
        $this->assertStringContainsString('Fiscal no.', $bytes);
        $this->assertStringContainsString($document->verifyUrl(), $bytes);
        $this->actingAs($owner)->get(route('apps.records.document', ['pos', 'sales', $sale->id, 'receipt']))->assertSee($document->fiscal_number);
    }
}
