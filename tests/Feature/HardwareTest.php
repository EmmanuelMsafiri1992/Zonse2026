<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Hardware\Barcodes;
use App\Support\Hardware\HardwareSettings;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Modules\Invoicing\Models\Item;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class HardwareTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function shop(array $hardware = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['pos'], $owner);
        $workspace = $workspace->fresh();
        app(WorkspaceContext::class)->set($workspace);
        HardwareSettings::save($workspace, $hardware);

        return [$owner, $workspace->fresh()];
    }

    protected function product(Workspace $workspace, array $attributes = []): Item
    {
        return Item::factory()->for($workspace)->create(['type' => 'product', 'name' => 'Bread', 'price' => 1.50, 'stock_qty' => 20, 'tax_rate_id' => null, ...$attributes]);
    }

    protected function sell(User $cashier, Item $item, string $method = 'cash', float $quantity = 2, array $extra = []): TestResponse
    {
        return $this->actingAs($cashier)->post(route('apps.pos.sell'), ['lines' => [['item_id' => $item->id, 'quantity' => $quantity]], 'payment_method' => $method, ...$extra]);
    }

    public function test_barcode_check_digits_and_ean13_bars(): void
    {
        $this->assertSame(1, Barcodes::checkDigit('400638133393'));
        $this->assertSame(2, Barcodes::checkDigit('03600029145'));
        $this->assertSame(4, Barcodes::checkDigit('9638507'));
        $this->assertTrue(Barcodes::isValidGtin('4006381333931'));
        $this->assertTrue(Barcodes::isValidGtin('036000291452'));
        $this->assertFalse(Barcodes::isValidGtin('4006381333932'));
        $this->assertFalse(Barcodes::isValidGtin('ABC-123'));

        $this->assertSame(
            '10100010110100111011001100100110111101001110101010110011011011001000010101110010011101000100101',
            Barcodes::ean13Modules('5901234123457'),
        );
        $this->assertSame(Barcodes::ean13Modules('0036000291452'), Barcodes::ean13Modules('036000291452'), 'UPC-A is drawn as EAN-13 with a leading zero');
        $this->assertStringContainsString('<svg', Barcodes::ean13Svg('5901234123457'));
        $this->assertNull(Barcodes::ean13Svg('96385074'));
        $this->assertNull(Barcodes::ean13Svg('SKU-1'));
    }

    public function test_items_take_valid_unique_barcodes_and_can_be_given_in_store_codes(): void
    {
        [$owner, $workspace] = $this->shop();
        $bread = $this->product($workspace);
        $milk = $this->product($workspace, ['name' => 'Milk']);
        $payload = fn (array $overrides) => ['type' => 'product', 'name' => 'Milk', 'price' => 2, ...$overrides];

        $this->actingAs($owner)->put(route('items.update', $milk), $payload(['barcode' => '4006381333932']))->assertSessionHasErrors('barcode');
        $this->actingAs($owner)->put(route('items.update', $milk), $payload(['barcode' => 'has space']))->assertSessionHasErrors('barcode');
        $this->actingAs($owner)->put(route('items.update', $milk), $payload(['barcode' => '4006381333931']))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('items.store'), $payload(['name' => 'Copy', 'barcode' => '4006381333931']))->assertSessionHasErrors('barcode');
        $this->actingAs($owner)->put(route('items.update', $milk), $payload(['barcode' => '4006381333931']))->assertSessionHasNoErrors();

        // Another workspace may use the same code, and so may a new item once the old one is deleted.
        $this->product(Workspace::factory()->create(), ['barcode' => '5901234123457']);
        $this->actingAs($owner)->post(route('items.store'), $payload(['name' => 'Jam', 'barcode' => '5901234123457']))->assertSessionHasNoErrors();
        $milk->delete();
        $this->actingAs($owner)->post(route('items.store'), $payload(['name' => 'New milk', 'barcode' => '4006381333931']))->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('items.barcode', $bread))->assertSessionHas('flash.type', 'success');
        $this->assertSame('2000000000015', $bread->fresh()->barcode);
        $this->assertTrue(Barcodes::isValidGtin($bread->fresh()->barcode));
        $cake = $this->product($workspace, ['name' => 'Cake']);
        $this->actingAs($owner)->post(route('items.barcode', $cake));
        $this->assertSame('2000000000022', $cake->fresh()->barcode);
        $this->actingAs($owner)->post(route('items.barcode', $cake))->assertSessionHas('flash.type', 'info');
        $this->assertSame('2000000000022', $cake->fresh()->barcode);

        $this->actingAs($owner)->get(route('items.index', ['q' => '2000000000015']))->assertOk()->assertViewHas('items', fn ($items) => $items->pluck('name')->all() === ['Bread']);
    }

    public function test_scanner_finds_items_by_barcode_sku_and_scale_label(): void
    {
        [$owner, $workspace] = $this->shop(['scale_prefixes' => ['21', '22'], 'scale_mode' => 'weight']);
        $bread = $this->product($workspace, ['barcode' => '5901234123457', 'sku' => 'BRD-1']);
        $mince = $this->product($workspace, ['name' => 'Mince', 'sku' => '42', 'price' => 8, 'unit' => 'kg', 'stock_qty' => null]);
        $this->product($workspace, ['name' => 'Old stock', 'barcode' => '4006381333931', 'is_active' => false]);
        $this->product(Workspace::factory()->create(), ['name' => 'Elsewhere', 'barcode' => '036000291452']);
        $scan = fn (string $code) => $this->actingAs($owner)->getJson(route('items.scan', ['code' => $code]));

        $scan('5901234123457')->assertOk()->assertJsonPath('item.id', $bread->id)->assertJsonPath('quantity', 1);
        $scan('brd-1')->assertOk()->assertJsonPath('item.name', 'Bread');

        // 21 | 00042 | 01250 | check: 1.250 kg of mince.
        $label = '21000420125'.'0';
        $label = substr($label, 0, 12).Barcodes::checkDigit(substr($label, 0, 12));
        $scan($label)->assertOk()->assertJsonPath('item.id', $mince->id)->assertJsonPath('quantity', 1.25);

        $scan(substr($label, 0, 12).((int) substr($label, -1) + 1) % 10)->assertNotFound();
        $other = '230004201250';
        $scan($other.Barcodes::checkDigit($other))->assertNotFound();
        $scan('4006381333931')->assertNotFound();
        $scan('036000291452')->assertNotFound();
        $scan('nothing')->assertNotFound()->assertJsonPath('message', 'No item has the code nothing.');

        // Price labels: 10.00 of mince at 8.00 a kilo is 1.25 kg.
        HardwareSettings::save($workspace, ['scale_mode' => 'price']);
        $priced = '220004201000';
        $scan($priced.Barcodes::checkDigit($priced))->assertOk()->assertJsonPath('quantity', 1.25);
    }

    public function test_labels_print_bars_for_ean_codes_and_qr_for_other_codes(): void
    {
        [$owner, $workspace] = $this->shop();
        $bread = $this->product($workspace, ['barcode' => '5901234123457']);
        $mince = $this->product($workspace, ['name' => 'Mince', 'sku' => 'MNC-9', 'barcode' => null]);
        $foreign = $this->product(Workspace::factory()->create(), ['name' => 'Elsewhere']);

        $this->actingAs($owner)->get(route('items.labels', ['items' => [$bread->id, $mince->id, $foreign->id], 'copies' => 2]))->assertOk()
            ->assertSee('aria-label="Barcode 5901234123457"', false)
            ->assertSee('MNC-9')
            ->assertDontSee('Elsewhere')
            ->assertViewHas('labels', fn ($labels) => $labels->count() === 4)
            ->assertViewHas('qrCodes', fn ($codes) => $codes[$bread->id] === null && str_contains($codes[$mince->id], '<svg'));

        $this->actingAs($owner)->get(route('items.labels'))->assertSessionHasErrors('items');
        $this->actingAs($owner)->get(route('items.index'))->assertOk()->assertSee('Print labels')->assertSee(route('items.barcode', $mince));
    }

    public function test_owner_sets_up_hardware_and_members_cannot(): void
    {
        [$owner, $workspace] = $this->shop();
        $member = $this->memberOf($workspace);

        $this->actingAs($owner)->get(route('settings.hardware.edit'))->assertOk()->assertSee('Receipt printer')->assertSee('Open the till');
        $this->actingAs($owner)->put(route('settings.hardware.update'), [
            'receipt_width' => 58, 'receipt_header' => "12 Main St\nVAT 1002003", 'open_drawer' => '0', 'receipt_qr' => '1',
            'scale_prefixes' => '22, 23,22', 'scale_mode' => 'price', 'card_terminal' => 'test',
        ])->assertSessionHasNoErrors()->assertSessionHas('flash.type', 'success');

        $this->assertSame([
            'receipt_width' => 58, 'receipt_header' => "12 Main St\nVAT 1002003", 'open_drawer' => false, 'receipt_qr' => true,
            'scale_prefixes' => ['22', '23'], 'scale_mode' => 'price', 'card_terminal' => 'test',
        ], HardwareSettings::for($workspace->fresh()));

        $this->actingAs($owner)->put(route('settings.hardware.update'), [
            'receipt_width' => 72, 'scale_prefixes' => '20, 30', 'scale_mode' => 'volume', 'card_terminal' => 'acme',
        ])->assertSessionHasErrors(['receipt_width', 'scale_prefixes', 'scale_mode', 'card_terminal']);

        $this->actingAs($member)->get(route('settings.hardware.edit'))->assertForbidden();
        $this->actingAs($member)->put(route('settings.hardware.update'), ['receipt_width' => 80, 'scale_mode' => 'weight', 'card_terminal' => 'off'])->assertForbidden();
    }

    public function test_test_card_terminal_approves_and_declines_card_sales(): void
    {
        [$owner, $workspace] = $this->shop(['card_terminal' => 'test']);
        $bread = $this->product($workspace);

        $this->actingAs($owner)->get(route('apps.pos.till'))->assertOk()->assertSee('The card terminal is charged');

        $this->sell($owner, $bread, 'card')->assertSessionHasNoErrors();
        $sale = Record::query()->ofEntity('pos', 'sales')->sole();
        $code = $sale->value('_card_approval');
        $this->assertMatchesRegularExpression('/^TEST-[A-Z0-9]{6}$/', $code);
        $payment = $sale->invoices()->sole()->payments()->sole();
        $this->assertSame('card', $payment->method);
        $this->assertSame($sale->number.' · '.$code, $payment->reference);
        $this->actingAs($owner)->get(route('apps.records.document', ['pos', 'sales', $sale->id, 'receipt']))->assertSee($code);

        // 1.50 + 0.01 = 1.51: the test terminal declines amounts ending in .51.
        $penny = $this->product($workspace, ['name' => 'Sweet', 'price' => 0.01]);
        $this->actingAs($owner)->post(route('apps.pos.sell'), [
            'lines' => [['item_id' => $bread->id, 'quantity' => 1], ['item_id' => $penny->id, 'quantity' => 1]], 'payment_method' => 'card',
        ])->assertSessionHasErrors('payment_method');
        $this->assertSame(1, Record::query()->ofEntity('pos', 'sales')->count());
        $this->assertSame(18.0, (float) $bread->fresh()->stock_qty);

        // Without a linked terminal, card sales are recorded as before.
        HardwareSettings::save($workspace, ['card_terminal' => 'off']);
        $this->actingAs($owner)->get(route('apps.pos.till'))->assertDontSee('The card terminal is charged');
        $this->sell($owner, $bread, 'card', 1)->assertSessionHasNoErrors();
        $this->assertNull(Record::query()->ofEntity('pos', 'sales')->latest('id')->first()->value('_card_approval'));
    }

    public function test_receipt_prints_as_a_slip_and_as_esc_pos_with_drawer_and_cut(): void
    {
        [$owner, $workspace] = $this->shop(['receipt_width' => 58, 'receipt_header' => 'VAT 1002003', 'open_drawer' => true, 'receipt_qr' => true]);
        $bread = $this->product($workspace, ['name' => 'Brown bread loaf']);

        $this->sell($owner, $bread, 'cash', 2, ['tendered' => 5])->assertSessionHas('lastSale.escpos');
        $sale = Record::query()->ofEntity('pos', 'sales')->sole();
        $invoice = $sale->invoices()->sole();

        $this->actingAs($owner)->get(route('apps.pos.receipt', $sale))->assertOk()
            ->assertSee($workspace->name)->assertSee('VAT 1002003')->assertSee('Brown bread loaf')->assertSee('Change')
            ->assertSee('size: 58mm auto', false)->assertSee('<svg', false);

        $response = $this->actingAs($owner)->get(route('apps.pos.receipt.escpos', $sale))->assertOk()
            ->assertHeader('Content-Type', 'application/octet-stream')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$sale->number.'.bin"');
        $bytes = $response->getContent();
        $this->assertStringStartsWith("\x1B@", $bytes);
        $this->assertStringContainsString('RECEIPT '.$sale->number, $bytes);
        $this->assertStringContainsString('Brown bread loaf', $bytes);
        $this->assertStringContainsString("\x1D(k", $bytes, 'QR code command');
        $this->assertStringContainsString($invoice->publicUrl(), $bytes);
        $this->assertStringContainsString("\x1DVB\x00", $bytes, 'Paper cut');
        $this->assertStringEndsWith("\x1Bp\x00\x19\xFA", $bytes, 'Cash drawer kick after a cash sale');
        $textLines = array_filter(explode("\n", preg_replace('/[\x00-\x09\x0B-\x1F]./s', '', $bytes)));
        $this->assertLessThanOrEqual(32, max(array_map('strlen', array_filter($textLines, fn ($line) => str_contains($line, '3.00')))));

        // Card sales never open the drawer; without the QR setting there is no QR.
        HardwareSettings::save($workspace, ['receipt_qr' => false]);
        $this->sell($owner, $bread, 'card', 1);
        $card = Record::query()->ofEntity('pos', 'sales')->latest('id')->first();
        $bytes = $this->actingAs($owner)->get(route('apps.pos.receipt.escpos', $card))->getContent();
        $this->assertStringNotContainsString("\x1Bp", $bytes);
        $this->assertStringNotContainsString("\x1D(k", $bytes);

        // Only this workspace's sales, and only sales.
        $till = Record::factory()->ofEntity('pos', 'tills', ['mode' => 'retail'])->create(['workspace_id' => $workspace->id, 'title' => 'Till 1', 'status' => 'active']);
        $this->actingAs($owner)->get(route('apps.pos.receipt', $till))->assertNotFound();
        [$stranger] = $this->shop();
        $this->actingAs($stranger)->get(route('apps.pos.receipt.escpos', $sale))->assertNotFound();
    }
}
