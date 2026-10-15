@extends('layouts.app')
@section('title', 'Platform payments')
@section('content')
    <x-page-header title="Platform payments" sub="Every payment made to Zonseob for plans and AI tokens, bank transfers waiting to be confirmed, and the exchange rates behind local prices." />

    @if($pendingTransfers)
        <div class="alert alert-warning d-flex gap-2 align-items-center">
            <x-icon name="landmark" class="zi" />
            <div class="fs-7">{{ $pendingTransfers }} bank {{ \Illuminate\Support\Str::plural('transfer', $pendingTransfers) }} waiting to be confirmed. Check your bank or mobile-money statement, then confirm each one below.</div>
            @if($status !== 'pending')<a href="{{ route('admin.payments.index', ['status' => 'pending']) }}" class="btn btn-sm btn-white ms-auto">Show pending</a>@endif
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header d-flex align-items-center flex-wrap gap-2">
                    <h5 class="card-title mb-0">Payments</h5>
                    <div class="btn-group ms-auto">
                        <a href="{{ route('admin.payments.index') }}" class="btn btn-sm {{ $status ? 'btn-white' : 'btn-primary' }}">All</a>
                        @foreach(\App\Models\Checkout::STATUSES as $key => $label)
                            <a href="{{ route('admin.payments.index', ['status' => $key]) }}" class="btn btn-sm {{ $status === $key ? 'btn-primary' : 'btn-white' }}">{{ $label }}</a>
                        @endforeach
                    </div>
                </div>
                <div class="z-table-wrap">
                    <table class="table z-table">
                        <thead><tr><th>Date</th><th>Who</th><th>For</th><th>Amount</th><th>Paid with</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse($checkouts as $checkout)
                            <tr>
                                <td class="fs-7">{{ $checkout->created_at->format('d M Y, H:i') }}</td>
                                <td>
                                    <div class="z-row-title">{{ $checkout->workspace?->name ?? $checkout->user?->name ?? '—' }}</div>
                                    <div class="fs-8 text-muted">{{ $checkout->user?->email }}</div>
                                </td>
                                <td class="fs-7">{{ $checkout->description() }}</td>
                                <td>{{ $checkout->baseMoney() }}@if($checkout->currency_code !== $checkout->base_currency) <span class="text-muted fs-8">({{ $checkout->money() }})</span>@endif</td>
                                <td class="fs-7">
                                    {{ $checkout->isManual() ? 'Bank transfer' : ucfirst($checkout->gateway) }}
                                    @if($checkout->note)<div class="fs-8 text-muted">{{ $checkout->note }}</div>@endif
                                    @if($checkout->gateway_reference)<div class="fs-8 text-muted">{{ $checkout->gateway_reference }}</div>@endif
                                </td>
                                <td>
                                    <x-pill :status="$checkout->status" />
                                    @if($checkout->confirmedBy)<div class="fs-8 text-muted">by {{ $checkout->confirmedBy->name }}</div>@endif
                                    @if($checkout->failure_reason)<div class="fs-8 text-muted">{{ $checkout->failure_reason }}</div>@endif
                                </td>
                                <td class="text-end">
                                    @if($checkout->isPending() && $checkout->isManual())
                                        <form method="POST" action="{{ route('admin.payments.confirm', $checkout) }}" class="d-flex gap-1 justify-content-end mb-1">
                                            @csrf
                                            <input type="text" name="reference" class="form-control form-control-sm" placeholder="Bank reference" style="max-width:160px">
                                            <button class="btn btn-sm btn-primary">Confirm</button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.payments.reject', $checkout) }}" onsubmit="return confirm('Mark this transfer as not received?')">
                                            @csrf
                                            <button class="btn btn-sm btn-soft-danger">Not received</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7"><x-empty icon="banknote" title="No payments yet" text="Payments for plans and AI tokens appear here as people make them." /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @if($checkouts->hasPages())<div class="card-footer">{{ $checkouts->links() }}</div>@endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title">Ways to pay</h5></div>
                <div class="card-body fs-7">
                    @forelse($gateways as $gateway)
                        <div class="d-flex gap-2 mb-2"><x-icon name="check" class="zi zi-sm text-success mt-1" /><div><strong>{{ $gateway->label() }}</strong><div class="text-muted">{{ $gateway->methods() }}</div></div></div>
                    @empty
                        <p class="text-muted mb-2">No online gateway is connected. Set PAYNOW_INTEGRATION_ID and PAYNOW_INTEGRATION_KEY, or STRIPE_SECRET_KEY, in the server's environment.</p>
                    @endforelse
                    <div class="d-flex gap-2"><x-icon name="{{ $manualInstructions !== '' ? 'check' : 'minus' }}" class="zi zi-sm {{ $manualInstructions !== '' ? 'text-success' : 'text-muted' }} mt-1" /><div><strong>Bank transfer</strong><div class="text-muted">{{ $manualInstructions !== '' ? 'Offered; you confirm each one here.' : 'Not offered until you write the instructions below.' }}</div></div></div>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.payments.settings') }}" class="card" x-data="{ rows: {{ Js::from(collect($rates)->map(fn ($rate, $code) => ['currency' => $code, 'rate' => $rate])->values()->all() ?: [['currency' => '', 'rate' => '']]) }} }">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Local prices</h5></div>
                <div class="card-body">
                    <p class="fs-7 text-muted">How many units of each currency one US dollar buys. Plans are priced in dollars and shown in these currencies; Paynow charges in its own currency at this rate.</p>
                    <template x-for="(row, i) in rows" :key="i">
                        <div class="d-flex gap-2 mb-2">
                            <select class="form-select form-select-sm" :name="'rates['+i+'][currency]'" x-model="row.currency">
                                <option value="">Currency</option>
                                @foreach($currencies as $code => $name)
                                    @if($code !== 'USD')<option value="{{ $code }}">{{ $code }} · {{ $name }}</option>@endif
                                @endforeach
                            </select>
                            <input type="number" step="0.0001" min="0" class="form-control form-control-sm" :name="'rates['+i+'][rate]'" x-model="row.rate" placeholder="per US$1">
                            <button type="button" class="btn btn-sm btn-white" x-on:click="rows.splice(i, 1)" aria-label="Remove"><x-icon name="x" class="zi zi-sm" /></button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-sm btn-white mb-3" x-on:click="rows.push({ currency: '', rate: '' })"><x-icon name="plus" class="zi zi-sm" /> Add currency</button>

                    <x-form.textarea name="manual_instructions" label="Bank transfer instructions" :value="$manualInstructions" rows="5" help="Shown to people choosing to pay by transfer or mobile money: bank, account number, EcoCash merchant number, what to put as the reference. Leave blank to switch transfers off." />
                </div>
                <div class="card-footer text-end"><button class="btn btn-primary">Save</button></div>
            </form>
        </div>
    </div>
@endsection
