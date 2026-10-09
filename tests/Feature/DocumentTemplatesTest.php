<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\DocumentTemplate;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DocumentTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Letters, certificates and receipts: designing templates and printing them filled in as PDFs. */
class DocumentTemplatesTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /**
     * @param  list<string>  $modules
     * @return array{0: User, 1: Workspace}
     */
    protected function workspace(array $modules = ['contacts', 'invoicing', 'clinic']): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules($modules, $owner);
        $workspace->update(['name' => 'Gweru Skills Centre', 'phone' => '+263 54 222 333']);

        return [$owner, $workspace->fresh()];
    }

    /** @param array<string, mixed> $attributes */
    protected function template(Workspace $workspace, array $attributes = []): DocumentTemplate
    {
        return DocumentTemplate::factory()->create(['workspace_id' => $workspace->id] + $attributes);
    }

    protected function contact(Workspace $workspace, array $attributes = []): Contact
    {
        return Contact::factory()->for($workspace)->create($attributes + ['name' => 'Tendai Moyo', 'kind' => 'person', 'company_name' => null, 'type' => 'customer']);
    }

    protected function assertPdf(TestResponse $response): void
    {
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_an_admin_can_start_a_template_from_a_starter(): void
    {
        [$owner] = $this->workspace();

        $this->actingAs($owner)->get(route('settings.document-templates.index'))->assertOk()->assertSee('No templates yet');
        $this->get(route('settings.document-templates.create'))->assertOk()
            ->assertSee('Certificate')->assertSee('Payment receipt')->assertSee('Payments (receipts)')->assertSee('Patients');

        $this->post(route('settings.document-templates.store'), ['name' => 'Course certificate', 'starter' => 'certificate', 'subject' => 'contact'])
            ->assertRedirect(route('settings.document-templates.edit', DocumentTemplate::query()->firstOrFail()));

        $template = DocumentTemplate::query()->firstOrFail();
        $this->assertSame(['certificate', 'contact', 'landscape', 'serif', 'center', 'double'], [$template->kind, $template->subject, $template->orientation, $template->font, $template->align, $template->border]);
        $this->assertSame(['Instructor', 'Director'], $template->signatures);
        $this->assertStringContainsString('{{ contact.name }}', $template->body);

        $this->get(route('settings.document-templates.edit', $template))->assertOk()
            ->assertSee('Course certificate')->assertSee("Today's date")->assertSee('contact.name');
        $this->get(route('settings.document-templates.index'))->assertSee('Course certificate')->assertSee('Contacts');
    }

    public function test_a_starter_used_for_an_app_keeps_working_with_that_apps_tags(): void
    {
        [$owner] = $this->workspace();

        $this->actingAs($owner)->post(route('settings.document-templates.store'), ['name' => 'Patient card', 'starter' => 'certificate', 'subject' => 'clinic.patients'])
            ->assertRedirect();

        $template = DocumentTemplate::query()->firstOrFail();
        $this->assertStringContainsString('# {{ record.title }}', $template->body);
        $this->assertSame([], DocumentTemplates::unknownTags($template->subject, $template->heading, $template->body, $template->footer));
    }

    public function test_templates_can_only_use_switched_on_sources_and_only_admins_manage_them(): void
    {
        [$owner, $workspace] = $this->workspace(['contacts']);

        $this->actingAs($owner)->post(route('settings.document-templates.store'), ['name' => 'Receipt', 'starter' => 'receipt', 'subject' => 'payment'])
            ->assertSessionHasErrors('subject');
        $this->post(route('settings.document-templates.store'), ['name' => 'Odd', 'starter' => 'nonsense', 'subject' => 'contact'])
            ->assertSessionHasErrors('starter');
        $this->assertSame(0, DocumentTemplate::query()->count());

        $member = $this->memberOf($workspace);
        $template = $this->template($workspace);
        $this->actingAs($member)->get(route('settings.document-templates.index'))->assertForbidden();
        $this->get(route('settings.document-templates.edit', $template))->assertForbidden();
        $this->post(route('settings.document-templates.preview', $template))->assertForbidden();
    }

    public function test_the_designer_saves_the_layout_and_rejects_unknown_tags(): void
    {
        [$owner, $workspace] = $this->workspace();
        $template = $this->template($workspace);
        $design = [
            'name' => 'Welcome letter', 'kind' => 'letter', 'heading' => 'Welcome, {{ contact.name }}',
            'body' => "Dear {{ contact.name }},\n\nYour shoe size is {{ contact.shoe_size }}.",
            'paper' => 'letter', 'orientation' => 'portrait', 'font' => 'serif', 'align' => 'left', 'border' => 'simple',
            'color' => '#AA3300', 'show_logo' => '0', 'signatures' => ['  Manager ', '', 'Owner'], 'footer' => '{{ workspace.phone }}', 'is_active' => '1',
        ];

        $this->actingAs($owner)->put(route('settings.document-templates.update', $template), $design)->assertSessionHasErrors('body');
        $this->assertStringNotContainsString('shoe', $template->fresh()->body);

        $this->put(route('settings.document-templates.update', $template), ['body' => "Dear {{contact.name}},\n\nWelcome."] + $design)
            ->assertRedirect(route('settings.document-templates.edit', $template))->assertSessionHasNoErrors();

        $template->refresh();
        $this->assertSame(['letter', 'serif', 'simple', '#aa3300', false], [$template->paper, $template->font, $template->border, $template->color, $template->show_logo]);
        $this->assertSame(['Manager', 'Owner'], $template->signatures);

        $this->put(route('settings.document-templates.update', $template), ['signatures' => ['A', 'B', 'C', 'D']] + $design)
            ->assertSessionHasErrors('signatures');
    }

    public function test_the_preview_shows_unsaved_changes_safely_with_tag_names(): void
    {
        [$owner, $workspace] = $this->workspace();
        $template = $this->template($workspace);

        $this->actingAs($owner)->get(route('settings.document-templates.preview', $template))->assertOk()
            ->assertSee('Welcome')->assertSee('[Name]')->assertSee('Gweru Skills Centre')->assertSee('Manager');

        $this->post(route('settings.document-templates.preview', $template), [
            'heading' => 'Notice for {{ contact.name }}', 'body' => "**Important** <script>alert(1)</script>\n\n# {{ contact.city }}", 'color' => 'not-a-colour',
        ])->assertOk()
            ->assertSee('Notice for [Name]')->assertSee('<strong>Important</strong>', false)->assertSee('<h1>[City]</h1>', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('#0073ea', false);

        $this->assertSame('Welcome', $template->fresh()->heading);
        $this->assertPdf($this->get(route('settings.document-templates.sample', $template)));
        $this->assertSame(0, $template->fresh()->generated_count);
    }

    public function test_a_letter_prints_filled_in_for_a_contact(): void
    {
        [$owner, $workspace] = $this->workspace();
        CustomField::factory()->create(['workspace_id' => $workspace->id, 'entity' => 'contact', 'label' => 'Member number', 'key' => 'member_number', 'type' => 'text']);
        $contact = $this->contact($workspace, ['city' => 'Gweru', 'custom_fields' => ['member_number' => 'M-0042']]);
        $template = $this->template($workspace, ['body' => 'Dear {{ contact.name }} of {{ contact.city }} ({{ custom.member_number }}), from {{ user.name }} at {{ workspace.name }}.']);

        $this->actingAs($owner)->get(route('contacts.show', $contact))->assertOk()
            ->assertSee(route('documents.show', [$template, $contact->id]), false)->assertSee('Welcome letter');

        $page = DocumentTemplates::page($template, $contact, $owner);
        $this->assertSame('<p>Dear Tendai Moyo of Gweru (M-0042), from '.e($owner->name).' at Gweru Skills Centre.</p>', trim((string) $page['body']));

        $this->assertPdf($response = $this->get(route('documents.show', [$template, $contact->id])));
        $this->assertStringContainsString('welcome-letter-tendai-moyo.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(1, $template->fresh()->generated_count);
        $this->assertDatabaseHas('activity_log', ['event' => 'document-generated', 'subject_id' => $contact->id]);
    }

    public function test_values_are_escaped_and_cannot_add_formatting(): void
    {
        [, $workspace] = $this->workspace();
        $contact = $this->contact($workspace, ['name' => '<b>**Rudo**</b>', 'address' => "1 Main St\nGweru"]);
        $template = $this->template($workspace, ['body' => "{{ contact.name }}\n\n{{ contact.address }}"]);

        $body = (string) DocumentTemplates::page($template, $contact)['body'];

        $this->assertStringContainsString('&lt;b&gt;**Rudo**&lt;/b&gt;', $body);
        $this->assertStringContainsString("1 Main St<br />\nGweru", $body);
    }

    public function test_a_receipt_prints_for_a_payment(): void
    {
        [$owner, $workspace] = $this->workspace();
        $contact = $this->contact($workspace);
        $invoice = Invoice::factory()->for($workspace)->withLines([['description' => 'Course fee', 'quantity' => 1, 'unit_price' => 150, 'tax_rate' => 0]])
            ->create(['contact_id' => $contact->id, 'currency_code' => 'USD']);
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'mobile_money', 'reference' => 'EC123']);
        $starter = DocumentTemplates::starters()['receipt'];
        $template = $this->template($workspace, ['name' => 'Receipt', 'kind' => 'receipt', 'subject' => 'payment'] + $starter['values']);

        $this->actingAs($owner)->get(route('payments.index'))->assertOk()->assertSee(route('documents.show', [$template, $payment->id]), false);
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee(route('documents.show', [$template, $payment->id]), false);

        $page = DocumentTemplates::page($template, $payment->fresh(), $owner);
        $this->assertSame('Receipt '.$payment->number, $page['heading']);
        foreach (['Tendai Moyo', '$100.00', 'Mobile money EC123', $invoice->number, 'Balance still owed: $50.00'] as $text) {
            $this->assertStringContainsString($text, (string) $page['body']);
        }
        $this->assertSame('Gweru Skills Centre · +263 54 222 333', $page['footer']);

        $this->assertPdf($this->get(route('documents.show', [$template, $payment->id])));
    }

    public function test_a_certificate_prints_for_an_app_record(): void
    {
        [$owner, $workspace] = $this->workspace();
        CustomField::factory()->create(['workspace_id' => $workspace->id, 'entity' => 'clinic.patients', 'label' => 'Ward', 'key' => 'ward', 'type' => 'text']);
        $patient = Record::create([
            'workspace_id' => $workspace->id, 'blueprint' => 'clinic', 'entity' => 'patients', 'title' => 'Rudo Banda', 'status' => 'active',
            'data' => ['medical_aid' => 'CIMAS', 'date_of_birth' => '1990-05-04'], 'custom_fields' => ['ward' => 'B2'],
        ]);
        $template = $this->template($workspace, [
            'name' => 'Patient card', 'kind' => 'certificate', 'subject' => 'clinic.patients', 'heading' => '{{ record.number }}',
            'body' => '{{ record.title }}, born {{ record.date_of_birth }}, aid {{ record.medical_aid }}, ward {{ custom.ward }}',
        ]);

        $this->actingAs($owner)->get($patient->url())->assertOk()->assertSee(route('documents.show', [$template, $patient->id]), false);

        $page = DocumentTemplates::page($template, $patient);
        $this->assertSame($patient->number, $page['heading']);
        $this->assertStringContainsString('Rudo Banda, born 04 May 1990, aid CIMAS, ward B2', (string) $page['body']);

        $this->assertPdf($this->get(route('documents.show', [$template, $patient->id])));
    }

    public function test_general_templates_print_on_their_own_and_members_can_print(): void
    {
        [$owner, $workspace] = $this->workspace();
        $template = $this->template($workspace, ['name' => 'Closing notice', 'subject' => 'none', 'body' => 'We close early on {{ today }}.', 'signatures' => []]);
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->get(route('settings.document-templates.index'))->assertSee(route('documents.show', $template), false);
        $this->actingAs($member)->get(route('documents.show', $template))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('documents.show', [$template, 1]))->assertNotFound();
    }

    public function test_switched_off_templates_and_other_workspaces_stay_hidden(): void
    {
        [$owner, $workspace] = $this->workspace();
        [$otherOwner, $other] = $this->workspace();
        $contact = $this->contact($workspace);
        $theirs = $this->template($other);
        $theirContact = $this->contact($other);
        $off = $this->template($workspace, ['is_active' => false]);

        $this->actingAs($owner)->get(route('contacts.show', $contact))->assertOk()->assertDontSee(route('documents.show', [$off, $contact->id]), false);
        $this->get(route('documents.show', [$off, $contact->id]))->assertNotFound();
        $this->get(route('documents.show', [$theirs, $contact->id]))->assertNotFound();
        $this->get(route('settings.document-templates.edit', $theirs))->assertNotFound();

        $mine = $this->template($workspace);
        $this->get(route('documents.show', [$mine, $theirContact->id]))->assertNotFound();
        $this->get(route('documents.show', $mine))->assertNotFound();

        $invoice = Invoice::factory()->for($workspace)->withLines([['description' => 'Fee', 'quantity' => 1, 'unit_price' => 10, 'tax_rate' => 0]])->create(['contact_id' => $contact->id]);
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id]);
        $receipt = $this->template($workspace, ['subject' => 'payment', 'body' => 'Paid {{ payment.amount }}']);
        $this->get(route('documents.show', [$receipt, $payment->id]))->assertOk();

        $workspace->disableModule('invoicing');
        $this->get(route('documents.show', [$receipt, $payment->id]))->assertNotFound();
    }
}
