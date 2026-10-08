<?php

namespace Tests\Feature;

use App\Models\ApprovalRule;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Invoicing\Documents\DocumentDesign;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Quote;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** How invoices and quotes look: the design settings, the printed page and the PDF download. */
class DocumentDesignTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace, 2: User} */
    protected function workspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing', 'quotes'], $owner);
        $workspace = $workspace->fresh();

        return [$owner, $workspace, $this->memberOf($workspace)];
    }

    protected function invoice(Workspace $workspace, float $amount = 200, array $attributes = []): Invoice
    {
        return Invoice::factory()->for($workspace)
            ->withLines([['description' => 'Consulting', 'quantity' => 2, 'unit_price' => $amount / 2, 'tax_rate' => 15]])
            ->create(['currency_code' => 'USD'] + $attributes)->fresh();
    }

    /** @return array<string, mixed> */
    protected function design(array $overrides = []): array
    {
        return $overrides + [
            'style' => 'banner', 'color' => '#E2445C', 'invoice_title' => 'Tax invoice', 'quote_title' => 'Estimate',
            'payment_details' => 'EcoCash 0771 234 567', 'show_logo' => '1', 'signature' => '1',
        ];
    }

    public function test_the_defaults_apply_until_a_design_is_saved(): void
    {
        [, $workspace] = $this->workspace();
        $design = DocumentDesign::for($workspace);

        $this->assertSame('classic', $design->style);
        $this->assertSame(DocumentDesign::DEFAULT_COLOR, $design->color);
        $this->assertSame('Invoice', $design->title('invoice'));
        $this->assertSame('Quotation', $design->title('quote'));
        $this->assertTrue($design->show_tax_column);
        $this->assertFalse($design->signature);
        $this->assertSame('#e6f1fd', $design->tint(0.9));
    }

    public function test_an_admin_saves_the_design_and_it_is_audited(): void
    {
        [$owner, $workspace] = $this->workspace();

        $this->actingAs($owner)->get(route('settings.invoicing.edit'))->assertOk()->assertSee('Document design')->assertSee('Banner');
        $this->put(route('settings.invoicing.design.update'), $this->design())
            ->assertRedirect(route('settings.invoicing.edit').'#design')->assertSessionHas('flash.type', 'success');

        $design = DocumentDesign::for($workspace->fresh());
        $this->assertSame('banner', $design->style);
        $this->assertSame('#e2445c', $design->color);
        $this->assertSame('Tax invoice', $design->title('invoice'));
        $this->assertSame('Estimate', $design->title('quote'));
        $this->assertSame('EcoCash 0771 234 567', $design->payment_details);
        $this->assertTrue($design->signature);
        $this->assertFalse($design->show_tax_column, 'An unticked switch is saved as off.');
        $this->assertDatabaseHas('activity_log', ['workspace_id' => $workspace->id, 'event' => 'document-design-updated']);
    }

    public function test_the_design_is_validated_and_only_admins_change_it(): void
    {
        [$owner, , $member] = $this->workspace();

        $this->actingAs($owner)->put(route('settings.invoicing.design.update'), $this->design(['style' => 'neon', 'color' => 'red', 'invoice_title' => str_repeat('x', 41)]))
            ->assertSessionHasErrors(['style', 'color', 'invoice_title']);

        $this->actingAs($member)->get(route('settings.invoicing.edit'))->assertForbidden();
        $this->put(route('settings.invoicing.design.update'), $this->design())->assertForbidden();
        $this->get(route('settings.invoicing.design.preview'))->assertForbidden();
    }

    public function test_the_printed_invoice_and_quote_follow_the_design(): void
    {
        [$owner, $workspace] = $this->workspace();
        DocumentDesign::save($workspace, $this->design());
        $invoice = $this->invoice($workspace);
        $quote = Quote::factory()->for($workspace)->withLines()->create(['currency_code' => 'USD'])->fresh();

        $this->actingAs($owner)->get(route('invoices.print', $invoice))->assertOk()
            ->assertSee('doc doc-banner', false)->assertSee('#e2445c')->assertSee('Tax invoice')
            ->assertSee('EcoCash 0771 234 567')->assertSee('Received by')->assertDontSee('<th class="r">Tax</th>', false)
            ->assertSee(route('invoices.pdf', $invoice));
        $this->get(route('quotes.print', $quote))->assertOk()->assertSee('Estimate')->assertSee('Accepted by')
            ->assertDontSee('How to pay')->assertSee(route('quotes.pdf', $quote));

        $invoice->forceFill(['status' => 'paid'])->saveQuietly();
        $this->get(route('invoices.print', $invoice))->assertDontSee('How to pay');
    }

    public function test_invoices_and_quotes_download_as_pdf(): void
    {
        [$owner, $workspace] = $this->workspace();
        DocumentDesign::save($workspace, $this->design());
        $invoice = $this->invoice($workspace);
        $quote = Quote::factory()->for($workspace)->withLines()->create(['currency_code' => 'USD'])->fresh();

        $response = $this->actingAs($owner)->get(route('invoices.pdf', $invoice))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="tax-invoice-'.strtolower($invoice->number).'.pdf"');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $response = $this->get(route('quotes.pdf', $quote))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        foreach (DocumentDesign::STYLES as $style => $meta) {
            DocumentDesign::save($workspace, $this->design(['style' => $style]));
            $this->assertStringStartsWith('%PDF', $this->get(route('invoices.pdf', $invoice))->assertOk()->getContent());
        }
    }

    public function test_the_logo_is_embedded_in_the_pdf_and_can_be_hidden(): void
    {
        [, $workspace] = $this->workspace();
        Storage::fake('public');
        Storage::disk('public')->put('logos/sunrise.png', base64_decode(self::PNG));
        $workspace->update(['logo_path' => 'logos/sunrise.png']);
        DocumentDesign::save($workspace, $this->design());

        $design = DocumentDesign::for($workspace->fresh());
        $this->assertSame('data:image/png;base64,'.self::PNG, $design->logo($workspace, embed: true));
        $this->assertSame($workspace->logo_url, $design->logo($workspace));

        DocumentDesign::save($workspace, $this->design(['show_logo' => '0']));
        $this->assertNull(DocumentDesign::for($workspace->fresh())->logo($workspace, embed: true));
    }

    public function test_the_public_pdf_follows_the_share_link_rules(): void
    {
        [, $workspace] = $this->workspace();
        $invoice = $this->invoice($workspace, 2000);
        $quote = Quote::factory()->for($workspace)->withLines()->create(['currency_code' => 'USD'])->fresh();

        $this->get($invoice->publicUrl())->assertOk()->assertSee(route('invoices.public.pdf', $invoice->uuid))->assertDontSee(route('invoices.pdf', $invoice));
        $this->assertStringStartsWith('%PDF', $this->get(route('invoices.public.pdf', $invoice->uuid))->assertOk()->getContent());
        $this->assertStringStartsWith('%PDF', $this->get(route('quotes.public.pdf', $quote->uuid))->assertOk()->getContent());
        $this->get(route('invoices.public.pdf', 'not-a-real-uuid'))->assertNotFound();

        ApprovalRule::factory()->create(['workspace_id' => $workspace->id, 'min_amount' => 1000]);
        $this->get(route('invoices.public.pdf', $invoice->uuid))->assertNotFound();
    }

    public function test_documents_stay_inside_their_workspace(): void
    {
        [, $workspace] = $this->workspace();
        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace();
        $otherWorkspace->enableModules(['invoicing', 'quotes'], $otherOwner);
        DocumentDesign::save($workspace, $this->design());
        $invoice = $this->invoice($workspace);

        $this->actingAs($otherOwner)->get(route('invoices.pdf', $invoice))->assertNotFound();
        $this->assertSame('classic', DocumentDesign::for($otherWorkspace->fresh())->style);
    }

    public function test_the_preview_shows_a_sample_invoice_in_the_saved_design(): void
    {
        [$owner, $workspace] = $this->workspace();
        DocumentDesign::save($workspace, $this->design(['style' => 'minimal']));

        $this->actingAs($owner)->get(route('settings.invoicing.design.preview'))->assertOk()
            ->assertSee('doc doc-minimal', false)->assertSee('SAMPLE-0001')->assertSee('Sample Customer Ltd')
            ->assertSee('Back to settings')->assertDontSee('Download PDF');
        $this->assertSame(0, Invoice::query()->count());
    }
}
