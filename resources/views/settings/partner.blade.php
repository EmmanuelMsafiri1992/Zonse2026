@extends('layouts.app')
@section('title', 'Partner program')
@section('content')
    <x-page-header title="Partner program" sub="Bring businesses to the platform, run their workspaces under your brand and earn a share of what they pay." :crumbs="['Settings' => route('settings.workspace.edit'), 'Partner program']" />

    @if(! $workspace->isPartner())
        <div class="card">
            <div class="card-body">
                <x-empty icon="handshake" title="Become a partner"
                    text="Get a referral link, set up workspaces for your clients and earn {{ rtrim(rtrim(number_format($percent, 2), '0'), '.') }}% of every subscription they pay, every month." class="py-2" />
                @if($workspace->reseller_id)
                    <p class="text-center fs-7 text-muted mb-0">This workspace is a client of {{ $workspace->reseller?->name ?? 'a partner' }}, so it cannot join the program itself.</p>
                @else
                    <form method="POST" action="{{ route('settings.partners.enable') }}" class="text-center">
                        @csrf
                        <button class="btn btn-primary"><x-icon name="handshake" /> Join the partner program</button>
                    </form>
                @endif
            </div>
        </div>
    @else
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-xl-3"><x-stat label="Clients" :value="$totals['clients']" icon="users-round" /></div>
            <div class="col-sm-6 col-xl-3"><x-stat label="Paying" :value="$totals['paying']" icon="badge-check" color="success" /></div>
            <div class="col-sm-6 col-xl-3"><x-stat label="Client revenue / month" :value="\App\Support\Money::format($totals['monthly'], $rows->first()['currency'] ?? 'USD')" icon="trending-up" color="info" /></div>
            <div class="col-sm-6 col-xl-3"><x-stat label="Your commission / month" :value="\App\Support\Money::format($totals['commission'], $rows->first()['currency'] ?? 'USD')" icon="wallet" color="warning" /></div>
        </div>

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">Clients</h5></div>
                    @if($rows->isEmpty())
                        <div class="card-body"><x-empty icon="users-round" title="No clients yet" text="Share your referral link or set up a workspace for a client below." class="py-2" /></div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Workspace</th><th>Plan</th><th>Status</th><th class="text-end">Pays / month</th><th class="text-end">Your share</th><th></th></tr></thead>
                                <tbody>
                                    @foreach($rows as $row)
                                        <tr>
                                            <td><span class="fw-600">{{ $row['workspace']->name }}</span><span class="d-block fs-8 text-muted">{{ $row['workspace']->owner?->name }}</span></td>
                                            <td>{{ $row['plan'] ?? '—' }}</td>
                                            <td><x-pill :status="$row['status']" /></td>
                                            <td class="text-end">{{ \App\Support\Money::format($row['monthly'], $row['currency']) }}</td>
                                            <td class="text-end fw-600">{{ \App\Support\Money::format($row['commission'], $row['currency']) }}</td>
                                            <td class="text-end">
                                                @if(in_array($row['workspace']->id, $memberOf, true))
                                                    <form method="POST" action="{{ route('workspaces.switch', $row['workspace']) }}">@csrf<button class="btn btn-white btn-sm">Open</button></form>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                    <form method="POST" action="{{ route('settings.partners.clients.store') }}" class="card-footer d-flex gap-2 align-items-start">
                        @csrf
                        <div class="flex-grow-1"><x-form.input name="name" placeholder="Client business name" aria-label="Client business name" required class="mb-0" /></div>
                        <button class="btn btn-primary text-nowrap"><x-icon name="plus" /> Set up client</button>
                    </form>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header"><h5 class="card-title">Referral link</h5></div>
                    <div class="card-body" x-data="{ copied: false }">
                        <p class="fs-7 text-muted">Anyone who signs up through this link becomes your client.</p>
                        <div class="input-group">
                            <input type="text" class="form-control fs-8" value="{{ $referralUrl }}" readonly x-ref="link" aria-label="Referral link">
                            <button type="button" class="btn btn-white" @click="navigator.clipboard?.writeText($refs.link.value); copied = true; setTimeout(() => copied = false, 1500)">
                                <x-icon name="copy" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                            </button>
                        </div>
                        <div class="form-text">Your code: <code>{{ $workspace->setting('partner.code') }}</code> · {{ rtrim(rtrim(number_format($percent, 2), '0'), '.') }}% commission</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.partners.update') }}" class="card mb-4">
                    @csrf @method('PUT')
                    <div class="card-header"><h5 class="card-title">White-label</h5></div>
                    <div class="card-body">
                        <x-form.check name="white_label" label="Show my brand to my clients" :checked="(bool) $workspace->setting('partner.white_label')" switch
                            help="Clients see your name, colour and logo instead of ours, and the 'powered by' footer is hidden." />
                        <a href="{{ route('settings.branding.edit') }}" class="fs-7">Set your brand name, colour and domain</a>
                    </div>
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save</button></div>
                </form>

                <form method="POST" action="{{ route('settings.partners.disable') }}" onsubmit="return confirm('Leave the partner program? Your referral link will stop working.')">
                    @csrf @method('DELETE')
                    <button class="btn btn-link text-danger p-0 fs-7">Leave the partner program</button>
                </form>
            </div>
        </div>
    @endif
@endsection
