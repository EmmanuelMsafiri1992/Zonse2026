@extends('layouts.app')
@section('title', 'Plan & billing')
@section('content')
    <x-page-header title="Plan & billing" sub="Your subscription, trial and payment history." :crumbs="['Settings' => route('settings.workspace.edit'), 'Billing']" />

    @if($subscription)
        <div class="card mb-4">
            <div class="card-body d-flex flex-wrap gap-4 align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <span class="z-avatar z-avatar-lg z-bg-primary rounded-3"><x-icon name="credit-card" class="zi zi-lg" /></span>
                    <div>
                        <div class="fs-8 text-muted text-uppercase fw-600">Current plan</div>
                        <div class="h4 mb-0">{{ $subscription->plan?->name }} <x-pill :status="$subscription->status" class="ms-1 align-middle" /></div>
                    </div>
                </div>
                <div class="vr d-none d-md-block"></div>
                <div>
                    <div class="fs-8 text-muted text-uppercase fw-600">Billing</div>
                    <div class="fw-600">{{ $subscription->currency }} {{ number_format($subscription->amount, 2) }} / {{ $subscription->billing_cycle === 'yearly' ? 'year' : 'month' }}</div>
                </div>
                <div>
                    <div class="fs-8 text-muted text-uppercase fw-600">{{ $subscription->status === 'trialing' ? 'Trial ends' : 'Renews' }}</div>
                    <div class="fw-600">{{ ($subscription->status === 'trialing' ? $subscription->trial_ends_at : $subscription->current_period_end)?->toFormattedDateString() ?? '—' }}</div>
                </div>
                <div class="ms-auto d-flex gap-2">
                    @if(in_array($subscription->status, ['trialing', 'active']))
                        <form method="POST" action="{{ route('settings.billing.cancel') }}" onsubmit="return confirm('Cancel your subscription at the end of the current period?')">
                            @csrf
                            <button class="btn btn-soft-danger">Cancel plan</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>
    @endif

    @if($subscription && ($addons->isNotEmpty() || $usage['allowance'] !== null))
        @php $per = $subscription->billing_cycle === 'yearly' ? 'year' : 'month'; @endphp
        <div class="card mb-4">
            <div class="card-header d-flex align-items-center">
                <h5 class="card-title mb-0">Apps & add-ons</h5>
                @if($usage['allowance'] !== null)
                    <span class="ms-auto text-muted fs-7">{{ $usage['used'] }} of {{ $usage['allowance'] }} apps in your plan used</span>
                @endif
            </div>
            <div class="z-table-wrap">
                <table class="table z-table">
                    <thead><tr><th>Add-on app</th><th>Price</th><th>Added</th><th></th></tr></thead>
                    <tbody>
                    <tr>
                        <td class="z-row-title">{{ $subscription->plan?->name }} plan</td>
                        <td>{{ \App\Support\Money::format($subscription->plan?->priceFor($subscription->billing_cycle), $subscription->currency) }} / {{ $per }}</td>
                        <td class="text-muted fs-7">—</td><td></td>
                    </tr>
                    @forelse($addons as $addon)
                        <tr>
                            <td class="z-row-title"><x-icon :name="$addon->icon ?: 'box'" class="zi zi-sm me-1" /> {{ $addon->name }}</td>
                            <td>{{ \App\Support\Money::format($subscription->billing_cycle === 'yearly' ? $addon->pivot->addon_yearly : $addon->pivot->addon_monthly, $subscription->currency) }} / {{ $per }}</td>
                            <td class="text-muted fs-7">{{ $addon->pivot->enabled_at ? \Illuminate\Support\Carbon::parse($addon->pivot->enabled_at)->toFormattedDateString() : '—' }}</td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('settings.modules.disable', $addon->key) }}" onsubmit="return confirm({{ Js::from('Turn off '.$addon->name.'? Its records are kept.') }})">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-soft-danger">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted fs-7">No paid add-ons. <a href="{{ route('settings.modules.index') }}">Browse apps</a></td></tr>
                    @endforelse
                    <tr class="fw-600">
                        <td>Total</td>
                        <td colspan="3">{{ \App\Support\Money::format($subscription->amount, $subscription->currency) }} / {{ $per }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <div class="alert alert-light border d-flex gap-2 align-items-start">
        <x-icon name="info" class="zi mt-1 text-primary" />
        <div class="fs-7">Online card and mobile-money payments (Paynow, EcoCash, Stripe, Paystack) are being connected. Until then our team confirms payments manually and activates your plan within a working day.</div>
    </div>

    <div x-data="{ cycle: {{ Js::from($subscription?->billing_cycle ?? 'monthly') }} }">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="mb-0">Plans</h5>
            <div class="btn-group">
                <button type="button" class="btn btn-sm" :class="cycle === 'monthly' ? 'btn-primary' : 'btn-white'" x-on:click="cycle = 'monthly'">Monthly</button>
                <button type="button" class="btn btn-sm" :class="cycle === 'yearly' ? 'btn-primary' : 'btn-white'" x-on:click="cycle = 'yearly'">Yearly</button>
            </div>
        </div>
        <div class="row g-4">
            @foreach($plans as $p)
                @php
                    $limits = $p->limits ?? [];
                    $features = array_filter([
                        ...$p->appsSummary(),
                        isset($limits['users']) ? ($limits['users'] === -1 ? 'Unlimited team members' : $limits['users'].' '.\Illuminate\Support\Str::plural('team member', $limits['users'])) : null,
                        isset($limits['branches']) ? ($limits['branches'] === -1 ? 'Unlimited branches' : $limits['branches'].' '.\Illuminate\Support\Str::plural('branch', $limits['branches'])) : null,
                        isset($limits['storage_gb']) ? $limits['storage_gb'].' GB storage' : null,
                        ...($limits['extras'] ?? []),
                    ]);
                @endphp
                <div class="col-md-6 col-xl-3">
                    <div class="z-plan-card {{ $p->is_featured ? 'featured' : '' }} {{ $subscription?->plan_id === $p->id ? 'selected' : '' }}" style="cursor:default">
                        @if($p->is_featured)<span class="z-plan-tag">Most popular</span>@endif
                        <h5 class="mb-0">{{ $p->name }}</h5>
                        <div class="text-muted fs-7 mb-3">{{ $p->tagline }}</div>
                        <div class="z-plan-price mb-3">
                            @if($p->isFree()) Free
                            @else
                                <span x-show="cycle === 'monthly'">${{ number_format($p->price_monthly, 0) }}<small>/mo</small></span>
                                <span x-show="cycle === 'yearly'" x-cloak>${{ number_format($p->price_yearly, 0) }}<small>/yr</small></span>
                            @endif
                        </div>
                        <ul>@foreach($features as $f)<li><x-icon name="check" class="zi zi-sm" /> <span>{{ $f }}</span></li>@endforeach</ul>
                        @php $quote = $switchQuotes[$p->id] ?? []; @endphp
                        @if($quote && $subscription?->plan_id !== $p->id)
                            <div class="alert alert-warning fs-8 p-2 mb-0">
                                {{ count($quote) }} of your apps aren't covered and would stay on as add-ons:
                                <span x-show="cycle === 'monthly'">+{{ \App\Support\Money::format(array_sum(array_column($quote, 'monthly')), $p->currency) }}/mo</span>
                                <span x-show="cycle === 'yearly'" x-cloak>+{{ \App\Support\Money::format(array_sum(array_column($quote, 'yearly')), $p->currency) }}/yr</span>.
                                Turn apps off first to avoid this.
                            </div>
                        @endif
                        <form method="POST" action="{{ route('settings.billing.subscribe', $p) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="billing_cycle" :value="cycle">
                            @if($quote)<input type="hidden" name="keep_addons" value="1">@endif
                            @if($subscription?->plan_id === $p->id && in_array($subscription->status, ['trialing', 'active']))
                                <button type="button" class="btn btn-soft-secondary w-100" disabled>Current plan</button>
                            @else
                                <button type="submit" class="btn {{ $p->is_featured ? 'btn-primary' : 'btn-white' }} w-100">Switch to {{ $p->name }}</button>
                            @endif
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header"><h5 class="card-title">History</h5></div>
        <div class="z-table-wrap">
            <table class="table z-table">
                <thead><tr><th>Plan</th><th>Status</th><th>Amount</th><th>Period</th><th>Started</th></tr></thead>
                <tbody>
                @forelse($history as $h)
                    <tr>
                        <td class="z-row-title">{{ $h->plan?->name }}</td>
                        <td><x-pill :status="$h->status" /></td>
                        <td>{{ $h->currency }} {{ number_format($h->amount, 2) }} <span class="text-muted fs-8">/ {{ $h->billing_cycle }}</span></td>
                        <td class="fs-7">{{ $h->current_period_start?->toFormattedDateString() }} – {{ $h->current_period_end?->toFormattedDateString() }}</td>
                        <td class="text-muted fs-7">{{ $h->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No subscription history yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
