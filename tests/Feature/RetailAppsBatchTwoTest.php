<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Retail: pharmacy, hardware, bottle store, phones by IMEI, vehicle dealership and bookshop. */
class RetailAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_scheduled_medicines_need_a_prescription_and_come_off_stock(): void
    {
        $app = 'pharmacy-retail-pos';
        [$owner, $workspace] = $this->appWorkspace($app);
        $codeine = $this->record($workspace, $app, 'products', 'Codeine syrup', 'in_stock', ['schedule' => 's5', 'batch_number' => 'B7', 'expiry_date' => today()->addDays(30)->toDateString(), 'price' => 90, 'stock' => 8]);
        $panado = $this->record($workspace, $app, 'products', 'Panado', 'in_stock', ['schedule' => 'unscheduled', 'price' => 20, 'stock' => 3]);
        $this->assertSame('low_stock', $panado->status);
        $old = $this->record($workspace, $app, 'products', 'Old cough mix', 'in_stock', ['schedule' => 's2', 'expiry_date' => today()->subDay()->toDateString(), 'price' => 40, 'stock' => 10]);
        $sell = fn (Record $product, int $quantity, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'scheduled_sales']), ['title' => 'Ama', 'status' => 'dispensed', 'occurs_on' => today()->toDateString(), 'data' => ['product' => $product->id, 'quantity' => $quantity, 'pharmacist' => $owner->id, ...$data]]);

        $sell($panado, 1)->assertSessionHasErrors(['data.product' => 'Panado is not a scheduled medicine.']);
        $sell($old, 1)->assertSessionHasErrors(['data.product' => 'Old cough mix expired on '.today()->subDay()->format('d M Y').'.']);
        $sell($codeine, 9)->assertSessionHasErrors(['data.quantity' => 'Only 8 of Codeine syrup in stock.', 'data.id_number' => 'Record the patient\'s ID for a S5 medicine.', 'data.prescription_number' => 'A S5 medicine needs a prescription number.']);
        $sell($codeine, 2, ['id_number' => 'ID1', 'prescription_number' => 'RX1'])->assertSessionHasNoErrors();
        $this->assertEquals(6, $codeine->fresh()->value('stock'));

        $sale = Record::query()->where('entity', 'scheduled_sales')->first();
        $this->actingAs($owner)->post($sale->url().'/actions/return')->assertSessionHas('flash.message', '2 × Codeine syrup returned to stock.');
        $this->assertEquals(8, $codeine->fresh()->value('stock'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Expired or expiring within 60 days')->assertSee('Codeine syrup')->assertSee('expired '.today()->subDay()->format('d M Y'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Scheduled register by schedule')->assertSee('S5');
    }

    public function test_hardware_quotes_expire_and_accepted_ones_book_a_delivery(): void
    {
        $app = 'hardware-building-supplies-store';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'quotes']), ['title' => 'Bwalya house', 'status' => 'draft', 'amount' => 5000, 'data' => ['items' => '50 bags cement', 'delivery_needed' => '1']])
            ->assertSessionHasErrors(['data.site_address' => 'Give the site address for delivery.']);

        $quote = $this->record($workspace, $app, 'quotes', 'Bwalya house', 'draft', ['items' => '50 bags cement', 'delivery_needed' => true, 'site_address' => 'Plot 9'], ['amount' => 5000, 'occurs_on' => today(), 'due_on' => null]);
        $this->assertTrue($quote->due_on->isSameDay(today()->addDays(14)));
        $this->actingAs($owner)->post($quote->url().'/actions/send')->assertSessionHas('flash.message', 'Quote sent to Bwalya house; valid until '.today()->addDays(14)->format('d M Y').'.');
        $this->actingAs($owner)->post($quote->url().'/actions/accept', ['delivery_date' => today()->toDateString()])->assertSessionHas('flash.message', 'Bwalya house accepted the quote; delivery booked for '.today()->format('d M').'.');
        $delivery = Record::query()->where('entity', 'deliveries')->firstOrFail();
        $this->assertSame($quote->id, (int) $delivery->value('quote'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Deliveries due')->assertSee('Bwalya house');

        $this->actingAs($owner)->post($delivery->url().'/actions/load')->assertSessionHas('flash.message', 'Delivery to Bwalya house loaded.');
        $this->actingAs($owner)->post($delivery->url().'/actions/deliver', ['delivery_note' => ''])->assertSessionHasErrors(['delivery_note' => 'Give the signed delivery note number.']);
        $this->actingAs($owner)->post($delivery->url().'/actions/deliver', ['delivery_note' => 'DN55'])->assertSessionHas('flash.message', 'Delivered to Bwalya house on note DN55.');

        $stale = $this->record($workspace, $app, 'quotes', 'Old job', 'sent', ['items' => 'Sand'], ['amount' => 900, 'occurs_on' => today()->subDays(20), 'due_on' => today()->subDays(6)]);
        $this->assertSame('expired', $stale->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), ['title' => 'Old job', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'data' => ['quote' => $stale->id]])
            ->assertSessionHasErrors(['data.quote' => 'Quote Old job is expired, not accepted.']);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Quotes won by month')->assertSee($this->money(5000));
    }

    public function test_bottle_store_empties_pay_back_deposits(): void
    {
        $app = 'liquor-store-bottle-store';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'products']), ['title' => 'Castle', 'status' => 'in_stock', 'data' => ['type' => 'beer', 'price' => 20, 'case_price' => 15]])
            ->assertSessionHasErrors(['data.case_price' => 'A case must cost more than a single unit.']);
        $castle = $this->record($workspace, $app, 'products', 'Castle', 'in_stock', ['type' => 'beer', 'size' => '340ml', 'price' => 20, 'case_price' => 400, 'stock' => 10]);
        $this->assertSame('low_stock', $castle->status);
        $this->assertSame('in_stock', $this->record($workspace, $app, 'products', 'Merlot', 'out_of_stock', ['type' => 'wine', 'price' => 120, 'stock' => 40])->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'empties']), ['title' => 'Zed', 'status' => 'refunded', 'data' => ['crates' => 0, 'bottles' => 0]])
            ->assertSessionHasErrors(['data.crates' => 'Count at least one crate or bottle.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'empties']), ['title' => 'Zed', 'status' => 'refunded', 'occurs_on' => today()->toDateString(), 'data' => ['crates' => 2, 'bottles' => 5]])->assertSessionHasNoErrors();
        $this->assertEquals(65, (float) Record::query()->where('entity', 'empties')->value('amount'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reorder')->assertSee('Castle')->assertSee('Empties this month')->assertSee($this->money(65));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Empties by month')->assertSee('Stock by type')->assertSee($this->money(4800));
    }

    public function test_phones_are_tracked_by_imei_and_repaired_free_under_warranty(): void
    {
        $app = 'mobile-phones-electronics-imei';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'devices']), ['title' => 'Bad phone', 'status' => 'in_stock', 'data' => ['imei' => '490154203237519', 'price' => 100]])
            ->assertSessionHasErrors(['data.imei' => '490154203237519 is not a valid IMEI; check the number.']);
        $phone = $this->record($workspace, $app, 'devices', 'Galaxy A15', 'in_stock', ['imei' => '49-015420-323751-8', 'condition' => 'new', 'price' => 3000, 'warranty_months' => 12]);
        $this->assertSame('490154203237518', $phone->value('imei'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'devices']), ['title' => 'Copy', 'status' => 'in_stock', 'data' => ['imei' => '490154203237518', 'price' => 100]])
            ->assertSessionHasErrors(['data.imei' => '490154203237518 is already in stock as Galaxy A15.']);

        $this->actingAs($owner)->post($phone->url().'/actions/sell')->assertSessionHas('flash.message', 'Galaxy A15 sold; warranty until '.today()->addMonthsNoOverflow(12)->format('d M Y').'.');
        $free = $this->record($workspace, $app, 'repairs', 'Galaxy A15', 'booked_in', ['imei' => '490154203237518', 'fault' => 'Screen flicker'], ['occurs_on' => today(), 'due_on' => null, 'amount' => 500]);
        $this->assertTrue($free->value('_under_warranty'));
        $this->assertEquals(0, (float) $free->amount);
        $this->assertTrue($free->due_on->isSameDay(today()->addDays(3)));
        foreach (['Galaxy A15 is diagnosing.', 'Galaxy A15 is repairing.', 'Galaxy A15 is ready (free, under warranty).'] as $message) {
            $this->actingAs($owner)->post($free->url().'/actions/advance')->assertSessionHas('flash.message', $message);
        }

        $paid = $this->record($workspace, $app, 'repairs', 'iPhone 11', 'repairing', ['imei' => 'SN123', 'fault' => 'Battery'], ['occurs_on' => today()->subDays(5), 'due_on' => today()->subDays(2), 'amount' => 0]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Repairs past their promised date')->assertSee('iPhone 11');
        $this->actingAs($owner)->post($paid->url().'/actions/advance', ['amount' => ''])->assertSessionHasErrors(['amount' => 'Set the repair price.']);
        $this->actingAs($owner)->post($paid->url().'/actions/advance', ['amount' => 450])->assertSessionHas('flash.message', 'iPhone 11 is ready ('.$this->money(450).').');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Devices sold by condition')->assertSee('New')->assertSee($this->money(3000));
    }

    public function test_dealership_deals_reserve_and_sell_one_vehicle(): void
    {
        $app = 'vehicle-dealership';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vehicles']), ['title' => 'Model T', 'status' => 'in_stock', 'data' => ['year' => 1920, 'vin' => 'SHORT', 'price' => 100]])
            ->assertSessionHasErrors(['data.year' => 'Give a year between 1950 and '.(today()->year + 1).'.', 'data.vin' => 'A VIN has 17 characters.']);
        $hilux = $this->record($workspace, $app, 'vehicles', 'Toyota Hilux', 'in_stock', ['year' => 2021, 'vin' => 'AHTFR22G106012345', 'price' => 400000, 'cost_price' => 340000]);
        $this->assertEquals(60000, $hilux->value('_margin'));

        $deal = fn (string $name, string $status, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deals']), ['title' => $name, 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['vehicle' => $hilux->id, ...$data]]);
        $deal('Ama', 'offer', ['deposit' => 300000, 'trade_in_value' => 150000])->assertSessionHasErrors(['data.deposit' => 'The deposit and trade-in come to more than the deal.']);
        $deal('Ama', 'enquiry', ['deposit' => 50000, 'trade_in_value' => 100000])->assertSessionHasNoErrors();
        $ama = Record::query()->where('entity', 'deals')->where('title', 'Ama')->firstOrFail();
        $this->assertEquals(400000, (float) $ama->amount);
        $this->assertEquals(250000, $ama->value('_balance'));

        $this->actingAs($owner)->post($ama->url().'/actions/offer')->assertSessionHas('flash.message', 'Ama\'s deal moved to offer.');
        $this->assertSame('reserved', $hilux->fresh()->status);
        $deal('Bo', 'offer')->assertSessionHasErrors(['data.vehicle' => 'Toyota Hilux is reserved for Ama.']);
        $this->actingAs($owner)->post($ama->url().'/actions/finance')->assertSessionHasErrors(['status' => 'Say which bank is financing the deal.']);
        $this->actingAs($owner)->post($ama->url().'/actions/sold')->assertSessionHas('flash.message', 'Toyota Hilux sold to Ama for '.$this->money(400000).'; '.$this->money(250000).' to settle.');
        $this->assertSame('sold', $hilux->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Vehicles sold by month')->assertSee($this->money(60000));

        $polo = $this->record($workspace, $app, 'vehicles', 'VW Polo', 'in_stock', ['year' => 2019, 'price' => 150000]);
        $cy = $this->record($workspace, $app, 'deals', 'Cy', 'offer', ['vehicle' => $polo->id], ['occurs_on' => today()]);
        $this->assertSame('reserved', $polo->fresh()->status);
        $this->actingAs($owner)->post($cy->url().'/actions/lost')->assertSessionHas('flash.message', 'Cy\'s deal is lost; VW Polo is in stock.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Showroom')->assertSee('In stock over 90 days');
    }

    public function test_bookshop_checks_isbns_and_special_orders_collect_the_balance(): void
    {
        $app = 'bookshop-stationery';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'titles']), ['title' => 'Typo', 'status' => 'in_stock', 'data' => ['isbn' => '978-0-306-40615-8', 'price' => 100]])
            ->assertSessionHasErrors(['data.isbn' => '9780306406158 is not a valid ISBN; check the number.']);
        $book = $this->record($workspace, $app, 'titles', 'Things Fall Apart', 'in_stock', ['isbn' => '978-0-306-40615-7', 'author' => 'Achebe', 'price' => 150, 'stock' => 2]);
        $this->assertSame('9780306406157', $book->value('isbn'));
        $this->assertSame('low_stock', $book->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'titles']), ['title' => 'Copy', 'status' => 'in_stock', 'data' => ['isbn' => '9780306406157', 'price' => 100]])
            ->assertSessionHasErrors(['data.isbn' => 'Things Fall Apart already uses 9780306406157.']);
        $this->assertSame('on_order', $this->record($workspace, $app, 'titles', 'Atlas', 'on_order', ['price' => 300, 'stock' => 0])->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Mrs Banda', 'status' => 'ordered', 'amount' => 500, 'data' => ['items' => 'Grade 4 list', 'deposit' => 600]])
            ->assertSessionHasErrors(['data.deposit' => 'The deposit cannot be more than the total.']);
        $order = $this->record($workspace, $app, 'orders', 'Mrs Banda', 'ordered', ['items' => 'Grade 4 list', 'school' => 'Hillside Primary', 'deposit' => 200], ['amount' => 500, 'occurs_on' => today(), 'due_on' => null]);
        $this->assertTrue($order->due_on->isSameDay(today()->addDays(14)));
        $this->actingAs($owner)->post($order->url().'/actions/arrived')->assertSessionHas('flash.message', 'Order for Mrs Banda has arrived; '.$this->money(300).' to pay on collection.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting to be collected')->assertSee('Mrs Banda');
        $this->actingAs($owner)->post($order->url().'/actions/collected')->assertSessionHas('flash.message', 'Mrs Banda collected the order and paid '.$this->money(300).'.');

        $other = $this->record($workspace, $app, 'orders', 'Mr Phiri', 'ordered', ['items' => 'Bible', 'deposit' => 50], ['amount' => 120, 'occurs_on' => today()]);
        $this->actingAs($owner)->post($other->url().'/actions/cancel')->assertSessionHas('flash.message', 'Order for Mr Phiri cancelled; refund the '.$this->money(50).' deposit.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Special orders by school')->assertSee('Hillside Primary')->assertSee($this->money(500));
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
