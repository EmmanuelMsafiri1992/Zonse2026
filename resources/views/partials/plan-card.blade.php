{{-- Expects $p (Plan). Set $selectable=true inside an Alpine scope with `plan` and `cycle`; otherwise pass $cycleStatic. --}}
@php
    $selectable = $selectable ?? false;
    $limits = $p->limits ?? [];
    $features = array_filter([
        $p->includes_all_modules ? 'All 300+ apps included' : ($p->modules_count ?? $p->modules()->count()).' apps included',
        isset($limits['modules']) ? ($limits['modules'] === -1 ? 'Unlimited active apps' : "Up to {$limits['modules']} active apps") : null,
        isset($limits['users']) ? ($limits['users'] === -1 ? 'Unlimited team members' : "{$limits['users']} ".\Illuminate\Support\Str::plural('team member', $limits['users'])) : null,
        isset($limits['branches']) ? ($limits['branches'] === -1 ? 'Unlimited branches' : "{$limits['branches']} ".\Illuminate\Support\Str::plural('branch', $limits['branches'])) : null,
        isset($limits['storage_gb']) ? "{$limits['storage_gb']} GB file storage" : null,
        ...($limits['extras'] ?? []),
    ]);
@endphp
<div class="col-md-6 col-xl-3">
    <div class="z-plan-card {{ $p->is_featured ? 'featured' : '' }}"
         @if($selectable) :class="{ selected: plan === {{ Js::from($p->key) }} }" x-on:click="plan = {{ Js::from($p->key) }}" @endif>
        @if(! empty($bestValue))<span class="z-plan-tag">Best value for your apps</span>
        @elseif($p->is_featured)<span class="z-plan-tag">Most popular</span>@endif
        <h5 class="mb-0">{{ $p->name }}</h5>
        <div class="text-muted fs-7 mb-3">{{ $p->tagline }}</div>
        <div class="z-plan-price mb-3">
            @if($p->isFree())
                Free
            @elseif($selectable)
                <span x-show="cycle === 'monthly'">${{ number_format($p->price_monthly, 0) }}<small>/month</small></span>
                <span x-show="cycle === 'yearly'" x-cloak>${{ number_format($p->price_yearly / 12, 0) }}<small>/month, billed ${{ number_format($p->price_yearly, 0) }}/yr</small></span>
            @else
                ${{ number_format($p->price_monthly, 0) }}<small>/month</small>
                <div class="fs-8 text-muted fw-normal">or ${{ number_format($p->price_yearly, 0) }}/year</div>
            @endif
        </div>
        @if(! $p->isFree() && $p->trial_days)<div class="z-pill z-pill-trial mb-3">{{ $p->trial_days }}-day free trial</div>@endif
        <ul>
            @foreach($features as $f)<li><x-icon name="check" class="zi zi-sm" /> <span>{{ $f }}</span></li>@endforeach
        </ul>
        @if(! empty($addonQuote))
            <div class="alert alert-warning fs-8 p-2 mb-0">
                {{ count($addonQuote) }} of your chosen apps would be paid add-ons:
                +{{ \App\Support\Money::format(array_sum(array_column($addonQuote, 'monthly')), $p->currency) }}/month.
            </div>
        @endif
        @if(! $selectable)
            <form method="POST" action="{{ route('settings.billing.subscribe', $p) }}" class="mt-3">
                @csrf
                <input type="hidden" name="billing_cycle" value="{{ $cycleStatic ?? 'monthly' }}">
                @if(($currentPlanKey ?? null) === $p->key)
                    <button type="button" class="btn btn-soft-secondary w-100" disabled>Current plan</button>
                @else
                    <button type="submit" class="btn {{ $p->is_featured ? 'btn-primary' : 'btn-white' }} w-100">Choose {{ $p->name }}</button>
                @endif
            </form>
        @endif
    </div>
</div>
