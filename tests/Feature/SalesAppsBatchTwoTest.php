<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Sales: call centre, van sales, customer portal, gift cards, warranty & returns and service contracts. */
class SalesAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_calls_need_an_outcome_and_campaigns_run_their_list(): void
    {
        $app = 'call-centre';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'calls']), ['title' => 'Order query', 'status' => 'answered', 'data' => ['direction' => 'inbound', 'phone' => '0977000000']])
            ->assertSessionHasErrors(['data.disposition' => 'Give the outcome of the call.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'calls']), ['title' => 'Order query', 'status' => 'callback', 'data' => ['direction' => 'inbound', 'phone' => '0977000000']])
            ->assertSessionHasErrors(['data.callback_at' => 'Give the time to call back.']);

        $missed = $this->record($workspace, $app, 'calls', 'Mrs Banda', 'missed', ['direction' => 'inbound', 'phone' => '0977111111', 'duration' => 5]);
        $this->assertEquals(0, $missed->value('duration'));
        $this->record($workspace, $app, 'calls', 'Mr Zulu', 'callback', ['direction' => 'inbound', 'phone' => '0977222222', 'callback_at' => now()->subHour()->toDateTimeString()]);
        $this->record($workspace, $app, 'calls', 'Sale call', 'answered', ['direction' => 'outbound', 'phone' => '0977333333', 'duration' => 12, 'disposition' => 'sale'], ['assignee_id' => $owner->id]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Callbacks due')->assertSee('Mr Zulu')->assertSee('12 min');
        $this->actingAs($owner)->post($missed->url().'/actions/called_back', ['disposition' => 'interested', 'duration' => 4])->assertSessionHas('flash.message', 'Mrs Banda called back: interested.');
        $this->assertSame('answered', $missed->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'campaigns']), ['title' => 'Renewals', 'status' => 'planned', 'data' => ['list_size' => 10, 'reached' => 11]])
            ->assertSessionHasErrors(['data.reached' => 'Reached can\'t be more than the 10 numbers on the list.']);
        $campaign = $this->record($workspace, $app, 'campaigns', 'Renewals', 'planned', ['list_size' => 100, 'reached' => 0], ['occurs_on' => today()->addDay(), 'due_on' => today()->addDays(10)]);
        $this->assertSame('planned', $campaign->status);
        $this->travel(1)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('running', $campaign->fresh()->status);
        $this->actingAs($owner)->post($campaign->url().'/actions/reached', ['reached' => 40])->assertSessionHas('flash.message', 'Renewals: 40 of 100 reached.');
        $this->actingAs($owner)->get($campaign->url())->assertOk()->assertSee('Still to call')->assertSee('40%');
        $this->actingAs($owner)->post($campaign->url().'/actions/reached', ['reached' => 100])->assertSessionHas('flash.message', 'Renewals reached every number and is finished.');
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Calls by agent')->assertSee($owner->name)->assertSee('Unassigned');
    }

    public function test_van_loads_are_reconciled_against_orders_taken(): void
    {
        $app = 'field-sales-van-sales';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'routes']), ['title' => 'Matero run', 'status' => 'active', 'data' => []])
            ->assertSessionHasErrors(['data.rep' => 'Give the rep who drives this route.']);
        $route = $this->record($workspace, $app, 'routes', 'Matero run', 'active', ['rep' => $owner->id, 'vehicle' => 'ABC 123']);
        $closed = $this->record($workspace, $app, 'routes', 'Old run', 'inactive');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), ['title' => 'Shop', 'status' => 'planned', 'data' => ['route' => $closed->id]])
            ->assertSessionHasErrors(['data.route' => 'Route Old run is inactive.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), ['title' => 'Shop', 'status' => 'visited', 'data' => ['route' => $route->id, 'order_taken' => '1']])
            ->assertSessionHasErrors(['amount' => 'Give the sales value of the order.']);

        $load = $this->record($workspace, $app, 'loads', 'Monday load', 'loaded', ['route' => $route->id, 'items_loaded' => '20 x Maheu'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'loads']), ['title' => 'Second load', 'status' => 'loaded', 'data' => ['route' => $route->id, 'items_loaded' => '5 x Bread']])
            ->assertSessionHasErrors(['data.route' => 'Route Matero run already has load Monday load out.']);
        $shop = $this->record($workspace, $app, 'visits', 'Mwape Grocery', 'planned', ['route' => $route->id], ['occurs_on' => today()]);
        $kiosk = $this->record($workspace, $app, 'visits', 'Corner Kiosk', 'planned', ['route' => $route->id], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($shop->url().'/actions/visited', ['amount' => 500])->assertSessionHas('flash.message', 'Mwape Grocery visited; order of '.$this->money(500).'.');
        $this->assertTrue((bool) $shop->fresh()->value('order_taken'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today on the road')->assertSee('Loads out')->assertSee('Monday load');

        $this->actingAs($owner)->post($load->url().'/actions/depart')->assertSessionHas('flash.message', 'Monday load is on the route.');
        $this->actingAs($owner)->post($load->url().'/actions/return', ['items_returned' => '2 x Maheu'])->assertSessionHas('flash.message', 'Monday load is back at the depot.');
        $this->actingAs($owner)->post($load->url().'/actions/reconcile', ['cash_collected' => ''])->assertSessionHasErrors(['cash_collected' => 'Give the cash collected.']);
        $this->actingAs($owner)->post($load->url().'/actions/reconcile', ['cash_collected' => 400])->assertSessionHas('flash.message', 'Monday load reconciled; cash short by '.$this->money(100).'.');
        $this->assertEquals(-100, $load->fresh()->value('_variance'));

        $this->travel(1)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $kiosk->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sales by route')->assertSee('Matero run')->assertSee($owner->name)->assertSee($this->money(400));
    }

    public function test_portal_accounts_are_unique_and_requests_get_handled(): void
    {
        $app = 'customer-portal';
        [$owner, $workspace] = $this->appWorkspace($app);
        $acme = $this->record($workspace, $app, 'accounts', 'Acme Ltd', 'invited', ['email' => 'Info@Acme.co.zm']);
        $this->assertSame('info@acme.co.zm', $acme->value('email'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'accounts']), ['title' => 'Acme again', 'status' => 'invited', 'data' => ['email' => 'INFO@acme.co.zm']])
            ->assertSessionHasErrors(['data.email' => 'Acme Ltd already has a portal account with info@acme.co.zm.']);
        $this->actingAs($owner)->post($acme->url().'/actions/activate')->assertSessionHas('flash.message', 'Acme Ltd\'s portal account is active.');
        $gone = $this->record($workspace, $app, 'accounts', 'Gone Co', 'disabled', ['email' => 'gone@co.zm']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), ['title' => 'Hello', 'status' => 'new', 'data' => ['account' => $gone->id, 'type' => 'question', 'message' => 'Hi']])
            ->assertSessionHasErrors(['data.account' => 'Gone Co\'s portal account is disabled.']);

        $request = $this->record($workspace, $app, 'requests', 'Copy of invoice 12', 'new', ['account' => $acme->id, 'type' => 'document_request', 'message' => 'Please send it.'], ['occurs_on' => today()->subDays(2)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Requests waiting')->assertSee('Copy of invoice 12')->assertSee('2 days');
        $this->actingAs($owner)->post($request->url().'/actions/take')->assertSessionHas('flash.message', 'Copy of invoice 12 is yours.');
        $this->assertSame($owner->id, $request->fresh()->assignee_id);
        $this->actingAs($owner)->post($request->url().'/actions/done')->assertSessionHas('flash.message', 'Copy of invoice 12 done.');
        $this->assertSame(today()->toDateString(), $request->fresh()->value('_done_on'));
        $this->actingAs($owner)->get($acme->url())->assertOk()->assertSee('Copy of invoice 12')->assertSee('Document request');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Requests by type')->assertSee('Average days to close')->assertSee('Document request');
    }

    public function test_gift_cards_keep_a_balance_and_expire(): void
    {
        $app = 'gift-cards';
        [$owner, $workspace] = $this->appWorkspace($app);
        $card = $this->record($workspace, $app, 'cards', 'gc-100', 'active', ['type' => 'gift_card'], ['amount' => 500]);
        $this->assertSame('GC-100', $card->title);
        $this->assertTrue($card->due_on->isSameDay(today()->addYear()));
        $this->assertEquals(500, $card->value('balance'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cards']), ['title' => 'Gc-100', 'status' => 'active', 'data' => ['type' => 'voucher']])
            ->assertSessionHasErrors(['title' => 'Card code GC-100 is already in use.', 'amount' => 'Give the face value.']);
        $credit = $this->record($workspace, $app, 'cards', 'CREDIT-1', 'active', ['type' => 'store_credit'], ['amount' => 80, 'due_on' => today()->addDay()]);
        $this->assertNull($credit->due_on);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'redemptions']), ['title' => 'Till 1', 'status' => 'posted', 'amount' => 600, 'data' => ['card' => $card->id]])
            ->assertSessionHasErrors(['amount' => 'Card GC-100 only has '.$this->money(500).' left.']);
        $this->actingAs($owner)->post($card->url().'/actions/redeem', ['amount' => 200])->assertSessionHas('flash.message', $this->money(200).' taken from GC-100; '.$this->money(300).' left.');
        $this->actingAs($owner)->post($card->url().'/actions/redeem', ['amount' => 300])->assertSessionHasNoErrors();
        $this->assertSame('redeemed', $card->fresh()->status);
        $last = Record::query()->where('entity', 'redemptions')->where('amount', 300)->firstOrFail();
        $this->actingAs($owner)->post($last->url().'/actions/reverse')->assertSessionHas('flash.message', 'Redemption reversed; card GC-100 is back to '.$this->money(300).'.');
        $this->assertSame('active', $card->fresh()->status);
        $this->actingAs($owner)->get($card->url())->assertOk()->assertSee('Balance '.$this->money(300).' of '.$this->money(500));

        $soon = $this->record($workspace, $app, 'cards', 'V-7', 'active', ['type' => 'voucher'], ['amount' => 50, 'due_on' => today()->addDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Expiring in 30 days')->assertSee('V-7')->assertSee($this->money(430));
        $this->travel(2)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $soon->fresh()->status);
        $this->assertSame('active', $credit->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'redemptions']), ['title' => 'Till 2', 'status' => 'posted', 'amount' => 10, 'data' => ['card' => $soon->id]])
            ->assertSessionHasErrors(['data.card' => 'Card V-7 is expired.']);
        $this->travelBack();
        $this->actingAs($owner)->post($credit->url().'/actions/cancel')->assertSessionHas('flash.message', 'Card CREDIT-1 cancelled with '.$this->money(80).' unused.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Gift cards by month')->assertSee($this->money(630))->assertSee($this->money(200));
    }

    public function test_returns_need_a_live_warranty_and_an_outcome(): void
    {
        $app = 'warranty-returns-rma-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $fridge = $this->record($workspace, $app, 'warranties', 'Fridge', 'active', ['serial_number' => 'fr-001', 'months' => 24], ['occurs_on' => today()->subMonths(3)]);
        $this->assertSame('FR-001', $fridge->value('serial_number'));
        $this->assertTrue($fridge->due_on->isSameDay(today()->subMonths(3)->addMonthsNoOverflow(24)));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'warranties']), ['title' => 'Fridge again', 'status' => 'active', 'data' => ['serial_number' => 'FR-001']])
            ->assertSessionHasErrors(['data.serial_number' => 'Serial FR-001 is already under warranty '.$fridge->number.'.']);
        $old = $this->record($workspace, $app, 'warranties', 'Old kettle', 'active', ['serial_number' => 'K-9', 'months' => 6], ['occurs_on' => today()->subYear()]);
        $this->assertSame('expired', $old->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'returns']), ['title' => 'Kettle broke', 'status' => 'requested', 'data' => ['warranty' => $old->id, 'reason' => 'faulty']])
            ->assertSessionHasErrors(['data.warranty' => 'The warranty on Old kettle ended on '.$old->due_on->format('d M Y').'.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'returns']), ['title' => 'Don\'t want it', 'status' => 'requested', 'data' => ['warranty' => $fridge->id, 'reason' => 'changed_mind']])
            ->assertSessionHasErrors(['data.reason' => 'Change-of-mind returns are only taken within 14 days of the sale.']);

        $claim = $this->record($workspace, $app, 'returns', 'Not cooling', 'requested', ['warranty' => $fridge->id, 'reason' => 'faulty']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Returns waiting')->assertSee('Not cooling');
        $this->actingAs($owner)->post($claim->url().'/actions/approve')->assertSessionHas('flash.message', 'Not cooling approved; waiting for the item.');
        $this->actingAs($owner)->post($claim->url().'/actions/receive')->assertSessionHas('flash.message', 'Not cooling received.');
        $this->actingAs($owner)->post($claim->url().'/actions/refunded', ['resolution' => 'Could not repair.'])->assertSessionHasErrors(['amount' => 'Give the refund amount.']);
        $this->actingAs($owner)->post($claim->url().'/actions/refunded', ['resolution' => 'Could not repair.', 'amount' => 4500])->assertSessionHas('flash.message', 'Not cooling refunded '.$this->money(4500).'.');
        $this->actingAs($owner)->get($fridge->url())->assertOk()->assertSee('Claims')->assertSee('Not cooling')->assertSee('Days left');

        $ending = $this->record($workspace, $app, 'warranties', 'Toaster', 'active', ['serial_number' => 'T-1', 'months' => 1], ['occurs_on' => today()->subMonth()->addDay()]);
        $this->assertSame('active', $ending->status);
        $this->travel(2)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $ending->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Returns by reason')->assertSee('Faulty')->assertSee($this->money(4500));
    }

    public function test_service_contracts_schedule_visits_and_come_up_for_renewal(): void
    {
        $app = 'service-contracts-amc-annual';
        [$owner, $workspace] = $this->appWorkspace($app);
        $contract = $this->record($workspace, $app, 'contracts', 'Generator AMC', 'active', ['equipment' => '1 x 50kVA generator', 'visits_per_year' => 4], ['amount' => 24000, 'occurs_on' => today()]);
        $this->assertTrue($contract->due_on->isSameDay(today()->addYear()));
        $this->actingAs($owner)->post($contract->url().'/actions/schedule')->assertSessionHas('flash.message', 'Scheduled 4 visits for Generator AMC.');
        $this->assertSame(4, Record::query()->where('entity', 'visits')->where('status', 'scheduled')->count());
        $this->actingAs($owner)->post($contract->url().'/actions/schedule')->assertSessionHas('flash.message', 'All 4 visits for Generator AMC are already booked.');

        $visit = Record::query()->where('entity', 'visits')->orderBy('occurs_on')->firstOrFail();
        $this->actingAs($owner)->post($visit->url().'/actions/complete', ['report' => ''])->assertSessionHasErrors(['report' => 'Write what was done on the visit.']);
        $this->actingAs($owner)->post($visit->url().'/actions/complete', ['report' => 'Oil and filters changed.'])->assertSessionHas('flash.message', $visit->title.' completed.');
        $this->actingAs($owner)->get($contract->url())->assertOk()->assertSee('Visits this term: 1 of 4 done');

        $aircon = $this->record($workspace, $app, 'contracts', 'Aircon AMC', 'active', ['equipment' => '6 split units', 'visits_per_year' => 2], ['amount' => 9000, 'occurs_on' => today()->subYear()->addDays(20), 'due_on' => today()->addDays(20)]);
        $this->assertSame('expiring', $aircon->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Up for renewal')->assertSee('Aircon AMC');
        $this->actingAs($owner)->post($aircon->url().'/actions/renew')->assertSessionHas('flash.message', 'Aircon AMC renewed until '.today()->addDays(21)->addYear()->format('d M Y').'.');
        $this->assertSame('active', $aircon->fresh()->status);

        $lapsed = $this->record($workspace, $app, 'contracts', 'Lift AMC', 'cancelled', ['equipment' => 'Lift']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visits']), ['title' => 'Check', 'status' => 'scheduled', 'data' => ['contract' => $lapsed->id]])
            ->assertSessionHasErrors(['data.contract' => 'Lift AMC is cancelled.']);
        $late = $this->record($workspace, $app, 'visits', 'Extra call-out', 'scheduled', ['contract' => $contract->id], ['occurs_on' => today()]);
        $this->travel(1)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $late->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Visits by contract')->assertSee('Generator AMC')->assertSee($this->money(24000));
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    private function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'status' => $status,
            ...$attributes,
        ]);
    }
}
