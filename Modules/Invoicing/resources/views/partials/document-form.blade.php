@php
    $isInvoice = $kind === 'invoice';
    $indexRoute = $isInvoice ? 'invoices.index' : 'quotes.index';
    $showRoute = $isInvoice ? 'invoices.show' : 'quotes.show';
    $action = $document->exists ? route($isInvoice ? 'invoices.update' : 'quotes.update', $document) : route($isInvoice ? 'invoices.store' : 'quotes.store');
    $label = $isInvoice ? 'Invoice' : 'Quote';
    $existingLines = $document->exists
        ? $document->lines->map(fn ($l) => ['item_id' => $l->item_id, 'description' => $l->description, 'quantity' => (float) $l->quantity, 'unit' => $l->unit, 'unit_price' => (float) $l->unit_price, 'tax_rate' => (float) $l->tax_rate])->values()->all()
        : [];
    $config = [
        'lines' => array_values(old('lines', $existingLines)),
        'items' => $items,
        'taxRates' => $taxRates,
        'defaultTax' => (float) $defaultTaxRate,
        'currency' => old('currency_code', $document->currency_code ?? $workspace->currency_code),
        'symbols' => \App\Support\Money::SYMBOLS,
        'discountType' => old('discount_type', $document->discount_type),
        'discountValue' => (float) old('discount_value', $document->discount_value ?? 0),
        'contacts' => $contacts->map(fn ($c) => ['id' => $c->id, 'currency' => $c->currency_code])->values(),
    ];
@endphp

<form method="POST" action="{{ $action }}" x-data="documentForm({{ \Illuminate\Support\Js::from($config) }})" @submit="beforeSubmit">
    @csrf
    @if($document->exists) @method('PUT') @endif

    @if($errors->any())
        <div class="alert alert-danger fs-7"><strong>Please fix the following:</strong>
            <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">{{ $label }} details</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <x-form.select name="contact_id" label="Customer" :value="$document->contact_id" placeholder="Choose a customer…" required
                                           :options="$contacts->mapWithKeys(fn ($c) => [$c->id => $c->kind === 'company' && $c->company_name ? $c->company_name.' ('.$c->name.')' : $c->name])->all()"
                                           x-model="contactId" @change="contactChanged" />
                            @if(Route::has('contacts.create'))
                                <div class="form-text mt-n2 mb-3">Not here yet? <a href="{{ route('contacts.create', ['type' => 'customer']) }}" target="_blank">Add a contact</a>.</div>
                            @endif
                        </div>
                        <div class="col-md-3"><x-form.select name="currency_code" label="Currency" :options="$currencies" :value="$document->currency_code ?? $workspace->currency_code" required x-model="currency" /></div>
                        <div class="col-md-3"><x-form.select name="branch_id" label="Branch" :options="$branches" :value="$document->branch_id" placeholder="—" /></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4"><x-form.input name="issue_date" type="date" label="Issue date" :value="optional($document->issue_date)->format('Y-m-d')" required /></div>
                        @if($isInvoice)
                            <div class="col-md-4"><x-form.input name="due_date" type="date" label="Due date" :value="optional($document->due_date)->format('Y-m-d')" required /></div>
                        @else
                            <div class="col-md-4"><x-form.input name="valid_until" type="date" label="Valid until" :value="optional($document->valid_until)->format('Y-m-d')" /></div>
                        @endif
                        <div class="col-md-4"><x-form.input name="reference" label="Reference / PO number" :value="$document->reference" /></div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">Lines</h5>
                    <span class="fs-8 text-muted">Pick from your products & services or type freely.</span>
                </div>
                <div class="z-table-wrap">
                    <table class="table z-table align-top z-line-editor">
                        <thead>
                        <tr>
                            <th style="min-width:280px">Description</th>
                            <th style="width:110px">Qty</th>
                            <th style="width:140px">Unit price</th>
                            <th style="width:110px">Tax %</th>
                            <th class="text-end" style="width:130px">Amount</th>
                            <th style="width:44px"></th>
                        </tr>
                        </thead>
                        <tbody>
                        <template x-for="(line, i) in lines" :key="line.key">
                            <tr>
                                <td>
                                    <input type="hidden" :name="'lines['+i+'][item_id]'" :value="line.item_id || ''">
                                    <input type="hidden" :name="'lines['+i+'][unit]'" :value="line.unit || ''">
                                    <select class="form-select form-select-sm mb-1" x-show="items.length" x-model="line.item_id" @change="pickItem(line)">
                                        <option value="">Pick from catalogue…</option>
                                        <template x-for="it in items" :key="it.id"><option :value="it.id" x-text="it.name + ' — ' + fmt(it.price)"></option></template>
                                    </select>
                                    <input type="text" class="form-control form-control-sm" :name="'lines['+i+'][description]'" x-model="line.description" placeholder="What are you charging for?" required>
                                </td>
                                <td><input type="number" step="0.001" min="0.001" class="form-control form-control-sm text-end" :name="'lines['+i+'][quantity]'" x-model.number="line.quantity" required></td>
                                <td><input type="number" step="0.01" min="0" class="form-control form-control-sm text-end" :name="'lines['+i+'][unit_price]'" x-model.number="line.unit_price" required></td>
                                <td>
                                    <select class="form-select form-select-sm" :name="'lines['+i+'][tax_rate]'" x-model.number="line.tax_rate">
                                        <option value="0">None</option>
                                        <template x-for="t in taxRates" :key="t.id"><option :value="Number(t.rate)" x-text="t.name + ' ' + Number(t.rate) + '%'"></option></template>
                                        <template x-if="customTax(line)"><option :value="line.tax_rate" x-text="line.tax_rate + '%'"></option></template>
                                    </select>
                                </td>
                                <td class="text-end fw-600 pt-3" x-text="fmt(lineTotal(line))"></td>
                                <td class="pt-2"><button type="button" class="btn btn-sm btn-icon btn-soft-danger" @click="removeLine(i)" title="Remove line" :disabled="lines.length === 1"><x-icon name="x" class="zi zi-sm" /></button></td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <button type="button" class="btn btn-soft-primary btn-sm" @click="addLine()"><x-icon name="plus" class="zi zi-sm" /> Add line</button>
                    <div style="min-width:280px">
                        <div class="d-flex justify-content-between fs-7 py-1"><span class="text-muted">Subtotal</span><span x-text="fmt(subtotal)"></span></div>
                        <div class="d-flex justify-content-between align-items-center fs-7 py-1 gap-2">
                            <span class="text-muted">Discount</span>
                            <span class="d-flex gap-1">
                                <select name="discount_type" class="form-select form-select-sm w-auto" x-model="discountType">
                                    <option value="">None</option><option value="percent">%</option><option value="fixed">Amount</option>
                                </select>
                                <input type="number" step="0.01" min="0" name="discount_value" class="form-control form-control-sm text-end" style="width:90px" x-model.number="discountValue" x-show="discountType">
                            </span>
                            <span x-text="'- ' + fmt(discount)"></span>
                        </div>
                        <div class="d-flex justify-content-between fs-7 py-1"><span class="text-muted">Tax</span><span x-text="fmt(tax)"></span></div>
                        <div class="d-flex justify-content-between fw-600 fs-6 pt-2 border-top"><span>Total</span><span x-text="fmt(total)"></span></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Notes & terms</h5></div>
                <div class="card-body">
                    <x-form.textarea name="notes" label="Notes to the customer" :value="$document->notes" rows="3" help="Shown on the {{ strtolower($label) }}." />
                    <x-form.textarea name="terms" label="Terms & conditions" :value="$document->terms" rows="4" />
                </div>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-primary flex-grow-1"><x-icon name="check" /> {{ $document->exists ? 'Save changes' : 'Save '.strtolower($label) }}</button>
                <a href="{{ $document->exists ? route($showRoute, $document) : route($indexRoute) }}" class="btn btn-white">Cancel</a>
            </div>
            <p class="fs-8 text-muted mt-2">Saving keeps it as a draft. Mark it as sent from the {{ strtolower($label) }} page when you share it.</p>
        </div>
    </div>
