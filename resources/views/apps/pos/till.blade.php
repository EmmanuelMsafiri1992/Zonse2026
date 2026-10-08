@extends('layouts.app')
@section('title', 'Till')
@section('content')
    @php
        $config = [
            'items' => $items,
            'symbol' => \App\Support\Money::symbol($workspace->currency_code),
            'lines' => array_values(old('lines', [])),
        ];
    @endphp

    <x-page-header title="Till" :sub="$app->name"
                   :crumbs="['Apps' => route('apps.index'), $app->name => route('apps.show', 'pos'), 'Till']">
        <a href="{{ route('apps.records.index', ['pos', 'sales']) }}" class="btn btn-white"><x-icon name="receipt" /> Sales</a>
        <a href="{{ route('apps.records.index', ['pos', 'shifts']) }}" class="btn btn-white"><x-icon name="clock" /> Shifts</a>
    </x-page-header>

    @if(session('lastSale'))
        <div class="alert alert-success d-flex flex-wrap align-items-center gap-3">
            <span class="fs-5 fw-semibold">Change: {{ \App\Support\Money::format(session('lastSale')['change'], $workspace->currency_code) }}</span>
            <span class="text-muted">Sale {{ session('lastSale')['number'] }}</span>
            <a href="{{ session('lastSale')['receipt'] }}" target="_blank" class="btn btn-sm btn-white ms-auto"><x-icon name="printer" /> Print receipt</a>
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger fs-7"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('apps.pos.sell') }}" x-data="posTill({{ \Illuminate\Support\Js::from($config) }})" @submit="submitting = true">
        @csrf
        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card h-100">
                    <div class="card-header">
                        <input type="search" class="form-control" placeholder="Search items or scan a SKU…" x-model="search"
                               @keydown.enter.prevent="addFirstMatch()" autofocus aria-label="Search items">
                    </div>
                    <div class="card-body">
                        @if($items->isEmpty())
                            <x-empty icon="package" title="No items to sell yet" text="Add products and services in Invoicing first." />
                            <div class="text-center"><a href="{{ route('items.index') }}" class="btn btn-primary btn-sm">Add an item</a></div>
                        @endif
                        <div class="row g-2">
                            <template x-for="item in matches()" :key="item.id">
                                <div class="col-6 col-md-4">
                                    <button type="button" class="btn btn-white w-100 h-100 text-start p-2" @click="add(item)" :disabled="item.stock !== null && item.stock <= qtyInCart(item.id)">
                                        <div class="fw-semibold text-truncate" x-text="item.name"></div>
                                        <div class="d-flex justify-content-between fs-8 text-muted">
                                            <span x-text="money(item.price)"></span>
                                            <span x-show="item.stock !== null" x-text="fmt(item.stock) + ' left'"></span>
                                        </div>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h5 class="card-title mb-0">Cart</h5>
                        <button type="button" class="btn btn-sm btn-soft-danger" x-show="lines.length" @click="lines = []">Clear</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 fs-7 align-middle">
                            <tbody>
                                <template x-for="(line, index) in lines" :key="line.item_id">
                                    <tr>
                                        <td>
                                            <div x-text="item(line.item_id)?.name"></div>
                                            <div class="fs-8 text-muted" x-text="money(item(line.item_id)?.price)"></div>
                                            <input type="hidden" :name="`lines[${index}][item_id]`" :value="line.item_id">
                                        </td>
                                        <td style="width: 6.5rem">
                                            <input type="number" class="form-control form-control-sm" min="0.001" step="any" :name="`lines[${index}][quantity]`" x-model.number="line.quantity" aria-label="Quantity">
                                        </td>
                                        <td class="text-end" x-text="money(lineTotal(line))"></td>
                                        <td class="text-end"><button type="button" class="btn btn-sm btn-icon btn-white" @click="lines.splice(index, 1)" title="Remove"><x-icon name="x" /></button></td>
                                    </tr>
                                </template>
                                <tr x-show="!lines.length"><td class="text-muted text-center py-4">Tap an item to add it.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="card-body border-top">
                        <dl class="row mb-2 fs-7">
                            <dt class="col-6 fw-normal text-muted">Subtotal</dt><dd class="col-6 text-end mb-1" x-text="money(subtotal())"></dd>
                            <dt class="col-6 fw-normal text-muted">Tax</dt><dd class="col-6 text-end mb-1" x-text="money(total() - subtotal())"></dd>
                            <dt class="col-6 fs-5">Total</dt><dd class="col-6 text-end fs-5 fw-semibold mb-0" x-text="money(total())"></dd>
                        </dl>

                        @if($tills->isNotEmpty())
                            <x-form.select name="till" label="Till" :options="$tills->all()" :value="request('till', $tills->keys()->first())" />
                        @endif
                        <x-form.select name="contact_id" label="Customer" :options="$contacts->all()" placeholder="Walk-in customer" />

                        <label class="form-label">Payment</label>
                        <div class="d-flex flex-wrap gap-1 mb-3">
                            @foreach(['cash' => 'Cash', 'card' => 'Card', 'mobile_money' => 'Mobile money', 'account' => 'On account', 'split' => 'Split'] as $methodKey => $methodLabel)
                                <input type="radio" class="btn-check" name="payment_method" id="method-{{ $methodKey }}" value="{{ $methodKey }}" x-model="method" @checked(old('payment_method', 'cash') === $methodKey)>
                                <label class="btn btn-sm btn-outline-primary" for="method-{{ $methodKey }}">{{ $methodLabel }}</label>
                            @endforeach
                        </div>

                        <div x-show="method === 'cash'" class="mb-3">
                            <label class="form-label" for="tendered">Cash tendered</label>
                            <input type="number" step="0.01" min="0" id="tendered" name="tendered" class="form-control" x-model.number="tendered" :disabled="method !== 'cash'">
                            <div class="fs-7 mt-1" x-show="tendered" :class="tendered >= total() ? 'text-success' : 'text-danger'"
                                 x-text="tendered >= total() ? 'Change: ' + money(tendered - total()) : 'Short by ' + money(total() - tendered)"></div>
                        </div>

                        <button class="btn btn-success btn-lg w-100" :disabled="!lines.length || submitting || (method === 'cash' && tendered && tendered < total())">
                            <x-icon name="check" /> Charge <span x-text="money(total())"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection

