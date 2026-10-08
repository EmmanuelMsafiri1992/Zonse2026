<?php

namespace App\Support\Import;

use App\Support\Hardware\Barcodes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\TaxRate;

/** The price list: products and services, including item lists exported from QuickBooks, Sage and Xero. */
class ItemTarget extends ImportTarget
{
    protected const TYPE_WORDS = [
        'product' => ['product', 'products', 'inventory', 'inventoryitem', 'noninventory', 'stock', 'stockitem', 'goods', 'item'],
        'service' => ['service', 'services', 'labour', 'labor', 'nonstock', 'nonstockitem'],
    ];

    /** @var array<int, TaxRate>|null */
    protected ?array $taxRates = null;

    public function key(): string
    {
        return 'items';
    }

    public function label(): string
    {
        return 'Products & services';
    }

    public function icon(): string
    {
        return 'package';
    }

    public function listUrl(): ?string
    {
        return route('items.index');
    }

    public function modelClass(): string
    {
        return Item::class;
    }

    public function columns(): array
    {
        return [
            'name' => ['label' => 'Name', 'required' => true, 'example' => 'Cement 50kg', 'aliases' => ['itemname', 'productservicename', 'productname', 'product', 'service', 'item', 'productservice', 'title']],
            'price' => ['label' => 'Price', 'required' => true, 'example' => '12.50', 'hint' => 'The selling price before tax.', 'aliases' => ['salesprice', 'salespricerate', 'rate', 'sellingprice', 'salesunitprice', 'unitprice', 'retailprice', 'priceexcltax']],
            'sku' => ['label' => 'SKU / code', 'example' => 'CEM-50', 'aliases' => ['sku', 'itemcode', 'code', 'productcode', 'stockcode', 'reference', 'partnumber']],
            'barcode' => ['label' => 'Barcode', 'example' => '', 'aliases' => ['ean', 'upc', 'gtin', 'ean13']],
            'type' => ['label' => 'Type', 'example' => 'Product', 'hint' => 'Product or service.', 'aliases' => ['itemtype', 'producttype', 'kind']],
            'description' => ['label' => 'Description', 'example' => 'Portland cement, 50 kg bag', 'aliases' => ['salesdescription', 'details', 'longdescription']],
            'unit' => ['label' => 'Unit', 'example' => 'bag', 'aliases' => ['uom', 'unitofmeasure', 'unitofsale']],
            'cost' => ['label' => 'Cost', 'example' => '9.80', 'aliases' => ['costprice', 'purchasecost', 'purchaseprice', 'purchasesunitprice', 'buyingprice', 'unitcost']],
            'stock_qty' => ['label' => 'Quantity in stock', 'example' => '40', 'aliases' => ['stock', 'stockqty', 'quantity', 'quantityonhand', 'qtyonhand', 'quantityinstock', 'onhand', 'instock']],
            'reorder_level' => ['label' => 'Reorder level', 'example' => '10', 'aliases' => ['reorderpoint', 'minimumstock', 'minstock', 'reorderlevel']],
            'tax_rate' => ['label' => 'Tax rate', 'example' => '15%', 'hint' => 'The name or percentage of one of your tax rates.', 'aliases' => ['tax', 'vat', 'vatrate', 'taxcode', 'salestaxcode', 'taxratename']],
            'active' => ['label' => 'Active', 'example' => 'yes', 'aliases' => ['isactive', 'status', 'enabled']],
        ];
    }

