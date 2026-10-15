{{-- Trial, grace and paused notices at the top of every app page. --}}
@php
    $access = $workspace ? \App\Payments\SubscriptionAccess::for($workspace) : null;
    $canPay = $access && auth()->user() && (auth()->user()->is_super_admin || auth()->user()->isAdminOf($workspace));
    $showTrial = $access?->onTrial() && $access->daysLeft() <= 7;
@endphp
@if($access && ($showTrial || $access->inGrace() || $access->isBlocked()) && ! request()->routeIs('settings.billing.*'))
    <div class="alert {{ $access->isBlocked() ? 'alert-danger' : 'alert-warning' }} d-flex gap-2 align-items-center flex-wrap mb-4" data-subscription-notice="{{ $access->state }}">
        <x-icon name="{{ $access->isBlocked() ? 'pause' : 'hourglass' }}" class="zi" />
        <div class="fs-7 flex-grow-1">
            @if($showTrial)
                Your free trial ends {{ $access->daysLeft() === 0 ? 'today' : 'in '.$access->daysLeft().' '.\Illuminate\Support\Str::plural('day', $access->daysLeft()) }}.
                {{ $canPay ? 'Pay for a plan to carry on without a break.' : 'Ask your workspace owner to pay for a plan to carry on without a break.' }}
            @elseif($access->inGrace())
                Your plan has lapsed. {{ $canPay ? 'Pay by '.$access->until->toFormattedDateString().' to keep using your apps.' : 'Your apps close on '.$access->until->toFormattedDateString().' unless the workspace owner pays for a plan.' }}
            @else
                Your apps are paused until a plan is paid.
            @endif
        </div>
        @if($canPay)<a href="{{ route('settings.billing.index') }}" class="btn btn-sm {{ $access->isBlocked() ? 'btn-danger' : 'btn-primary' }}">Pay now</a>@endif
    </div>
@endif
