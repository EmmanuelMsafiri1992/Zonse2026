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

/** Retail: online store, marketplace, catalogue, shipping, gift vouchers and grocery. */
class RetailAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_online_orders_ship_with_an_address_and_tracking(): void
    {
        $app = 'online-store';
        [$owner, $workspace] = $this->appWorkspace($app);
        $mug = $this->record($workspace, $app, 'products', 'Mug', 'active', ['sku' => 'MUG-1', 'price' => 80, 'compare_at_price' => 100, 'stock' => 0]);
        $this->assertSame('out_of_stock', $mug->status);
        $this->assertEquals(20, $mug->value('_discount_percent'));
        $mug->update(['data' => [...$mug->data, 'stock' => 3]]);
        $this->assertSame('active', $mug->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'products']), ['title' => 'Cup', 'status' => 'active', 'data' => ['sku' => 'mug-1', 'price' => 50, 'compare_at_price' => 40]])
            ->assertSessionHasErrors(['data.sku' => 'Mug already uses SKU mug-1.', 'data.compare_at_price' => 'The compare-at price must be above the selling price.']);

        $order = fn (string $status, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Zed', 'status' => $status, 'amount' => 160, 'data' => ['items' => '2 × Mug', ...$data]]);
        $order('packed', ['shipping_method' => 'courier'])->assertSessionHasErrors(['data.shipping_address' => 'Give the shipping address.']);
        $order('shipped', ['shipping_method' => 'post', 'shipping_address' => '1 Main Rd'])->assertSessionHasErrors(['data.tracking_number' => 'Give the tracking number.']);

        $ann = $this->record($workspace, $app, 'orders', 'Ann', 'pending_payment', ['items' => '2 × Mug', 'shipping_method' => 'courier', 'shipping_address' => '1 Main Rd', 'tracking_number' => null], ['amount' => 160, 'occurs_on' => today()]);
        $this->actingAs($owner)->post($ann->url().'/actions/paid')->assertSessionHas('flash.message', 'Ann\'s order is paid.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Orders to handle')->assertSee('Low stock')->assertSee('3 left');
        $this->actingAs($owner)->post($ann->url().'/actions/pack')->assertSessionHas('flash.message', 'Ann\'s order is packed.');
        $this->actingAs($owner)->post($ann->url().'/actions/ship', ['tracking_number' => ''])->assertSessionHasErrors(['tracking_number' => 'Give the tracking number.']);
        $this->actingAs($owner)->post($ann->url().'/actions/ship', ['tracking_number' => 'TRK1'])->assertSessionHas('flash.message', 'Ann\'s order shipped, tracking TRK1.');
        $this->assertSame('TRK1', $ann->fresh()->value('tracking_number'));

        $bo = $this->record($workspace, $app, 'orders', 'Bo', 'packed', ['items' => '1 × Mug', 'shipping_method' => 'collection'], ['amount' => 80, 'occurs_on' => today()]);
        $this->actingAs($owner)->post($bo->url().'/actions/ship')->assertSessionHas('flash.message', 'Bo collected the order.');
        $this->assertSame('delivered', $bo->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sales by month')->assertSee($this->money(240));
    }

    public function test_marketplace_vendors_must_be_approved_and_paid_out_less_commission(): void
    {
        $app = 'marketplace';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vendors']), ['title' => 'Too Dear', 'status' => 'applied', 'data' => ['commission_percent' => 120]])
            ->assertSessionHasErrors(['data.commission_percent' => 'Commission must be between 0 and 100%.']);
        $kofi = $this->record($workspace, $app, 'vendors', 'Kofi Crafts', 'applied', ['commission_percent' => 10, 'bank_details' => null]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'listings']), ['title' => 'Bowl', 'status' => 'live', 'data' => ['vendor' => $kofi->id, 'price' => 50, 'stock' => 2]])
            ->assertSessionHasErrors(['status' => 'Kofi Crafts is applied, so its listings cannot go live.']);

        $this->actingAs($owner)->post($kofi->url().'/actions/approve')->assertSessionHas('flash.message', 'Kofi Crafts is approved.');
        $bowl = $this->record($workspace, $app, 'listings', 'Bowl', 'pending_review', ['vendor' => $kofi->id, 'price' => 50, 'stock' => 0]);
        $this->actingAs($owner)->post($bowl->url().'/actions/go_live')->assertSessionHas('flash.message', 'Bowl is sold out.');
        $this->record($workspace, $app, 'listings', 'Basket', 'live', ['vendor' => $kofi->id, 'price' => 90, 'stock' => 5]);
        $this->actingAs($owner)->post($kofi->url().'/actions/suspend')->assertSessionHas('flash.message', 'Kofi Crafts suspended; 2 listings taken down for review.');
        $this->assertSame(2, Record::query()->where('entity', 'listings')->where('status', 'pending_review')->count());

        $payout = $this->record($workspace, $app, 'payouts', 'Oct 2026', 'calculated', ['vendor' => $kofi->id, 'gross_sales' => 1000, 'commission' => 0]);
        $this->assertEquals(100, $payout->value('commission'));
        $this->assertEquals(900, (float) $payout->amount);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'payouts']), ['title' => 'oct 2026', 'status' => 'calculated', 'data' => ['vendor' => $kofi->id, 'gross_sales' => 500]])
            ->assertSessionHasErrors(['title' => 'This vendor already has a payout for Oct 2026.']);
        $this->actingAs($owner)->post($payout->url().'/actions/approve_payout')->assertSessionHas('flash.message', 'Payout of '.$this->money(900).' approved.');
        $this->actingAs($owner)->post($payout->url().'/actions/pay')->assertSessionHasErrors(['status' => 'Kofi Crafts has no payout bank details.']);
        $kofi->update(['data' => [...$kofi->fresh()->data, 'bank_details' => 'Bank 123']]);
        $this->actingAs($owner)->post($payout->url().'/actions/pay')->assertSessionHas('flash.message', 'Paid '.$this->money(900).' to Kofi Crafts.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sales and commission by vendor')->assertSee('Kofi Crafts')->assertSee($this->money(100));
    }

    public function test_price_lists_discount_the_catalogue_one_list_per_group(): void
    {
        $app = 'catalog';
        [$owner, $workspace] = $this->appWorkspace($app);
        $drill = $this->record($workspace, $app, 'items', 'Drill', 'active', ['code' => 'DR-1', 'brand' => 'Bosch', 'list_price' => 200]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'items']), ['title' => 'Other drill', 'status' => 'active', 'data' => ['code' => 'dr-1', 'list_price' => 0]])
            ->assertSessionHasErrors(['data.code' => 'Drill already uses code dr-1.', 'data.list_price' => 'Set the list price.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'price_lists']), ['title' => 'Silly', 'status' => 'draft', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => ['discount_percent' => 150]])
            ->assertSessionHasErrors(['data.discount_percent' => 'The discount must be between 0 and 100%.', 'due_on' => 'The list must end after it starts.']);

        $trade = $this->record($workspace, $app, 'price_lists', 'Trade list', 'active', ['customer_group' => 'Trade', 'discount_percent' => 10], ['occurs_on' => today(), 'due_on' => today()->addMonths(6)]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'price_lists']), ['title' => 'Trade 2', 'status' => 'active', 'data' => ['customer_group' => 'trade', 'discount_percent' => 15]])
            ->assertSessionHasErrors(['data.customer_group' => 'trade already has an active price list: Trade list.']);
        $this->actingAs($owner)->get($drill->url())->assertOk()->assertSee('Trade list')->assertSee($this->money(180));
        $this->actingAs($owner)->get($trade->url())->assertOk()->assertSee('Prices on this list')->assertSee('Drill');

        $this->record($workspace, $app, 'price_lists', 'Promo list', 'active', ['customer_group' => 'Promo', 'discount_percent' => 5], ['occurs_on' => today(), 'due_on' => today()->addDays(5)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Price lists ending soon')->assertSee('Promo list');
        Record::query()->whereKey($trade->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $trade->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Catalogue by brand')->assertSee('Bosch');
    }

    public function test_shipments_need_unique_waybills_and_flag_late_deliveries(): void
    {
        $app = 'shipping';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shipments']), ['title' => 'Zed', 'status' => 'collected', 'data' => ['address' => '1 Main Rd', 'courier' => 'dhl']])
            ->assertSessionHasErrors(['data.waybill' => 'Give the waybill number once the parcel has left.']);

        $ann = $this->record($workspace, $app, 'shipments', 'Ann', 'ready', ['address' => '1 Main Rd', 'courier' => 'dhl', 'waybill' => null], ['occurs_on' => null, 'due_on' => null]);
        $this->record($workspace, $app, 'shipments', 'Bo', 'collected', ['address' => '2 Main Rd', 'courier' => 'dhl', 'waybill' => 'W1'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($ann->url().'/actions/advance', ['waybill' => ''])->assertSessionHasErrors(['waybill' => 'Give the waybill number.']);
        $this->actingAs($owner)->post($ann->url().'/actions/advance', ['waybill' => 'w1'])->assertSessionHasErrors(['waybill' => 'Waybill w1 is already on Bo\'s shipment.']);
        $this->actingAs($owner)->post($ann->url().'/actions/advance', ['waybill' => 'W2'])->assertSessionHas('flash.message', 'Ann\'s parcel is collected.');
        $this->assertTrue($ann->fresh()->due_on->isSameDay(today()->addDays(3)));

        $cy = $this->record($workspace, $app, 'shipments', 'Cy', 'in_transit', ['address' => '3 Main Rd', 'courier' => 'own'], ['occurs_on' => today()->subDays(5), 'due_on' => today()->subDays(2), 'amount' => 60]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue deliveries')->assertSee('Cy');
        $this->actingAs($owner)->post($cy->url().'/actions/advance')->assertSessionHas('flash.message', 'Cy\'s parcel is out for delivery.');
        $this->actingAs($owner)->post($cy->url().'/actions/advance')->assertSessionHas('flash.message', 'Cy\'s parcel is delivered, 2 days late.');
        $this->assertTrue($cy->fresh()->value('_late'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Courier performance')->assertSee('Own')->assertSee('0%');
    }

    public function test_vouchers_keep_a_balance_and_cannot_be_overspent(): void
    {
        $app = 'gift-cards-vouchers';
        [$owner, $workspace] = $this->appWorkspace($app);
        $gift = $this->record($workspace, $app, 'vouchers', 'abc123', 'active', ['balance' => 0], ['amount' => 500, 'occurs_on' => today(), 'due_on' => null]);
        $this->assertSame('ABC123', $gift->title);
        $this->assertEquals(500, $gift->value('balance'));
        $this->assertTrue($gift->due_on->isSameDay(today()->addMonthsNoOverflow(36)));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vouchers']), ['title' => 'abc123', 'status' => 'active', 'amount' => 100, 'data' => ['balance' => 100]])
            ->assertSessionHasErrors(['title' => 'Voucher code ABC123 is already in use.']);

        $redeem = fn (Record $voucher, float $amount, string $reference) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'redemptions']), ['title' => $reference, 'status' => 'redeemed', 'amount' => $amount, 'occurs_on' => today()->toDateString(), 'data' => ['voucher' => $voucher->id]]);
        $redeem($gift, 600, 'R1')->assertSessionHasErrors(['amount' => 'Voucher ABC123 only has '.$this->money(500).' left.']);
        $redeem($gift, 200, 'R1')->assertSessionHasNoErrors();
        $this->assertSame('partly_used', $gift->fresh()->status);
        $this->assertEquals(300, $gift->fresh()->value('balance'));
        $redeem($gift, 300, 'R2')->assertSessionHasNoErrors();
        $this->assertSame('redeemed', $gift->fresh()->status);

        $last = Record::query()->where('entity', 'redemptions')->where('title', 'R2')->first();
        $this->actingAs($owner)->post($last->url().'/actions/reverse')->assertSessionHas('flash.message', $this->money(300).' put back on voucher ABC123.');
        $this->assertSame('partly_used', $gift->fresh()->status);
        $this->actingAs($owner)->get($gift->url())->assertOk()->assertSee('Used '.$this->money(200));

        $old = $this->record($workspace, $app, 'vouchers', 'OLD1', 'active', ['balance' => 100], ['amount' => 100, 'occurs_on' => today()->subYears(3), 'due_on' => today()->addDay()]);
        Record::query()->whereKey($old->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $old->fresh()->status);
        $redeem($old, 50, 'R3')->assertSessionHasErrors(['data.voucher' => 'Voucher OLD1 is expired.']);

        $this->actingAs($owner)->post($gift->url().'/actions/void')->assertSessionHas('flash.message', 'Voucher ABC123 is void; '.$this->money(300).' can no longer be spent.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Owed to holders');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Voucher money')->assertSee('Expired unspent')->assertSee($this->money(500));
    }

    public function test_grocery_batches_are_marked_down_then_sold_or_written_off(): void
    {
        $app = 'grocery-pos';
        [$owner, $workspace] = $this->appWorkspace($app);
        $milk = $this->record($workspace, $app, 'products', 'Milk', 'on_shelf', ['barcode' => '600100', 'department' => 'dairy', 'price' => 20, 'stock' => 10, 'reorder_level' => 12]);
        $this->assertSame('low_stock', $milk->status);
        $this->assertSame('out_of_stock', $this->record($workspace, $app, 'products', 'Bread', 'on_shelf', ['barcode' => '600200', 'department' => 'bakery', 'price' => 15, 'stock' => 0])->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'products']), ['title' => 'Milk 2', 'status' => 'on_shelf', 'data' => ['barcode' => '600100', 'department' => 'dairy', 'price' => 20]])
            ->assertSessionHasErrors(['data.barcode' => 'Milk already uses barcode 600100.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'expiries']), ['title' => 'Milk batch', 'status' => 'marked_down', 'due_on' => today()->addDay()->toDateString(), 'data' => ['product' => $milk->id, 'quantity' => 4]])
            ->assertSessionHasErrors(['data.markdown_price' => 'Give the markdown price.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'expiries']), ['title' => 'Milk batch', 'status' => 'tracked', 'due_on' => today()->addDay()->toDateString(), 'data' => ['product' => $milk->id, 'quantity' => 4, 'markdown_price' => 25]])
            ->assertSessionHasErrors(['data.markdown_price' => 'The markdown price must be below the shelf price of '.$this->money(20).'.']);

        $batch = $this->record($workspace, $app, 'expiries', 'Milk batch', 'tracked', ['product' => $milk->id, 'quantity' => 4, 'markdown_price' => null], ['due_on' => today()->addDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Expiring within 3 days')->assertSee('4 × full price');
        $this->actingAs($owner)->post($batch->url().'/actions/markdown', ['markdown_price' => 15])->assertSessionHas('flash.message', 'Milk batch marked down to '.$this->money(15).'.');
        $this->actingAs($owner)->post($batch->url().'/actions/sold')->assertSessionHas('flash.message', 'Milk batch sold, losing '.$this->money(20).'.');
        $this->assertEquals(6, $milk->fresh()->value('stock'));

        $old = $this->record($workspace, $app, 'expiries', 'Old milk', 'tracked', ['product' => $milk->id, 'quantity' => 2], ['due_on' => today()->addDay()]);
        Record::query()->whereKey($old->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('written_off', $old->fresh()->status);
        $this->assertEquals(40, $old->fresh()->value('_loss'));
        $this->assertEquals(4, $milk->fresh()->value('stock'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Expiry losses by department')->assertSee('Dairy')->assertSee($this->money(60));
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
