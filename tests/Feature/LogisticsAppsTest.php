<?php

namespace Tests\Feature;

use App\Blueprints\Logic\FreightLogic;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class LogisticsAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_inventory_moves_stock_only_through_movements(): void
    {
        $app = 'inventory';
        [$owner, $workspace] = $this->appWorkspace($app);
        $item = fn (string $title, array $data, string $status = 'active') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'items']), [
            'title' => $title, 'status' => $status, 'data' => ['unit' => 'each', 'cost_price' => 5, 'selling_price' => 10, 'quantity' => 0, 'reorder_level' => 5, ...$data],
        ]);

        $item('Widget', ['sku' => 'W-1'])->assertSessionHasNoErrors();
        $item('Copy', ['sku' => ' w-1 '])->assertSessionHasErrors('data.sku');
        $widget = Record::query()->where('entity', 'items')->firstOrFail();
        $this->assertEquals(50, $widget->value('_margin'));

        $this->actingAs($owner)->post($widget->url().'/actions/receive', ['quantity' => 20, 'reference' => 'DN-4'])->assertSessionHas('flash.message', '20 Widget received; 20 on hand.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'items', $widget->id]), [
            'title' => 'Widget', 'status' => 'active', 'data' => ['sku' => 'W-1', 'cost_price' => 5, 'selling_price' => 10, 'quantity' => 50],
        ])->assertSessionHasErrors('data.quantity');

        $move = fn (string $type, float $quantity, ?Record $for = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'movements']), [
            'title' => 'Counter sale', 'status' => 'posted', 'occurs_on' => today()->toDateString(), 'data' => ['item' => ($for ?? $widget)->id, 'type' => $type, 'quantity' => $quantity],
        ]);
        $move('sold', 30)->assertSessionHasErrors('data.quantity');
        $move('sold', 5)->assertSessionHasNoErrors();
        $this->assertEquals(15, $widget->fresh()->value('quantity'));
        $sale = Record::query()->where('entity', 'movements')->where('data->type', 'sold')->firstOrFail();
        $this->assertEquals(25, $sale->amount);

        $this->actingAs($owner)->post($widget->url().'/actions/count', ['counted' => 12])->assertSessionHas('flash.message', 'Widget counted at 12: −3 adjusted.');
        $this->assertEquals(12, $widget->fresh()->value('quantity'));
        $this->actingAs($owner)->post($sale->url().'/actions/reverse', ['reason' => 'Rang up twice']);
        $this->assertEquals(17, $widget->fresh()->value('quantity'));
        $this->assertSame('reversed', $sale->fresh()->status);

        $item('Old gadget', ['sku' => 'G-1'], 'discontinued');
        $move('received', 5, Record::query()->where('title', 'Old gadget')->firstOrFail())->assertSessionHasErrors('data.quantity');

        $batch = $this->record($workspace, $app, 'batches', 'LOT-9', 'in_stock', ['item' => $widget->id, 'quantity' => 10], ['due_on' => today()->addDay()]);
        $this->travel(2)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $batch->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Stock valuation')->assertSee('Widget');
    }

    public function test_warehouse_keeps_bins_picks_and_transfers_in_order(): void
    {
        $app = 'warehouse';
        [$owner, $workspace] = $this->appWorkspace($app);
        $main = $this->record($workspace, $app, 'warehouses', 'Main', 'active');
        $north = $this->record($workspace, $app, 'warehouses', 'North', 'active');
        $bin = fn (string $code, Record $warehouse, string $contents = '') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bins']), [
            'title' => $code, 'status' => 'empty', 'data' => ['warehouse' => $warehouse->id, 'zone' => 'A', 'contents' => $contents],
        ]);

        $bin('a-01', $main, 'Widgets')->assertSessionHasNoErrors();
        $first = Record::query()->where('entity', 'bins')->firstOrFail();
        $this->assertSame('A-01', $first->title);
        $this->assertSame('in_use', $first->status);
        $bin('A-01 ', $main)->assertSessionHasErrors('title');
        $bin('A-01', $north)->assertSessionHasNoErrors();

        $close = fn () => $this->actingAs($owner)->put(route('apps.records.update', [$app, 'warehouses', $main->id]), ['title' => 'Main', 'status' => 'closed', 'data' => []]);
        $close()->assertSessionHasErrors('status');

        $pick = $this->record($workspace, $app, 'picks', 'SO-100', 'to_pick', ['warehouse' => $main->id, 'lines' => "Widget x 2 @ A-01\nGadget x 1 @ A-02"]);
        $this->actingAs($owner)->post($pick->url().'/actions/start');
        $this->actingAs($owner)->post($pick->url().'/actions/pack', ['packages' => 2])->assertSessionHas('flash.message', 'SO-100 packed in 2 packages.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'picks', $pick->id]), [
            'title' => 'SO-100', 'status' => 'to_pick', 'occurs_on' => today()->toDateString(), 'data' => ['warehouse' => $main->id, 'lines' => 'Widget', 'packages' => 2],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($pick->url().'/actions/dispatch');
        $this->assertSame('dispatched', $pick->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [
            'title' => 'Restock', 'status' => 'draft', 'occurs_on' => today()->toDateString(), 'data' => ['from_warehouse' => $main->id, 'to_warehouse' => $main->id, 'items' => 'Widget x 5'],
        ])->assertSessionHasErrors('data.to_warehouse');
        $transfer = $this->record($workspace, $app, 'transfers', 'Restock', 'draft', ['from_warehouse' => $main->id, 'to_warehouse' => $north->id, 'items' => 'Widget x 5']);
        $this->actingAs($owner)->post($transfer->url().'/actions/send');
        $this->actingAs($owner)->post($transfer->url().'/actions/receive');
        $this->assertSame('received', $transfer->fresh()->status);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'bins', $first->id]), ['title' => 'A-01', 'status' => 'in_use', 'data' => ['warehouse' => $main->id, 'contents' => '']]);
        $this->assertSame('empty', $first->fresh()->status);
        $this->actingAs($owner)->post($first->url().'/actions/block', ['reason' => 'Racking damaged'])->assertSessionHas('flash.message', 'Bin A-01 blocked: Racking damaged.');
        $close()->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'picks']), [
            'title' => 'SO-101', 'status' => 'to_pick', 'occurs_on' => today()->toDateString(), 'data' => ['warehouse' => $main->id, 'lines' => 'Widget x 1'],
        ])->assertSessionHasErrors('data.warehouse');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Picking by warehouse');
    }

    public function test_suppliers_need_details_for_approval_and_keep_one_current_price_per_item(): void
    {
        $app = 'suppliers';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'suppliers']), ['title' => 'Fast Co', 'status' => 'approved', 'data' => []])
            ->assertSessionHasErrors(['data.tax_number', 'data.bank_details']);
        $acme = $this->record($workspace, $app, 'suppliers', 'Acme', 'pending', ['tax_number' => 'TPIN 100-200', 'bank_details' => 'NBM 1001']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'suppliers']), ['title' => 'Copycat', 'status' => 'pending', 'data' => ['tax_number' => 'tpin100200']])
            ->assertSessionHasErrors('data.tax_number');
        $this->actingAs($owner)->post($acme->url().'/actions/approve')->assertSessionHas('flash.message', 'Acme approved.');

        $price = fn (Record $supplier, string $item, float $unitPrice) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'prices']), [
            'title' => $item, 'status' => 'current', 'data' => ['supplier' => $supplier->id, 'unit_price' => $unitPrice, 'lead_time' => 3],
        ]);
        $price($acme, 'Cement', 0)->assertSessionHasErrors('data.unit_price');
        $price($acme, 'Cement', 100)->assertSessionHasNoErrors();
        $price($acme, 'cement ', 90)->assertSessionHasNoErrors();
        $this->assertSame(['expired', 'current'], Record::query()->where('entity', 'prices')->orderBy('id')->pluck('status')->all());

        $beta = $this->record($workspace, $app, 'suppliers', 'Beta', 'approved', ['tax_number' => 'B1', 'bank_details' => 'FDH 2']);
        $price($beta, 'Cement', 72)->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Price comparison')->assertSee('Beta')->assertSee('20%');

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'suppliers', $acme->id]), ['title' => 'Acme', 'status' => 'blocked', 'data' => ['tax_number' => 'TPIN 100-200', 'bank_details' => 'NBM 1001']])
            ->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($acme->url().'/actions/block', ['reason' => 'Short deliveries'])->assertSessionHas('flash.message', 'Acme blocked; 1 price withdrawn.');
        $price($acme, 'Sand', 20)->assertSessionHasErrors('data.supplier');
        $this->actingAs($owner)->post($beta->url().'/actions/rate', ['rating' => 6])->assertSessionHasErrors('rating');

        $old = $this->record($workspace, $app, 'prices', 'Stone', 'current', ['supplier' => $beta->id, 'unit_price' => 30], ['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $old->fresh()->status);
    }

    public function test_manufacturing_costs_bills_and_completes_work_orders_after_a_passed_check(): void
    {
        $app = 'manufacturing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $bom = fn (string $components) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'boms']), [
            'title' => 'Chair', 'status' => 'active', 'data' => ['output_quantity' => 2, 'components' => $components],
        ]);

        $bom("4 x Leg\nGlue")->assertSessionHasErrors('data.components');
        $bom("4 x Leg @ 5\n1 x Seat @ 30")->assertSessionHasNoErrors();
        $chair = Record::query()->where('entity', 'boms')->firstOrFail();
        $this->assertEquals(25, $chair->amount);

        $order = fn (float $quantity, float $made = 0) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'work_orders']), [
            'title' => 'Chairs for school', 'status' => 'planned', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addWeek()->toDateString(), 'data' => ['bom' => $chair->id, 'quantity' => $quantity, 'quantity_made' => $made],
        ]);
        $order(10, 12)->assertSessionHasErrors('data.quantity_made');
        $order(10)->assertSessionHasNoErrors();
        $workOrder = Record::query()->where('entity', 'work_orders')->firstOrFail();
        $this->actingAs($owner)->get($workOrder->url())->assertOk()->assertSee('Materials needed')->assertSee('Leg');

        $this->actingAs($owner)->post($workOrder->url().'/actions/start');
        $this->actingAs($owner)->post($workOrder->url().'/actions/to_qc', ['quantity_made' => 11])->assertSessionHasErrors('quantity_made');
        $this->actingAs($owner)->post($workOrder->url().'/actions/to_qc', ['quantity_made' => 9, 'scrap' => 1]);
        $this->assertSame('qc', $workOrder->fresh()->status);
        $this->assertEquals(250, $workOrder->fresh()->value('_cost'));
        $this->actingAs($owner)->post($workOrder->url().'/actions/complete');
        $this->assertSame('qc', $workOrder->fresh()->status);

        $check = fn (int $sample, int $defects) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'quality_checks']), [
            'title' => 'Final check', 'status' => 'failed', 'occurs_on' => today()->toDateString(), 'data' => ['work_order' => $workOrder->id, 'sample_size' => $sample, 'defects' => $defects],
        ]);
        $check(5, 6)->assertSessionHasErrors('data.defects');
        $check(20, 2)->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertEquals(1, $workOrder->fresh()->value('_sent_back'));

        $this->actingAs($owner)->post($workOrder->url().'/actions/to_qc', ['quantity_made' => 9, 'scrap' => 1]);
        $check(40, 1)->assertSessionHasNoErrors();
        $this->assertSame('passed', Record::query()->where('entity', 'quality_checks')->orderByDesc('id')->firstOrFail()->status);
        $this->actingAs($owner)->post($workOrder->url().'/actions/complete')->assertSessionHasNoErrors();
        $this->assertSame('completed', $workOrder->fresh()->status);

        $order(5)->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'boms', $chair->id]), ['title' => 'Chair', 'status' => 'obsolete', 'data' => ['output_quantity' => 2, 'components' => '4 x Leg @ 5']])
            ->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.reports', $app, ['to' => today()->addMonth()->toDateString()]))->assertOk()->assertSee('Output against plan')->assertSee('Chair')->assertSee('10%');
    }

    public function test_orders_hold_shipping_for_back_orders(): void
    {
        $app = 'orders';
        [$owner, $workspace] = $this->appWorkspace($app);
        $store = fn (string $items) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), [
            'title' => 'Order for Banda', 'status' => 'new', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(3)->toDateString(), 'data' => ['channel' => 'phone', 'items' => $items],
        ]);

        $store('Widgets')->assertSessionHasErrors('data.items');
        $store("2 x Widget @ 10\n1 x Gadget @ 25")->assertSessionHasNoErrors();
        $order = Record::query()->where('entity', 'orders')->firstOrFail();
        $this->assertEquals(45, $order->amount);

        $this->actingAs($owner)->post($order->url().'/actions/advance')->assertSessionHas('flash.message', 'Order for Banda is confirmed.');
        $this->actingAs($owner)->post($order->url().'/actions/backorder', ['item' => 'Gadget', 'quantity' => 1])->assertSessionHas('flash.message', 'Gadget back-ordered for Order for Banda.');
        $this->actingAs($owner)->post($order->url().'/actions/advance');
        $this->actingAs($owner)->post($order->url().'/actions/advance')->assertSessionHas('flash.message', 'Order for Banda is part shipped.');

        $put = fn (string $status) => $this->actingAs($owner)->put(route('apps.records.update', [$app, 'orders', $order->id]), [
            'title' => 'Order for Banda', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['channel' => 'phone', 'items' => '2 x Widget @ 10'],
        ]);
        $put('shipped')->assertSessionHasErrors('status');
        $put('new')->assertSessionHasErrors('status');

        $backorder = Record::query()->where('entity', 'backorders')->firstOrFail();
        $this->actingAs($owner)->post($backorder->url().'/actions/ready');
        $this->actingAs($owner)->post($backorder->url().'/actions/fulfil')->assertSessionHas('flash.message', 'Gadget fulfilled; Order for Banda is now fully shipped.');
        $this->assertSame('shipped', $order->fresh()->status);
        $put('cancelled')->assertSessionHasErrors('status');

        $late = $this->record($workspace, $app, 'orders', 'Late order', 'confirmed', ['channel' => 'online', 'items' => '1 x Widget @ 10'], ['occurs_on' => today()->subWeek(), 'due_on' => today()->subDay()]);
        $this->actingAs($owner)->post($late->url().'/actions/backorder', ['item' => 'Widget', 'quantity' => 1]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Late orders')->assertSee('Late order');
        $this->actingAs($owner)->post($late->url().'/actions/cancel', ['reason' => 'Customer changed mind']);
        $this->assertSame('cancelled', Record::query()->where('entity', 'backorders')->orderByDesc('id')->firstOrFail()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Orders by channel')->assertSee('Phone')->assertSee('Back-ordered items');
    }

    public function test_delivery_tracks_parcels_and_runs_one_route_per_driver_a_day(): void
    {
        $app = 'delivery';
        [$owner, $workspace] = $this->appWorkspace($app);
        $parcel = fn (array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'parcels']), [
            'title' => 'Documents', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'data' => ['recipient' => 'Mary Phiri', 'address' => 'Area 47, Lilongwe', ...$data],
        ]);

        $parcel(['cod_amount' => 50])->assertSessionHasNoErrors();
        $cod = Record::query()->where('entity', 'parcels')->firstOrFail();
        $this->assertStringStartsWith('ZN'.today()->format('ymd'), $cod->value('tracking_number'));
        $parcel(['tracking_number' => strtolower($cod->value('tracking_number'))])->assertSessionHasErrors('data.tracking_number');
        $this->actingAs($owner)->post($cod->url().'/actions/collect');
        $plain = $this->record($workspace, $app, 'parcels', 'Spares', 'in_transit', ['recipient' => 'John Banda', 'address' => 'Area 3'], ['assignee_id' => $owner->id]);

        $run = fn () => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'runs']), [
            'title' => 'City route', 'status' => 'planned', 'occurs_on' => today()->toDateString(), 'data' => ['driver' => $owner->id, 'vehicle' => 'KA 1234'],
        ]);
        $run()->assertSessionHasNoErrors();
        $run()->assertSessionHasErrors('data.driver');
        $route = Record::query()->where('entity', 'runs')->firstOrFail();
        $this->actingAs($owner)->post($route->url().'/actions/start')->assertSessionHas('flash.message', 'City route started with 2 parcels.');
        $this->assertSame('out_for_delivery', $cod->fresh()->status);
        $this->actingAs($owner)->post($route->url().'/actions/complete')->assertSessionHasErrors('status');

        $this->actingAs($owner)->post($cod->url().'/actions/deliver', ['received_by' => 'Mary', 'collected' => 40])->assertSessionHasErrors('collected');
        $this->actingAs($owner)->post($cod->url().'/actions/deliver', ['received_by' => 'Mary', 'collected' => 50])->assertSessionHasNoErrors();
        $this->assertSame('delivered', $cod->fresh()->status);

        $this->actingAs($owner)->post($plain->url().'/actions/fail')->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post($plain->url().'/actions/fail', ['reason' => 'Nobody home'])->assertSessionHas('flash.message', 'Attempt 1 failed: Nobody home.');
        foreach ([2, 3] as $attempt) {
            $this->actingAs($owner)->post($plain->url().'/actions/out');
            $this->actingAs($owner)->post($plain->url().'/actions/fail', ['reason' => 'Gate locked']);
        }
        $this->assertSame('returned', $plain->fresh()->status);
        $this->actingAs($owner)->post($route->url().'/actions/complete')->assertSessionHas('flash.message', 'City route completed: 1 of 2 delivered.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Success rate by driver')->assertSee($owner->name)->assertSee('50%');
    }

    public function test_freight_checks_containers_and_releases_shipments_through_customs(): void
    {
        $app = 'freight-forwarding';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->assertTrue(app(FreightLogic::class)->validContainer('CSQU3054383'));
        $this->assertFalse(app(FreightLogic::class)->validContainer('CSQU3054384'));

        $shipment = fn (array $data, array $payload = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shipments']), [
            'title' => 'Fertiliser', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addWeeks(3)->toDateString(), ...$payload,
            'data' => ['mode' => 'sea', 'origin' => 'Durban', 'destination' => 'Beira', ...$data],
        ]);
        $shipment(['destination' => ' durban'])->assertSessionHasErrors('data.destination');
        $shipment([], ['due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('due_on');
        $shipment([], ['status' => 'in_transit'])->assertSessionHasErrors('data.bill_of_lading');
        $shipment(['bill_of_lading' => 'MSC-1'])->assertSessionHasNoErrors();
        $sea = Record::query()->where('entity', 'shipments')->firstOrFail();
        $air = $this->record($workspace, $app, 'shipments', 'Spares', 'booked', ['mode' => 'air', 'origin' => 'Dubai', 'destination' => 'Lilongwe']);

        $container = fn (string $number, Record $for, array $data = [], string $status = 'empty') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'containers']), [
            'title' => $number, 'status' => $status, 'data' => ['shipment' => $for->id, 'size' => '40ft', ...$data],
        ]);
        $container('CSQU3054384', $sea)->assertSessionHasErrors('title');
        $container('CSQU3054383', $air)->assertSessionHasErrors('data.shipment');
        $container('CSQU3054383', $sea, [], 'loaded')->assertSessionHasErrors('data.seal_number');
        $container('csqu 305438 3', $sea, ['seal_number' => 'S-9'], 'loaded')->assertSessionHasNoErrors();
        $this->assertSame('CSQU3054383', Record::query()->where('entity', 'containers')->firstOrFail()->title);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'shipments', $sea->id]), [
            'title' => 'Fertiliser', 'status' => 'released', 'occurs_on' => today()->toDateString(), 'data' => ['mode' => 'sea', 'origin' => 'Durban', 'destination' => 'Beira', 'bill_of_lading' => 'MSC-1'],
        ])->assertSessionHasErrors('status');

        $entry = $this->record($workspace, $app, 'clearances', 'Fertiliser entry', 'lodged', ['shipment' => $sea->id, 'entry_number' => 'C-2026-1']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'clearances']), ['title' => 'Again', 'status' => 'lodged', 'data' => ['shipment' => $sea->id, 'entry_number' => 'c-2026-1']])
            ->assertSessionHasErrors('data.entry_number');
        $this->actingAs($owner)->post($entry->url().'/actions/query', ['reason' => 'Invoice missing']);
        $this->actingAs($owner)->post($entry->url().'/actions/relodge');
        $this->actingAs($owner)->post($entry->url().'/actions/assess', ['duty' => 1200])->assertSessionHas('flash.message', 'Fertiliser entry assessed at '.$this->money(1200).'.');
        $this->assertEquals(1200, $entry->fresh()->amount);
        $this->actingAs($owner)->post($entry->url().'/actions/pay');
        $this->actingAs($owner)->post($entry->url().'/actions/release');
        $this->assertSame('released', $sea->fresh()->status);

        $late = $this->record($workspace, $app, 'containers', 'MSKU1234565', 'loaded', ['shipment' => $sea->id, 'seal_number' => 'S-1'], ['due_on' => today()->subDays(3)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Containers past return-by')->assertSee('MSKU1234565');
        $this->actingAs($owner)->post($late->url().'/actions/return')->assertSessionHas('flash.message', 'MSKU1234565 returned 3 days late: '.$this->money(3 * FreightLogic::DEMURRAGE_PER_DAY).' demurrage.');

        $kept = $this->record($workspace, $app, 'containers', 'MSCU1234566', 'loaded', ['shipment' => $sea->id, 'seal_number' => 'S-2'], ['due_on' => today()]);
        $this->travel(2)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertEquals(2, $kept->fresh()->value('_demurrage_days'));

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Shipments by mode')->assertSee('Demurrage')->assertSee('MSKU1234565');
    }

    /**
     * Format money the way the app does.
     */
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
