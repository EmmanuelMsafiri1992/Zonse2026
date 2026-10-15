{{-- Pay for a plan period. Expects $plans, $subscription, $quotes, $gateways, $manualInstructions, $payments, $access. --}}
@php
    $payable = $plans->reject(fn ($p) => $p->isFree())->values();
    $currentKey = $subscription?->plan && ! $subscription->plan->isFree() ? $subscription->plan->key : $payable->first()?->key;
@endphp
@if($payable->isNotEmpty() && ($gateways || $manualInstructions !== ''))
    <div class="card mb-4" x-data="{ plan: {{ Js::from($currentKey) }}, cycle: {{ Js::from($subscription?->billing_cycle ?? 'monthly') }}, quotes: {{ Js::from($quotes) }}, transfer: false, get q() { return this.quotes[this.plan][this.cycle]; } }">
        <div class="card-header d-flex align-items-center">
            <h5 class="card-title mb-0">Pay for your plan</h5>
            @if($access->onTrial())
                <span class="ms-auto text-muted fs-7">Trial ends {{ $access->until->toFormattedDateString() }}. Paying now starts your paid period today.</span>
            @elseif($access->inGrace())
                <span class="ms-auto text-warning fs-7">Your plan has lapsed. Pay by {{ $access->until->toFormattedDateString() }} to keep using your apps.</span>
            @elseif($access->isBlocked())
                <span class="ms-auto text-danger fs-7">Your apps are paused until a plan is paid.</span>
            @elseif($access->until)
                <span class="ms-auto text-muted fs-7">Paid until {{ $access->until->toFormattedDateString() }}. Paying again adds time from that date.</span>
            @endif
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('settings.billing.checkout') }}">
                @csrf
                <input type="hidden" name="plan" :value="plan">
                <input type="hidden" name="billing_cycle" :value="cycle">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <label class="form-label" for="pay-plan">Plan</label>
                        <select id="pay-plan" class="form-select mb-3" x-model="plan">
                            @foreach($payable as $p)
                                <option value="{{ $p->key }}">{{ $p->name }} · ${{ number_format($p->price_monthly, 0) }}/month or ${{ number_format($p->price_yearly, 0) }}/year</option>
                            @endforeach
                        </select>
                        <div class="form-label">Pay for</div>
                        <div class="btn-group d-flex mb-3" role="group">
                            <button type="button" class="btn" :class="cycle === 'monthly' ? 'btn-primary' : 'btn-white'" x-on:click="cycle = 'monthly'">One month</button>
                            <button type="button" class="btn" :class="cycle === 'yearly' ? 'btn-primary' : 'btn-white'" x-on:click="cycle = 'yearly'">One year</button>
                        </div>
                        <div class="fs-7 text-muted">Prices are in US dollars. Where we show a local amount, that is what you will be charged at today's rate.</div>
                    </div>
                    <div class="col-lg-7">
                        <div class="border rounded-3 p-3 mb-3">
                            <div class="d-flex justify-content-between fs-7"><span x-text="q.plan_label"></span><span x-text="q.plan"></span></div>
                            <template x-if="q.addons_raw > 0">
                                <div class="d-flex justify-content-between fs-7 text-muted"><span>Add-on apps</span><span x-text="q.addons"></span></div>
                            </template>
                            <div class="d-flex justify-content-between fw-600 border-top mt-2 pt-2"><span>Total</span><span x-text="q.usd"></span></div>
                            <template x-if="Object.keys(q.local).length">
                                <div class="mt-2 fs-7 text-muted">
                                    <div>About the same as</div>
                                    <template x-for="[code, amount] in Object.entries(q.local)" :key="code">
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
                                        @if($gateway->key() === 'paynow' && ($paynowCurrency ?? 'USD') !== 'USD')<span class="fw-normal">(charged in {{ $paynowCurrency }})</span>@endif
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
                                        <label class="form-label fs-7" for="pay-note">Your reference or phone number used</label>
                                        <input type="text" id="pay-note" name="note" class="form-control form-control-sm mb-2" maxlength="500" placeholder="e.g. EcoCash from 077 123 4567, ref ABC123">
                                        <button type="submit" name="gateway" value="manual" class="btn btn-sm btn-primary">I have sent <span x-text="q.usd"></span></button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
@elseif($access->inGrace() || $access->isBlocked())
    <div class="alert alert-warning d-flex gap-2 align-items-start mb-4">
        <x-icon name="alert-triangle" class="zi mt-1" />
        <div class="fs-7">
            @if($access->isBlocked())Your apps are paused until a plan is paid.@else Your plan has lapsed. Pay by {{ $access->until->toFormattedDateString() }} to keep using your apps.@endif
            Online payment is not set up on this Zonseob yet, so please contact support to pay for your plan.
        </div>
    </div>
@endif

@if($payments->isNotEmpty())
    <div class="card mb-4">
        <div class="card-header"><h5 class="card-title">Payments</h5></div>
        <div class="z-table-wrap">
            <table class="table z-table">
                <thead><tr><th>Date</th><th>For</th><th>Amount</th><th>Paid with</th><th>Status</th><th>Reference</th></tr></thead>
                <tbody>
                @foreach($payments as $payment)
                    <tr>
                        <td class="fs-7">{{ $payment->created_at->format('d M Y, H:i') }}</td>
                        <td class="z-row-title">{{ $payment->description() }}</td>
                        <td>{{ $payment->baseMoney() }}@if($payment->currency_code !== $payment->base_currency) <span class="text-muted fs-8">({{ $payment->money() }})</span>@endif</td>
                        <td>{{ $payment->isManual() ? 'Bank transfer' : ucfirst($payment->gateway) }}</td>
                        <td><x-pill :status="$payment->status" />@if($payment->failure_reason) <span class="fs-8 text-muted">{{ $payment->failure_reason }}</span>@endif</td>
                        <td class="fs-8 text-muted">{{ $payment->gateway_reference ?? $payment->note ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
