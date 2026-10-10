<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The second logistics batch: consignment stock, equipment hire, weighbridge, quality management and asset tracking. */
class LogisticsAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_consignment_settlements_cannot_outsell_the_stock_placed(): void
    {
        $app = 'wholesale-distribution-consignment-stock';
        [$owner] = $this->appWorkspace($app);
        $placed = [
            'title' => 'Daka Hardware', 'status' => 'placed', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addMonth()->toDateString(),
            'data' => ['items' => "20 x Cement @ 15\n10 x Lime @ 5", 'commission_rate' => 10],
        ];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'consignments']), [...$placed, 'due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('due_on');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'consignments']), $placed)->assertSessionHasNoErrors();
        $consignment = Record::query()->where('entity', 'consignments')->firstOrFail();
        $this->assertEquals(350, $consignment->amount);
        $this->assertEquals(30, $consignment->value('_units'));

        $settle = fn (float $units) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'settlements']), [
            'title' => 'Week 1', 'status' => 'pending', 'occurs_on' => today()->toDateString(), 'data' => ['consignment' => $consignment->id, 'units_sold' => $units],
        ]);
        $settle(40)->assertSessionHasErrors(['data.units_sold' => 'Only 30 units are left with the dealer.']);
        $settle(15)->assertSessionHasNoErrors();
        $settlement = Record::query()->where('entity', 'settlements')->firstOrFail();
        $this->assertEquals(157.5, $settlement->amount);
        $consignment->refresh();
        $this->assertSame('partly_sold', $consignment->status);
        $this->assertEquals(175, $consignment->value('sold_value'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'consignments', $consignment->id]), [...$placed, 'status' => 'settled'])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($settlement->url().'/actions/pay')->assertSessionHas('flash.message', 'Week 1 paid: '.$this->money(157.5).'.');
        $this->assertEquals(157.5, $consignment->fresh()->value('_paid'));

        $this->actingAs($owner)->post($consignment->url().'/actions/take_back', ['units' => 20])->assertSessionHasErrors('units');
        $this->actingAs($owner)->post($consignment->url().'/actions/take_back', ['units' => 15])->assertSessionHas('flash.message', '15 units taken back from Daka Hardware.');
        $this->assertSame('returned', $consignment->fresh()->status);
        $settle(1)->assertSessionHasErrors('data.consignment');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sell-through by dealer')->assertSee('50%');
    }

    public function test_equipment_hire_books_only_free_stock_and_charges_late_returns(): void
    {
        $app = 'equipment-tool-hire-tents';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->record($workspace, $app, 'items', 'Chairs', 'available', ['category' => 'chairs', 'quantity' => 100, 'daily_rate' => 2]);
        $this->record($workspace, $app, 'items', 'Tent', 'available', ['category' => 'tent', 'quantity' => 1, 'daily_rate' => 50]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'items']), ['title' => ' chairs ', 'status' => 'available', 'data' => ['quantity' => 5]])->assertSessionHasErrors('title');

        $book = fn (string $title, string $items, string $status) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => $title, 'status' => $status, 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(2)->toDateString(), 'data' => ['items' => $items, 'deposit' => 200],
        ]);
        $book('Wedding', "60 x Chairs\n1 x Marquee", 'booked')->assertSessionHasErrors(['data.items' => 'There is no hire item called "Marquee".']);
        $book('Wedding', "60 x Chairs\n1 x Tent", 'booked')->assertSessionHasNoErrors();
        $wedding = Record::query()->where('entity', 'bookings')->firstOrFail();
        $this->assertEquals(510, $wedding->amount);
        $book('Funeral', '50 x Chairs', 'quoted')->assertSessionHasErrors(['data.items' => 'Only 40 Chairs free on those dates.']);
        $book('Funeral', '40 x Chairs', 'quoted')->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($wedding->url().'/actions/dispatch')->assertSessionHas('flash.message', 'Wedding sent out.');
        $this->travel(4)->days();
        $this->actingAs($owner)->post($wedding->url().'/actions/return', ['damage_charge' => 30])->assertSessionHasErrors('damages');
        $this->actingAs($owner)->post($wedding->url().'/actions/return', ['damages' => 'Torn flap', 'damage_charge' => 30])
            ->assertSessionHas('flash.message', 'Wedding returned 2 days late; refund '.$this->money(0).' of the deposit and '.$this->money(170).' still owed.');
        $wedding->refresh();
        $this->assertSame('returned', $wedding->status);
        $this->assertEquals(340, $wedding->value('_late_fee'));

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hire by item')->assertSee('Chairs');
    }

    public function test_weighbridge_works_out_net_mass_from_two_weighs(): void
    {
        $app = 'weighbridge-bulk-dispatch-quarry';
        [$owner] = $this->appWorkspace($app);
        $arrive = fn (string $registration, string $direction, float $mass) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => $registration, 'status' => 'first_weigh', 'occurs_on' => now()->toDateTimeString(), 'data' => ['direction' => $direction, 'product' => 'Stone', 'gross_mass' => $mass, 'order_reference' => 'SO-12'],
        ]);
        $arrive('abc 123', 'outbound', 15000)->assertSessionHasNoErrors();
        $ticket = Record::query()->where('entity', 'tickets')->firstOrFail();
        $this->assertSame('ABC123', $ticket->title);
        $arrive('ABC-123', 'outbound', 15000)->assertSessionHasErrors('title');

        $this->actingAs($owner)->post($ticket->url().'/actions/second_weigh', ['mass' => 14000])->assertSessionHasErrors('mass');
        $this->actingAs($owner)->post($ticket->url().'/actions/second_weigh', ['mass' => 60000])
            ->assertSessionHas('flash.message', 'ABC123: 45,000 kg net Stone; overloaded at 60,000 kg gross.');
        $ticket->refresh();
        $this->assertSame('completed', $ticket->status);
        $this->assertEquals(15000, $ticket->value('tare_mass'));
        $this->assertEquals(45000, $ticket->value('net_mass'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'tickets', $ticket->id]), [
            'title' => 'ABC123', 'status' => 'completed', 'data' => [...$ticket->data, 'gross_mass' => 50000],
        ])->assertSessionHasErrors('data.gross_mass');
        $this->actingAs($owner)->get(route('apps.records.document', [$app, 'tickets', $ticket->id, 'dispatch_note']))->assertOk()->assertSee('Dispatch note')->assertSee('45.00 t');

        $arrive('XYZ 9', 'inbound', 30000);
        $inbound = Record::query()->where('title', 'XYZ9')->firstOrFail();
        $this->actingAs($owner)->post($inbound->url().'/actions/second_weigh', ['mass' => 31000])->assertSessionHasErrors('mass');
        $this->actingAs($owner)->post($inbound->url().'/actions/void', ['reason' => 'Wrong truck'])->assertSessionHas('flash.message', $inbound->number.' voided: Wrong truck.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Overloads and voided tickets')->assertSee('Wrong truck');
    }

    public function test_quality_closes_non_conformances_only_after_verified_actions(): void
    {
        $app = 'quality-management-iso';
        [$owner, $workspace] = $this->appWorkspace($app);
        $raise = fn (array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'ncrs']), [
            'title' => 'Short-filled bags', 'status' => 'open', 'occurs_on' => today()->toDateString(), 'data' => ['source' => 'supplier', 'severity' => 'major'], ...$extra,
        ]);
        $raise()->assertSessionHasErrors('assignee_id');
        $raise(['assignee_id' => $owner->id])->assertSessionHasNoErrors();
        $ncr = Record::query()->where('entity', 'ncrs')->firstOrFail();

        $this->actingAs($owner)->post($ncr->url().'/actions/investigate');
        $this->actingAs($owner)->post($ncr->url().'/actions/close', ['root_cause' => 'Scale drift'])->assertSessionHasErrors(['status' => 'Add a corrective action before closing.']);

        $action = fn (array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'capas']), [
            'title' => 'Calibrate filler scale', 'status' => 'planned', 'data' => ['ncr' => $ncr->id, 'type' => 'corrective'], ...$extra,
        ]);
        $action()->assertSessionHasErrors('due_on');
        $action(['due_on' => today()->addDays(5)->toDateString()])->assertSessionHasNoErrors();
        $capa = Record::query()->where('entity', 'capas')->firstOrFail();

        $this->actingAs($owner)->post($ncr->url().'/actions/close', ['root_cause' => 'Scale drift'])->assertSessionHasErrors(['status' => '1 corrective action not verified yet.']);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'capas', $capa->id]), [
            'title' => 'Calibrate filler scale', 'status' => 'closed', 'due_on' => today()->addDays(5)->toDateString(), 'data' => ['ncr' => $ncr->id, 'type' => 'corrective', 'effectiveness' => 'Fine'],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post($capa->url().'/actions/start');
        $this->actingAs($owner)->post($capa->url().'/actions/verify', ['effectiveness' => ''])->assertSessionHasErrors('effectiveness');
        $this->actingAs($owner)->post($capa->url().'/actions/verify', ['effectiveness' => 'Ten checks in spec'])->assertSessionHas('flash.message', 'Calibrate filler scale verified.');
        $this->actingAs($owner)->post($ncr->url().'/actions/close', ['root_cause' => 'Scale drift'])->assertSessionHas('flash.message', 'Short-filled bags closed after 0 days.');
        $this->assertSame('closed', $ncr->fresh()->status);

        $audit = $this->record($workspace, $app, 'audits', 'Clause 8.5', 'planned', ['standard' => 'iso_9001']);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'audits', $audit->id]), [
            'title' => 'Clause 8.5', 'status' => 'done', 'occurs_on' => today()->toDateString(), 'data' => ['standard' => 'iso_9001'],
        ])->assertSessionHasErrors('data.findings');
        $audit->update(['status' => 'done', 'data' => [...$audit->data, 'findings' => 'Work instruction out of date']]);
        $this->actingAs($owner)->post($audit->url().'/actions/raise_ncr', ['description' => 'Work instruction out of date', 'severity' => 'minor'])->assertSessionHasNoErrors();
        $raised = Record::query()->where('entity', 'ncrs')->where('title', 'Work instruction out of date')->firstOrFail();
        $this->assertSame('internal_audit', $raised->value('source'));

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Non-conformances by source')->assertSee('100%');
    }

    public function test_asset_tracking_follows_check_outs_and_returns(): void
    {
        $app = 'asset-tracking-with-qr';
        [$owner] = $this->appWorkspace($app);
        $asset = fn (string $title, string $tag) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'assets']), ['title' => $title, 'status' => 'available', 'amount' => 400, 'data' => ['tag' => $tag]]);
        $asset('Drill', 'qr-001')->assertSessionHasNoErrors();
        $asset('Saw', 'QR 001')->assertSessionHasErrors('data.tag');
        $drill = Record::query()->where('entity', 'assets')->firstOrFail();
        $this->assertSame('QR001', $drill->value('tag'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'assets', $drill->id]), ['title' => 'Drill', 'status' => 'checked_out', 'data' => ['tag' => 'QR001']])->assertSessionHasErrors('status');

        $checkOut = fn (string $due) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checkouts']), [
            'title' => 'Site job', 'status' => 'out', 'occurs_on' => today()->toDateString(), 'due_on' => $due, 'data' => ['asset' => $drill->id, 'holder' => $owner->id],
        ]);
        $checkOut(today()->subDay()->toDateString())->assertSessionHasErrors('due_on');
        $checkOut(today()->addDays(2)->toDateString())->assertSessionHasNoErrors();
        $this->assertSame('checked_out', $drill->fresh()->status);
        $checkOut(today()->addDays(2)->toDateString())->assertSessionHasErrors('data.asset');
        $checkout = Record::query()->where('entity', 'checkouts')->firstOrFail();

        $this->travel(3)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('overdue', $checkout->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue returns')->assertSee('Drill');

        $this->actingAs($owner)->post($checkout->fresh()->url().'/actions/return', ['condition' => 'damaged', 'notes' => 'Chuck broken'])
            ->assertSessionHas('flash.message', 'Drill returned damaged and sent for repair. 1 day late.');
        $this->assertSame('in_repair', $drill->fresh()->status);
        $checkOut(today()->addDays(2)->toDateString())->assertSessionHasErrors(['data.asset' => 'Drill is in repair and cannot be checked out.']);

        $this->actingAs($owner)->post($drill->url().'/actions/repaired')->assertSessionHas('flash.message', 'Drill is available again.');
        $this->assertSame('available', $drill->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Check-outs by holder')->assertSee($owner->name);
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
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
