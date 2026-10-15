<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\AssistantConversation;
use App\Models\Automation;
use App\Models\CustomField;
use App\Models\DocumentCapture;
use App\Models\DocumentTemplate;
use App\Models\ImportRun;
use App\Models\InboxChannel;
use App\Models\InboxConversation;
use App\Models\Invitation;
use App\Models\PortalAccess;
use App\Models\Record;
use App\Models\SignatureRequest;
use App\Models\User;
use App\Models\WebForm;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Support\Signatures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Models\TaxRate;
use Modules\Tasks\Models\Task;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** A signed-in person can never read or change another workspace's data by putting its ids in a link or form. */
class WorkspaceIsolationTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    private const MODULES = ['contacts', 'invoicing', 'quotes', 'helpdesk', 'tasks', 'appointments', 'payables'];

    /** Pages that take an id, as [route name, route parameters built from a workspace's ids]. */
    private const PAGES = [
        ['contacts.show', ['contact']], ['contacts.edit', ['contact']],
        ['invoices.show', ['invoice']], ['invoices.edit', ['invoice']], ['invoices.pdf', ['invoice']], ['invoices.print', ['invoice']],
        ['quotes.show', ['quote']], ['quotes.edit', ['quote']], ['quotes.pdf', ['quote']], ['quotes.print', ['quote']],
        ['tickets.show', ['ticket']], ['tickets.edit', ['ticket']],
        ['tasks.show', ['task']], ['tasks.edit', ['task']],
        ['appointments.show', ['appointment']], ['appointments.edit', ['appointment']],
        ['apps.records.show', ['record']], ['apps.records.edit', ['record']],
        ['signatures.show', ['signatureRequest']], ['signatures.certificate', ['signatureRequest']], ['signatures.document', ['signatureRequest']],
        ['documents.show', ['documentTemplate', 'contact']],
        ['settings.document-templates.edit', ['documentTemplate']],
        ['forms.show', ['webForm']], ['forms.edit', ['webForm']],
        ['captures.show', ['capture']],
        ['settings.imports.show', ['import']],
        ['settings.webhooks.show', ['webhook']],
        ['settings.automations.edit', ['automation']],
        ['settings.custom-fields.edit', ['custom_field']],
        ['settings.approval-rules.edit', ['approval_rule']],
        ['inbox.show', ['conversation']],
        ['assistant.show', ['assistant']],
    ];

    /** Changes that take an id, as [method, route name, route parameters]. */
    private const CHANGES = [
        ['delete', 'contacts.destroy', ['contact']], ['patch', 'contacts.update', ['contact']], ['post', 'contacts.comments.store', ['contact']],
        ['delete', 'invoices.destroy', ['invoice']], ['post', 'invoices.send', ['invoice']], ['post', 'invoices.payments.store', ['invoice']], ['post', 'invoices.cancel', ['invoice']],
        ['delete', 'quotes.destroy', ['quote']], ['post', 'quotes.accept', ['quote']], ['post', 'quotes.convert', ['quote']],
        ['delete', 'tickets.destroy', ['ticket']], ['post', 'tickets.replies.store', ['ticket']],
        ['delete', 'tasks.destroy', ['task']], ['post', 'tasks.status', ['task']],
        ['delete', 'appointments.destroy', ['appointment']], ['post', 'appointments.status', ['appointment']],
        ['delete', 'apps.records.destroy', ['record']], ['post', 'apps.records.status', ['record']], ['post', 'apps.records.comments.store', ['record']],
        ['post', 'signatures.cancel', ['signatureRequest']], ['post', 'signatures.remind', ['signatureRequest']],
        ['delete', 'settings.document-templates.destroy', ['documentTemplate']],
        ['delete', 'forms.destroy', ['webForm']],
        ['delete', 'captures.destroy', ['capture']],
        ['delete', 'settings.imports.destroy', ['import']], ['post', 'settings.imports.undo', ['import']],
        ['delete', 'settings.webhooks.destroy', ['webhook']], ['post', 'settings.webhooks.test', ['webhook']],
        ['delete', 'settings.automations.destroy', ['automation']], ['post', 'settings.automations.toggle', ['automation']],
        ['delete', 'settings.custom-fields.destroy', ['custom_field']],
        ['delete', 'settings.approval-rules.destroy', ['approval_rule']],
        ['post', 'approvals.approve', ['approvalRequest']], ['post', 'approvals.reject', ['approvalRequest']],
        ['post', 'inbox.reply', ['conversation']],
        ['delete', 'assistant.destroy', ['assistant']],
        ['delete', 'items.destroy', ['item']], ['delete', 'services.destroy', ['service']],
        ['delete', 'settings.invoicing.tax-rates.destroy', ['taxRate']],
        ['delete', 'settings.branches.destroy', ['branch']],
        ['delete', 'settings.portal.destroy', ['access']],
        ['delete', 'settings.members.destroy', ['user']], ['patch', 'settings.members.update', ['user']],
        ['delete', 'settings.members.invitations.destroy', ['invitation']],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Storage::fake(Signatures::DISK);
    }

    public function test_pages_with_another_workspaces_ids_are_not_found(): void
    {
        [$owner, $mine] = $this->workspaceWithData();
        [, $theirs] = $this->workspaceWithData();

        $this->actingAs($owner);
        foreach (self::PAGES as [$name, $keys]) {
            $this->get($this->url($name, $keys, $theirs))->assertNotFound();
            $status = $this->get($this->url($name, $keys, $mine))->status();
            $this->assertNotContains($status, [403, 404], "Own {$name} should open but gave {$status}.");
        }
    }

    public function test_changes_aimed_at_another_workspace_are_not_found_and_change_nothing(): void
    {
        [$owner] = $this->workspaceWithData();
        [, $theirs] = $this->workspaceWithData();
        $before = $this->snapshot($theirs);

        $this->actingAs($owner);
        foreach (self::CHANGES as [$method, $name, $keys]) {
            $status = $this->{$method}($this->url($name, $keys, $theirs), ['status' => 'done', 'role' => 'admin', 'body' => 'x', 'amount' => 1])->status();
            $this->assertContains($status, [403, 404], "{$name} on another workspace gave {$status}.");
        }

        $this->assertSame($before, $this->snapshot($theirs));
    }

    public function test_ids_passed_in_query_strings_and_forms_stay_inside_the_workspace(): void
    {
        [$owner] = $this->workspaceWithData();
        [, $theirs] = $this->workspaceWithData();
        $contact = Contact::allWorkspaces()->find($theirs['contact']);

        $this->actingAs($owner);
        $this->get(route('signatures.create', ['quote' => $theirs['quote']]))->assertNotFound();
        $this->get(route('signatures.create', ['template' => $theirs['documentTemplate'], 'subject' => $theirs['contact']]))->assertNotFound();
        $this->post(route('approvals.store'), ['subject' => 'payables.bills.approve', 'id' => $theirs['record']])->assertNotFound();
        $this->get(route('apps.records.create', ['payables', 'bills', 'contact_id' => $theirs['contact']]))->assertOk()->assertDontSee($contact->displayName());
        $this->post(route('apps.records.store', ['payables', 'bills']), ['title' => 'Mine', 'contact_id' => $theirs['contact'], 'data' => ['supplier_invoice_number' => 'X-1']])
            ->assertSessionHasErrors('contact_id');
        $this->post(route('workspaces.switch', Workspace::query()->find($theirs['workspace'])))->assertForbidden();
        $this->assertNotSame($theirs['workspace'], $owner->fresh()->current_workspace_id);
    }

    /**
     * An onboarded workspace with one of everything that has its own page.
     *
     * @return array{0: User, 1: array<string, int|string>}
     */
    private function workspaceWithData(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(self::MODULES, $owner);
        $in = ['workspace_id' => $workspace->id];
        $contact = Contact::factory()->for($workspace)->create(['email' => fake()->unique()->safeEmail()]);
        $quote = Quote::factory()->create($in + ['contact_id' => $contact->id]);
        $template = DocumentTemplate::factory()->create($in + ['subject' => 'contact', 'is_active' => true]);
        $record = Record::factory()->ofEntity('payables', 'bills', ['supplier_invoice_number' => 'B-'.$workspace->id])
            ->create($in + ['title' => 'Bill', 'amount' => 100, 'status' => 'received', 'contact_id' => $contact->id]);
        $member = $this->memberOf($workspace);
        $owner->switchWorkspace($workspace);

        return [$owner->fresh(), [
            'workspace' => $workspace->id,
            'contact' => $contact->id,
            'invoice' => Invoice::factory()->create($in + ['contact_id' => $contact->id])->id,
            'quote' => $quote->id,
            'ticket' => Ticket::factory()->create($in)->id,
            'task' => Task::factory()->create($in)->id,
            'appointment' => Appointment::factory()->create($in + ['contact_id' => $contact->id])->id,
            'record' => $record->id,
            'signatureRequest' => SignatureRequest::factory()->completed()->create($in + ['created_by' => $owner->id, 'certificate_path' => $this->storedPdf(), 'document_path' => $this->storedPdf()])->id,
            'documentTemplate' => $template->id,
            'webForm' => WebForm::factory()->create($in)->id,
            'capture' => DocumentCapture::factory()->create($in)->id,
            'import' => ImportRun::factory()->create($in)->id,
            'webhook' => WebhookEndpoint::factory()->create($in)->id,
            'automation' => Automation::factory()->create($in)->id,
            'custom_field' => CustomField::factory()->create($in)->id,
            'approval_rule' => ApprovalRule::factory()->create($in)->id,
            'approvalRequest' => ApprovalRequest::factory()->create($in + ['requested_by' => $member->id, 'approvable_id' => $quote->id])->id,
            'conversation' => InboxConversation::factory()->for(InboxChannel::factory()->create($in), 'channel')->create()->id,
            'assistant' => AssistantConversation::factory()->create($in + ['user_id' => $owner->id])->uuid,
            'item' => Item::factory()->create($in)->id,
            'service' => Service::factory()->create($in)->id,
            'taxRate' => TaxRate::factory()->create($in)->id,
            'branch' => $workspace->branches()->create(['name' => 'Second', 'is_active' => true])->id,
            'access' => PortalAccess::factory()->create($in + ['contact_id' => $contact->id])->id,
            'user' => $member->id,
            'invitation' => Invitation::query()->create($in + ['email' => fake()->unique()->safeEmail(), 'role' => 'member', 'token' => str()->random(40), 'invited_by' => $owner->id, 'expires_at' => now()->addWeek()])->id,
        ]];
    }

    private function storedPdf(): string
    {
        $path = 'signatures/certificate-'.str()->random(8).'.pdf';
        Storage::disk(Signatures::DISK)->put($path, '%PDF-1.4');

        return $path;
    }

    /** @param array<string, int|string> $ids */
    private function url(string $name, array $keys, array $ids): string
    {
        $parameters = array_map(fn (string $key) => $ids[$key], $keys);
        if (str_starts_with($name, 'apps.records.')) {
            $parameters = ['payables', 'bills', ...$parameters];
        }

        return route($name, $parameters);
    }

    /**
     * Every row of the other workspace that a change above could touch.
     *
     * @param  array<string, int|string>  $ids
     * @return array<string, mixed>
     */
    private function snapshot(array $ids): array
    {
        $workspace = $ids['workspace'];
        $rows = [];
        foreach (['contacts', 'invoices', 'payments', 'quotes', 'tickets', 'ticket_replies', 'tasks', 'appointments', 'records', 'comments', 'signature_requests',
            'document_templates', 'web_forms', 'document_captures', 'import_runs', 'webhook_endpoints', 'webhook_deliveries', 'automations', 'custom_fields',
            'approval_rules', 'approval_requests', 'inbox_messages', 'assistant_conversations', 'items', 'services', 'tax_rates', 'branches',
            'portal_accesses', 'invitations', 'workspace_user'] as $table) {
            if (! \Schema::hasTable($table) || ! \Schema::hasColumn($table, 'workspace_id')) {
                continue;
            }
            $rows[$table] = \DB::table($table)->where('workspace_id', $workspace)->orderBy(\Schema::hasColumn($table, 'id') ? 'id' : 'user_id')->get()
                ->map(fn ($row) => json_encode($row))->all();
        }

        return $rows;
    }
}