    public function prepare(array $raw, array $options): array
    {
        $errors = [];
        $warnings = [];
        $text = fn (string $key) => array_key_exists($key, $raw) ? (Values::text($raw[$key]) ?: null) : null;
        $number = function (string $key, string $label) use ($text, &$errors): ?float {
            $value = $text($key);
            if ($value === null) {
                return null;
            }
            $number = Values::number($value);
            if ($number === null) {
                $errors[] = $label.' "'.$value.'" is not a number.';
            }

            return $number;
        };

        $name = $text('name');
        if ($name === null) {
            $errors[] = 'Needs a name.';
        }
        $price = $number('price', 'Price');
        if ($text('price') === null) {
            $errors[] = 'Needs a price.';
        }

        $type = null;
        if (($typeText = $text('type')) !== null) {
            $type = collect(self::TYPE_WORDS)->search(fn (array $words) => in_array(Values::key($typeText), $words, true)) ?: null;
            if (! $type) {
                $warnings[] = 'Type "'.$typeText.'" is not product or service, so it is added as a product.';
            }
        }

        $taxRateId = null;
        if (($taxText = $text('tax_rate')) !== null && ! in_array(Values::key($taxText), ['', 'none', 'exempt', 'zero', 'notax', 'nontaxable'], true)) {
            $taxRateId = $this->taxRate($taxText)?->id;
            if (! $taxRateId) {
                $warnings[] = 'Tax rate "'.$taxText.'" does not match any of your tax rates, so no tax is set.';
            }
        }
        $active = null;
        if (($activeText = $text('active')) !== null && ($active = Values::boolean($activeText)) === null) {
            $warnings[] = 'Active "'.$activeText.'" is not yes or no, so the item stays active.';
        }

        $values = [
            'name' => $name,
            'type' => $type,
            'sku' => $text('sku'),
            'barcode' => $text('barcode'),
            'description' => $text('description'),
            'unit' => $text('unit'),
            'price' => $price !== null ? round($price, 2) : null,
            'cost' => ($cost = $number('cost', 'Cost')) !== null ? round($cost, 2) : null,
            'stock_qty' => $number('stock_qty', 'Quantity in stock'),
            'reorder_level' => $number('reorder_level', 'Reorder level'),
            'tax_rate_id' => $taxRateId,
            'is_active' => $active,
        ];

        $errors = [...$errors, ...$this->validate($values, [
            'name' => ['nullable', 'string', 'max:160'], 'sku' => ['nullable', 'string', 'max:60'], 'description' => ['nullable', 'string', 'max:2000'],
            'unit' => ['nullable', 'string', 'max:20'], 'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'], 'cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'stock_qty' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'], 'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[\x21-\x7E]+$/'],
        ])];
        if ($values['barcode'] && ctype_digit($values['barcode']) && in_array(strlen($values['barcode']), [8, 12, 13, 14], true) && ! Barcodes::isValidGtin($values['barcode'])) {
            $errors[] = 'The last digit of barcode '.$values['barcode'].' does not match.';
        }

        return ['values' => $values, 'errors' => $errors, 'warnings' => $warnings];
    }

    public function check(array $values, ?Model $existing): array
    {
        if ($values['barcode'] && Item::query()->where('barcode', $values['barcode'])->when($existing, fn ($query) => $query->whereKeyNot($existing->getKey()))->exists()) {
            return ['Another item already has barcode '.$values['barcode'].'.'];
        }

        return [];
    }

    public function matchKey(array $values): ?string
    {
        return $values['sku'] ? 'sku:'.mb_strtolower($values['sku']) : 'name:'.Values::key((string) $values['name']);
    }

    public function findExisting(array $values): ?Model
    {
        if ($values['sku']) {
            return Item::query()->whereRaw('lower(sku) = ?', [mb_strtolower($values['sku'])])->first();
        }

        return Item::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $values['name'])])->first();
    }

    public function create(array $values, array $options): Model
    {
        return Item::create(array_merge(['type' => 'product', 'is_active' => true, 'stock_qty' => 0], $this->given($values)));
    }

    public function update(Model $model, array $values): void
    {
        $model->update($this->given($values));
    }

    public function describe(Model $model): string
    {
        return Str::limit($model->name, 60);
    }

    /** A tax rate by name ("VAT") or by percentage ("15", "15%", "VAT 15%"). */
    protected function taxRate(string $value): ?TaxRate
    {
        $this->taxRates ??= TaxRate::query()->get()->all();
        $wanted = Values::key($value);
        $percent = preg_match('/(\d+(?:[.,]\d+)?)\s*%?/', $value, $match) ? (float) str_replace(',', '.', $match[1]) : null;

        return collect($this->taxRates)->first(fn (TaxRate $rate) => Values::key($rate->name) === $wanted)
            ?? ($percent !== null ? collect($this->taxRates)->first(fn (TaxRate $rate) => abs((float) $rate->rate - $percent) < 0.001) : null);
    }
}