@push('scripts')
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('posTill', (config) => ({
                items: config.items,
                lines: config.lines.map((line) => ({ item_id: Number(line.item_id), quantity: Number(line.quantity) })),
                search: '',
                method: 'cash',
                tendered: null,
                submitting: false,
                item(id) { return this.items.find((item) => item.id === Number(id)); },
                matches() {
                    const term = this.search.trim().toLowerCase();
                    return term === '' ? this.items : this.items.filter((item) => item.name.toLowerCase().includes(term) || (item.sku || '').toLowerCase() === term);
                },
                qtyInCart(id) { return this.lines.filter((line) => line.item_id === id).reduce((sum, line) => sum + Number(line.quantity || 0), 0); },
                add(item) {
                    const line = this.lines.find((line) => line.item_id === item.id);
                    line ? line.quantity = Number(line.quantity || 0) + 1 : this.lines.push({ item_id: item.id, quantity: 1 });
                },
                addFirstMatch() {
                    const first = this.matches()[0];
                    if (first) { this.add(first); this.search = ''; }
                },
                round(value) { return Math.round((value + Number.EPSILON) * 100) / 100; },
                lineNet(line) { return this.round(Number(line.quantity || 0) * (this.item(line.item_id)?.price || 0)); },
                lineTotal(line) { const net = this.lineNet(line); return this.round(net + net * (this.item(line.item_id)?.tax_rate || 0) / 100); },
                subtotal() { return this.round(this.lines.reduce((sum, line) => sum + this.lineNet(line), 0)); },
                total() { return this.round(this.lines.reduce((sum, line) => sum + this.lineTotal(line), 0)); },
                fmt(value) { return Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 }); },
                money(value) { return config.symbol + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            }));
        });
    </script>
@endpush
