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
                        $p->includes_all_modules ? 'All 300+ apps included' : $p->modules_count.' apps included',
                        isset($limits['users']) ? ($limits['users'] === -1 ? 'Unlimited team members' : $limits['users'].' team members') : null,
                        isset($limits['branches']) ? ($limits['branches'] === -1 ? 'Unlimited branches' : $limits['branches'].' branches') : null,
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
                        <form method="POST" action="{{ route('settings.billing.subscribe', $p) }}" class="mt-3">
                            @csrf
                            <input type="hidden" name="billing_cycle" :value="cycle">
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
