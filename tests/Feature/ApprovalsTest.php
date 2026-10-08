<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\SmsService;
use App\Support\Approvals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Quote;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Sign-off before invoices and quotes go out: rules, asking, deciding, and the send steps they hold. */
class ApprovalsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

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

    /** @param array<string, mixed> $attributes */
    protected function rule(Workspace $workspace, array $attributes = []): ApprovalRule
    {
        return ApprovalRule::factory()->create(['workspace_id' => $workspace->id] + $attributes);
    }

    protected function invoice(Workspace $workspace, float $amount, string $currency = 'USD', array $attributes = []): Invoice
    {
        return Invoice::factory()->for($workspace)
            ->withLines([['description' => 'Consulting', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0]])
            ->create(['currency_code' => $currency] + $attributes)->fresh();
    }

    public function test_an_admin_can_add_edit_and_remove_rules(): void
    {
        [$owner, $workspace, $member] = $this->workspace();

        $this->actingAs($owner)->get(route('settings.approval-rules.index'))->assertOk()->assertSee('Any invoice can go out without approval.');
        $this->get(route('settings.approval-rules.create', ['subject' => 'quote.send']))->assertOk()->assertSee('New approval rule');

        $this->post(route('settings.approval-rules.store'), [
            'name' => 'Large invoices', 'subject' => 'invoice.send', 'min_amount' => '1000', 'currency_code' => 'USD',
            'approver' => 'person', 'approver_id' => $member->id, 'is_active' => '1',
        ])->assertRedirect(route('settings.approval-rules.index'));

        $rule = ApprovalRule::query()->sole();
        $this->assertSame([$workspace->id, 1000.0, 'USD', 'person', $member->id, true], [$rule->workspace_id, $rule->min_amount, $rule->currency_code, $rule->approver, $rule->approver_id, $rule->is_active]);
        $this->get(route('settings.approval-rules.index'))->assertSee('Large invoices')->assertSee($member->name);
        $this->get(route('settings.approval-rules.edit', $rule))->assertOk();

        $this->put(route('settings.approval-rules.update', $rule), [
            'name' => 'All invoices', 'subject' => 'invoice.send', 'min_amount' => '', 'currency_code' => '',
            'approver' => 'managers', 'approver_id' => $member->id, 'is_active' => '0',
        ])->assertRedirect(route('settings.approval-rules.index'));
        $rule->refresh();
        $this->assertSame(['All invoices', 0.0, null, 'managers', null, false], [$rule->name, $rule->min_amount, $rule->currency_code, $rule->approver, $rule->approver_id, $rule->is_active]);
        $this->get(route('settings.approval-rules.index'))->assertSee('Paused');

        $this->delete(route('settings.approval-rules.destroy', $rule))->assertRedirect(route('settings.approval-rules.index'));
        $this->assertSame(0, ApprovalRule::query()->count());
    }

    public function test_rules_are_validated_and_only_admins_manage_them(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        [$stranger] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->post(route('settings.approval-rules.store'), [
            'name' => '', 'subject' => 'payroll.run', 'min_amount' => '-5', 'currency_code' => 'XXX', 'approver' => 'everyone',
        ])->assertSessionHasErrors(['name', 'subject', 'min_amount', 'currency_code', 'approver']);

        $this->post(route('settings.approval-rules.store'), ['name' => 'Big', 'subject' => 'invoice.send', 'approver' => 'person'])
            ->assertSessionHasErrors(['approver_id' => 'Choose who approves.']);
        $this->post(route('settings.approval-rules.store'), ['name' => 'Big', 'subject' => 'invoice.send', 'approver' => 'person', 'approver_id' => $stranger->id])
            ->assertSessionHasErrors(['approver_id' => 'Choose someone on your team.']);
        $this->assertSame(0, ApprovalRule::query()->count());

        $rule = $this->rule($workspace);
        $this->actingAs($member)->get(route('settings.approval-rules.index'))->assertForbidden();
        $this->post(route('settings.approval-rules.store'), ['name' => 'Mine', 'subject' => 'invoice.send', 'approver' => 'admins'])->assertForbidden();
        $this->delete(route('settings.approval-rules.destroy', $rule))->assertForbidden();
        $this->assertModelExists($rule);
    }

    public function test_a_member_can_send_below_the_limit_but_must_ask_above_it(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $this->rule($workspace, ['min_amount' => 1000]);
        $small = $this->invoice($workspace, 400);
        $large = $this->invoice($workspace, 1500);

        $this->actingAs($member)->post(route('invoices.send', $small))->assertSessionHas('flash.type', 'success');
        $this->assertSame('sent', $small->fresh()->status);

        $this->get(route('invoices.show', $large))->assertOk()->assertSee('Needs approval before it goes out.')->assertSee('Ask for approval')->assertDontSee('Mark as sent');
        $this->post(route('invoices.send', $large))->assertSessionHas('flash.type', 'warning');
        $this->assertSame('draft', $large->fresh()->status);

        $this->actingAs($owner)->get(route('invoices.show', $large))->assertOk()->assertSee('Mark as sent')->assertSee('you can send it straight away');
    }

    public function test_asking_notifies_approvers_and_an_approval_lets_the_member_send(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $admin = $this->memberOf($workspace, 'admin');
        $manager = $this->memberOf($workspace, 'manager');
        $this->rule($workspace, ['min_amount' => 1000]);
        $invoice = $this->invoice($workspace, 1500);

        $this->actingAs($member)->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $invoice->id, 'note' => 'Big job for Acme'])
            ->assertSessionHas('flash.type', 'success');
        $approval = ApprovalRequest::query()->sole();
        $this->assertSame(['pending', 1500.0, 'USD', $member->id, 'Big job for Acme'], [$approval->status, (float) $approval->amount, $approval->currency_code, $approval->requested_by, $approval->note]);

        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(0, $manager->notifications()->count());
        $this->assertStringContainsString('asks you to approve', $admin->notifications()->first()->data['title']);

        $this->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $invoice->id])->assertSessionHas('flash.type', 'info');
        $this->assertSame(1, ApprovalRequest::query()->count());

        $this->get(route('invoices.show', $invoice))->assertSee('Waiting for approval.')->assertSee('Withdraw request');
        $this->post(route('approvals.approve', $approval))->assertForbidden();
        $this->actingAs($manager)->post(route('approvals.approve', $approval))->assertForbidden();
        $this->actingAs($manager)->get(route('approvals.index'))->assertOk()->assertDontSee($approval->title);

        $this->actingAs($admin)->get(route('approvals.index'))->assertOk()->assertSee($approval->title)->assertSee('Big job for Acme');
        $this->post(route('approvals.approve', $approval), ['decision_note' => 'Go ahead'])->assertSessionHas('flash.type', 'success');
        $approval->refresh();
        $this->assertSame(['approved', $admin->id, 'Go ahead'], [$approval->status, $approval->decided_by, $approval->decision_note]);
        $this->assertStringContainsString('was approved', $member->notifications()->first()->data['title']);

        $this->actingAs($member)->get(route('invoices.show', $invoice))->assertSee('Approved')->assertSee('Mark as sent');
        $this->post(route('invoices.send', $invoice))->assertSessionHas('flash.type', 'success');
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_a_turned_down_request_can_be_asked_again_and_a_pending_one_withdrawn(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $this->rule($workspace, ['min_amount' => 0]);
        $invoice = $this->invoice($workspace, 200);

        $this->actingAs($member)->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $invoice->id]);
        $first = ApprovalRequest::query()->sole();

        $this->actingAs($owner)->post(route('approvals.reject', $first), ['decision_note' => 'Price is wrong'])->assertSessionHas('flash.type', 'success');
        $this->assertSame('rejected', $first->fresh()->status);
        $this->assertStringContainsString('was turned down', $member->notifications()->first()->data['title']);

        $this->actingAs($member)->get(route('invoices.show', $invoice))->assertSee('Price is wrong')->assertSee('Ask for approval');
        $this->post(route('invoices.send', $invoice))->assertSessionHas('flash.type', 'warning');

        $this->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $invoice->id]);
        $second = ApprovalRequest::query()->latest('id')->first();
        $this->assertTrue($second->isPending());

        $other = $this->memberOf($workspace);
        $this->actingAs($other)->post(route('approvals.withdraw', $second))->assertForbidden();
        $this->actingAs($member)->post(route('approvals.withdraw', $second))->assertSessionHas('flash.type', 'success');
        $this->assertSame('withdrawn', $second->fresh()->status);
        $this->post(route('approvals.withdraw', $second))->assertForbidden();
        $this->actingAs($owner)->post(route('approvals.approve', $second))->assertForbidden();
    }

    public function test_an_approver_sending_directly_settles_open_requests(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $this->rule($workspace, ['min_amount' => 1000]);
        $invoice = $this->invoice($workspace, 1500);
        $this->actingAs($member)->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $invoice->id]);

        $this->actingAs($owner)->post(route('invoices.send', $invoice))->assertSessionHas('flash.type', 'success');

        $approval = ApprovalRequest::query()->sole();
        $this->assertSame(['approved', $owner->id, 'Sent it directly.'], [$approval->status, $approval->decided_by, $approval->decision_note]);
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_raising_the_total_past_the_approved_amount_needs_approval_again(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $this->rule($workspace, ['min_amount' => 1000]);
        $invoice = $this->invoice($workspace, 1500);
        $approval = Approvals::request($invoice, 'invoice.send', $member);
        Approvals::decide($approval, $owner, true);

        $this->assertNull(Approvals::blocking($invoice, 'invoice.send', $member));

        $invoice->syncLines([['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1400, 'tax_rate' => 0]]);
        $this->assertNull(Approvals::blocking($invoice->fresh(), 'invoice.send', $member), 'A lower total is still covered.');

        $invoice->syncLines([['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 1800, 'tax_rate' => 0]]);
        $this->assertNotNull(Approvals::blocking($invoice->fresh(), 'invoice.send', $member));
        $this->actingAs($member)->post(route('invoices.send', $invoice))->assertSessionHas('flash.type', 'warning');
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_the_strictest_rule_applies_and_currency_rules_only_cover_their_currency(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $named = $this->memberOf($workspace);
        $manager = $this->memberOf($workspace, 'manager');
        $this->rule($workspace, ['name' => 'Medium', 'min_amount' => 500, 'currency_code' => 'USD', 'approver' => 'managers']);
        $this->rule($workspace, ['name' => 'Huge', 'min_amount' => 5000, 'currency_code' => 'USD', 'approver' => 'person', 'approver_id' => $named->id]);
        $this->rule($workspace, ['name' => 'Paused', 'min_amount' => 0, 'is_active' => false]);

        $medium = $this->invoice($workspace, 800);
        $huge = $this->invoice($workspace, 9000);
        $rand = $this->invoice($workspace, 9000, 'ZAR');

        $this->assertSame('Medium', Approvals::blocking($medium, 'invoice.send', $member)?->name);
        $this->assertNull(Approvals::blocking($medium, 'invoice.send', $manager));
        $this->assertSame('Huge', Approvals::blocking($huge, 'invoice.send', $manager)?->name);
        $this->assertNull(Approvals::blocking($huge, 'invoice.send', $named));
        $this->assertNull(Approvals::blocking($huge, 'invoice.send', $owner));
        $this->assertNull(Approvals::blocking($rand, 'invoice.send', $member));

        $this->actingAs($member)->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $rand->id])->assertSessionHas('flash.type', 'info');
        $this->assertSame(0, ApprovalRequest::query()->count());
    }

    public function test_the_public_link_and_sms_are_held_until_approved(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $workspace->update(['country_code' => 'ZW']);
        app(SmsService::class)->configure($workspace->fresh(), 'test', [], []);
        $contact = Contact::factory()->for($workspace)->create(['kind' => 'person', 'company_name' => null, 'type' => 'customer', 'mobile' => '077 123 4567', 'country_code' => 'ZW']);
        $this->rule($workspace, ['min_amount' => 1000]);
        $invoice = $this->invoice($workspace, 1500, 'USD', ['contact_id' => $contact->id]);

        $this->get($invoice->publicUrl())->assertNotFound();
        $this->actingAs($member)->get(route('invoices.show', $invoice))->assertDontSee('Send by SMS')->assertDontSee('Copy link')->assertDontSee($invoice->publicUrl());
        $this->post(route('invoices.sms', $invoice))->assertSessionHas('flash.type', 'warning');
        $this->assertSame('draft', $invoice->fresh()->status);

        Approvals::decide(Approvals::request($invoice, 'invoice.send', $member), $owner, true);
        auth()->logout();
        $this->get($invoice->publicUrl())->assertOk();
        $this->actingAs($member)->post(route('invoices.sms', $invoice))->assertSessionHas('flash.type', 'success');
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_quotes_are_held_the_same_way(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $this->rule($workspace, ['subject' => 'quote.send', 'min_amount' => 1000]);
        $quote = Quote::factory()->for($workspace)
            ->withLines([['description' => 'Website', 'quantity' => 1, 'unit_price' => 2000, 'tax_rate' => 0]])
            ->create(['currency_code' => 'USD'])->fresh();

        $this->get($quote->publicUrl())->assertNotFound();
        $this->actingAs($member)->get(route('quotes.show', $quote))->assertOk()->assertSee('Ask for approval')->assertDontSee('Mark as sent');
        $this->post(route('quotes.send', $quote))->assertSessionHas('flash.type', 'warning');

        $this->post(route('approvals.store'), ['subject' => 'quote.send', 'id' => $quote->id])->assertSessionHas('flash.type', 'success');
        $this->actingAs($owner)->post(route('approvals.approve', ApprovalRequest::query()->sole()))->assertSessionHas('flash.type', 'success');

        $this->actingAs($member)->post(route('quotes.send', $quote))->assertSessionHas('flash.type', 'success');
        $this->assertSame('sent', $quote->fresh()->status);
    }

    public function test_requests_stay_inside_their_workspace(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        [$otherOwner, $otherWorkspace] = $this->ownerWithWorkspace();
        $otherWorkspace->enableModules(['invoicing', 'quotes'], $otherOwner);
        $this->rule($workspace, ['min_amount' => 0]);
        $invoice = $this->invoice($workspace, 100);
        $approval = Approvals::request($invoice, 'invoice.send', $member);

        $this->actingAs($otherOwner)->post(route('approvals.approve', $approval))->assertNotFound();
        $this->post(route('approvals.store'), ['subject' => 'invoice.send', 'id' => $invoice->id])->assertNotFound();
        $this->get(route('approvals.index', ['tab' => 'all']))->assertOk()->assertDontSee($approval->title);
        $this->assertTrue($approval->fresh()->isPending());
    }

    public function test_the_inbox_tabs(): void
    {
        [$owner, $workspace, $member] = $this->workspace();
        $this->rule($workspace, ['min_amount' => 0]);
        $mine = Approvals::request($this->invoice($workspace, 100), 'invoice.send', $member);
        $theirs = Approvals::request($this->invoice($workspace, 200), 'invoice.send', $this->memberOf($workspace));

        $this->actingAs($member)->get(route('approvals.index'))->assertOk()->assertSee('Nothing waiting for you')->assertDontSee('All requests');
        $this->get(route('approvals.index', ['tab' => 'mine']))->assertOk()->assertSee($mine->title)->assertDontSee($theirs->title);
        $this->get(route('approvals.index', ['tab' => 'all']))->assertOk()->assertSee('Nothing waiting for you');

        $this->actingAs($owner)->get(route('approvals.index'))->assertOk()->assertSee($mine->title)->assertSee($theirs->title);
        $this->get(route('approvals.index', ['tab' => 'all']))->assertOk()->assertSee('All requests')->assertSee($mine->title)->assertSee($theirs->title);
        $this->get(route('approvals.index', ['tab' => 'mine']))->assertOk()->assertSee('No requests yet');
    }
}