</form>

@push('scripts')
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('documentForm', (cfg) => ({
        lines: [],
        items: cfg.items || [],
        taxRates: cfg.taxRates || [],
        defaultTax: cfg.defaultTax || 0,
        currency: cfg.currency || 'USD',
        symbols: cfg.symbols || {},
        discountType: cfg.discountType || '',
        discountValue: cfg.discountValue || 0,
        contactId: '',
        contacts: cfg.contacts || [],
        seq: 0,
        init() {
            const given = (cfg.lines || []).map((l) => this.normalise(l));
            this.lines = given.length ? given : [this.blank()];
        },
        normalise(l) {
            return {
                key: ++this.seq,
                item_id: l.item_id ? String(l.item_id) : '',
                description: l.description || '',
                quantity: Number(l.quantity ?? 1),
                unit: l.unit || '',
                unit_price: Number(l.unit_price ?? 0),
                tax_rate: Number(l.tax_rate ?? 0),
            };
        },
        blank() { return this.normalise({ quantity: 1, unit_price: 0, tax_rate: this.defaultTax }); },
        addLine() { this.lines.push(this.blank()); },
        removeLine(i) { if (this.lines.length > 1) this.lines.splice(i, 1); },
        pickItem(line) {
            const it = this.items.find((x) => String(x.id) === String(line.item_id));
            if (!it) return;
            line.description = it.description ? it.name + ' — ' + it.description : it.name;
            line.unit_price = Number(it.price);
            line.unit = it.unit || '';
            line.tax_rate = Number(it.tax_rate || 0);
        },
        customTax(line) { return line.tax_rate && !this.taxRates.some((t) => Number(t.rate) === Number(line.tax_rate)); },
        contactChanged() {
            const c = this.contacts.find((x) => String(x.id) === String(this.contactId));
            if (c && c.currency) this.currency = c.currency;
        },
        lineTotal(l) { return this.round((Number(l.quantity) || 0) * (Number(l.unit_price) || 0)); },
        round(n) { return Math.round((Number(n) || 0) * 100) / 100; },
        get subtotal() { return this.round(this.lines.reduce((s, l) => s + this.lineTotal(l), 0)); },
        get discount() {
            const v = Number(this.discountValue) || 0;
            if (this.discountType === 'percent') return this.round(this.subtotal * Math.min(100, Math.max(0, v)) / 100);
            if (this.discountType === 'fixed') return this.round(Math.min(this.subtotal, Math.max(0, v)));
            return 0;
        },
        get tax() {
            const ratio = this.subtotal > 0 ? (this.subtotal - this.discount) / this.subtotal : 1;
            return this.round(this.lines.reduce((s, l) => s + this.lineTotal(l) * (Number(l.tax_rate) || 0) / 100, 0) * ratio);
        },
        get total() { return this.round(this.subtotal - this.discount + this.tax); },
        fmt(n) {
            const sym = this.symbols[this.currency] ?? (this.currency + ' ');
            return sym + (Number(n) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        beforeSubmit(e) {
            if (!this.lines.some((l) => (l.description || '').trim() !== '')) { e.preventDefault(); window.zonseo.toast('Add at least one line.', 'danger'); }
        },
    }));
});
</script>
@endpush
