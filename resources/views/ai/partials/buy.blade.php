{{-- "Buy tokens" modal for Zonseob AI. Expects $packs, $gateways, $manualInstructions, $paynowCurrency, $purchases. --}}
@php $firstPack = array_key_first($packs); @endphp
<div class="modal fade" id="ai-buy" tabindex="-1" aria-labelledby="ai-buy-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="ai-buy-title">Buy tokens</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                @if($packs && ($gateways || $manualInstructions !== ''))
                    <form method="POST" action="{{ route('ai.tokens') }}" x-data="{ pack: {{ Js::from($firstPack) }}, packs: {{ Js::from($packs) }}, transfer: false, get p() { return this.packs[this.pack]; } }">
                        @csrf
                        <input type="hidden" name="pack" :value="pack">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <div class="form-label">Pack</div>
                                <div class="d-grid gap-2 mb-3">
                                    @foreach($packs as $key => $pack)
                                        <label class="border rounded-3 p-3 d-flex align-items-center gap-3" :class="pack === {{ Js::from($key) }} ? 'border-primary' : ''" style="cursor:pointer">
                                            <input type="radio" class="form-check-input mt-0" value="{{ $key }}" x-model="pack">
                                            <span class="flex-grow-1">
                                                <span class="fw-600">{{ $pack['name'] }}</span>
                                                <span class="d-block fs-8 text-muted">{{ number_format($pack['tokens']) }} tokens</span>
                                            </span>
                                            <span class="fw-600">{{ $pack['usd_label'] }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div class="fs-8 text-muted">Prices are in US dollars. Where we show a local amount, that is what you will be charged at today's rate. Tokens never expire.</div>
                            </div>
                            <div class="col-md-6">
                                <div class="border rounded-3 p-3 mb-3">
                                    <div class="d-flex justify-content-between fw-600"><span x-text="p.name + ' pack'"></span><span x-text="p.usd_label"></span></div>
                                    <div class="fs-7 text-muted" x-text="Number(p.tokens).toLocaleString() + ' tokens'"></div>
                                    <template x-if="Object.keys(p.local).length">
                                        <div class="mt-2 fs-7 text-muted">
                                            <div>About the same as</div>
                                            <template x-for="[code, amount] in Object.entries(p.local)" :key="code">
                                                <div class="d-flex justify-content-between"><span x-text="code"></span><span x-text="amount"></span></div>
                                            </template>
                                        </div>
                                    </template>
                                </div>

                                <div class="d-grid gap-2">
                                    @foreach($gateways as $gateway)
                                        <div>
                                            <button type="submit" name="gateway" value="{{ $gateway->key() }}" class="btn btn-primary w-100">
                                                <x-icon name="credit-card" /> Pay with {{ $gateway->label() }}
                                                @if($gateway->key() === 'paynow' && $paynowCurrency !== 'USD')<span class="fw-normal">(charged in {{ $paynowCurrency }})</span>@endif
                                            </button>
                                            <div class="fs-8 text-muted mt-1">{{ $gateway->methods() }}</div>
                                        </div>
                                    @endforeach
                                    @if($manualInstructions !== '')
                                        <div>
                                            <button type="button" class="btn btn-white w-100" x-on:click="transfer = !transfer"><x-icon name="landmark" /> Pay by bank transfer or mobile money</button>
                                            <div class="fs-8 text-muted mt-1">Our team confirms transfers within one working day.</div>
                                            <div x-show="transfer" x-cloak class="border rounded-3 p-3 mt-2">
                                                <div class="fs-7 mb-3" style="white-space: pre-line">{{ $manualInstructions }}</div>
                                                <label class="form-label fs-7" for="ai-pay-note">Your reference or phone number used</label>
                                                <input type="text" id="ai-pay-note" name="note" class="form-control form-control-sm mb-2" maxlength="500" placeholder="e.g. EcoCash from 077 123 4567, ref ABC123">
                                                <button type="submit" name="gateway" value="manual" class="btn btn-sm btn-primary">I have sent <span x-text="p.usd_label"></span></button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </form>
                @elseif($packs)
                    <div class="alert alert-light border fs-7 mb-0">Online payment is not set up on this Zonseob yet, so please contact support to buy tokens.</div>
                @else
                    <p class="text-muted mb-0">There are no token packs to buy at the moment.</p>
                @endif

                @if($purchases->isNotEmpty())
                    <h6 class="mt-4 mb-2">Token purchases</h6>
                    <div class="z-table-wrap border rounded-3">
                        <table class="table z-table mb-0">
                            <thead><tr><th>Date</th><th>Pack</th><th>Amount</th><th>Paid with</th><th>Status</th><th>Reference</th></tr></thead>
                            <tbody>
                            @foreach($purchases as $purchase)
                                <tr>
                                    <td class="fs-7">{{ $purchase->created_at->format('d M Y, H:i') }}</td>
                                    <td class="z-row-title">{{ $purchase->description() }}</td>
                                    <td>{{ $purchase->baseMoney() }}@if($purchase->currency_code !== $purchase->base_currency) <span class="text-muted fs-8">({{ $purchase->money() }})</span>@endif</td>
                                    <td>{{ $purchase->isManual() ? 'Bank transfer' : ucfirst($purchase->gateway) }}</td>
                                    <td><x-pill :status="$purchase->status" />@if($purchase->failure_reason) <span class="fs-8 text-muted">{{ $purchase->failure_reason }}</span>@endif</td>
                                    <td class="fs-8 text-muted">{{ $purchase->gateway_reference ?? $purchase->note ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@push('scripts')
<script>
    (function () {
        var open = function () {
            if (location.hash !== '#buy-tokens') return;
            var el = document.getElementById('ai-buy');
            if (el && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(el).show();
        };
        window.addEventListener('load', open);
        window.addEventListener('hashchange', open);
    })();
</script>
@endpush
