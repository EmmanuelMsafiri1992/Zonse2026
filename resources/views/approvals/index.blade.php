@extends('layouts.app')
@section('title', 'Approvals')
@section('content')
    <x-page-header title="Approvals" sub="Invoices and quotes that need a yes before they go out.">
        @if($isAdmin)
            <a href="{{ route('settings.approval-rules.index') }}" class="btn btn-white"><x-icon name="shield-check" /> Approval rules</a>
        @endif
    </x-page-header>

    <ul class="nav nav-pills mb-3 gap-1">
        @foreach($tabs as $key => $label)
            <li class="nav-item">
                <a href="{{ route('approvals.index', ['tab' => $key]) }}" @class(['nav-link', 'active' => $tab === $key])>
                    {{ $label }}@if($key === 'waiting' && $waiting->isNotEmpty()) <span class="badge bg-danger ms-1">{{ $waiting->count() }}</span>@endif
                </a>
            </li>
        @endforeach
    </ul>

    @if($tab === 'waiting')
        @if($waiting->isEmpty())
            <div class="card"><div class="card-body">
                @if($hasRules)
                    <x-empty icon="circle-check" title="Nothing waiting for you" text="When someone asks for your approval, it shows up here and you are told straight away." />
                @else
                    <x-empty icon="shield-check" title="No approval rules yet" :text="$isAdmin ? 'Set a rule, such as invoices from US$1,000 need an admin to approve them, under Approval rules.' : 'An admin can set rules for what needs approval.'" />
                @endif
            </div></div>
        @else
            <div class="row g-3">
                @foreach($waiting as $approval)
                    <div class="col-xl-6">
                        <div class="card h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <div>
                                        @if($approval->approvable && method_exists($approval->approvable, 'activityUrl'))
                                            <a href="{{ $approval->approvable->activityUrl() }}" class="fw-600">{{ $approval->title }}</a>
                                        @else
                                            <span class="fw-600">{{ $approval->title }}</span> <span class="text-danger fs-8">(deleted)</span>
                                        @endif
                                        <div class="fs-8 text-muted">{{ $approval->subjectLabel() }} · asked by {{ $approval->requester?->name ?? 'someone' }} {{ $approval->created_at->diffForHumans() }}</div>
                                    </div>
                                    <div class="text-end">
                                        <div class="fw-600">{{ $approval->amountLabel() }}</div>
                                        @if($approval->approvable && abs((float) $approval->approvable->total - (float) $approval->amount) > 0.005)
                                            <div class="fs-8 text-warning">now {{ \App\Support\Money::format($approval->approvable->total, $approval->approvable->currency_code) }}</div>
                                        @endif
                                    </div>
                                </div>
                                @if($approval->note)<p class="fs-7 mb-2" style="white-space:pre-line">“{{ $approval->note }}”</p>@endif
                                @if($approval->rule)<div class="fs-8 text-muted mb-2">Rule: {{ $approval->rule->name }}</div>@endif
                                <form method="POST">
                                    @csrf
                                    <textarea name="decision_note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Note for {{ $approval->requester?->name ?? 'them' }} (optional)"></textarea>
                                    <div class="d-flex gap-2">
                                        @if($approval->approvable)
                                            <button class="btn btn-sm btn-success" formaction="{{ route('approvals.approve', $approval) }}"><x-icon name="check" class="zi zi-sm" /> Approve</button>
                                        @endif
                                        <button class="btn btn-sm btn-white text-danger" formaction="{{ route('approvals.reject', $approval) }}"><x-icon name="x" class="zi zi-sm" /> Turn down</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @else
        <div class="card">
            @if($requests->isEmpty())
                <div class="card-body"><x-empty icon="circle-check" title="No requests yet" :text="$tab === 'mine' ? 'When a draft needs approval, ask from its page and follow it here.' : 'Requests made in this workspace show here.'" /></div>
            @else
                <div class="z-table-wrap">
                    <table class="table z-table align-middle mb-0">
                        <thead><tr><th>Document</th><th>Step</th><th>Asked by</th><th class="text-end">Amount</th><th>Status</th><th>Decided</th><th></th></tr></thead>
                        <tbody>
                        @foreach($requests as $approval)
                            <tr>
                                <td class="z-row-title">
                                    @if($approval->approvable && method_exists($approval->approvable, 'activityUrl'))
                                        <a href="{{ $approval->approvable->activityUrl() }}">{{ $approval->title }}</a>
                                    @else
                                        {{ $approval->title }} <span class="text-danger fs-8">(deleted)</span>
                                    @endif
                                    @if($approval->note)<div class="fs-8 text-muted text-truncate" style="max-width:280px">{{ $approval->note }}</div>@endif
                                </td>
                                <td class="fs-7">{{ $approval->subjectLabel() }}</td>
                                <td class="fs-7">{{ $approval->requester?->name ?? '—' }}<div class="fs-8 text-muted">{{ $approval->created_at->format('d M Y H:i') }}</div></td>
                                <td class="text-end">{{ $approval->amountLabel() }}</td>
                                <td><x-pill :status="$approval->status">{{ $approval->statusLabel() }}</x-pill></td>
                                <td class="fs-7">
                                    @if($approval->decided_at)
                                        {{ $approval->decider?->name ?? '—' }}<div class="fs-8 text-muted">{{ $approval->decided_at->format('d M Y H:i') }}</div>
                                        @if($approval->decision_note)<div class="fs-8 text-truncate" style="max-width:220px" title="{{ $approval->decision_note }}">“{{ $approval->decision_note }}”</div>@endif
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if($approval->isPending() && ($approval->requested_by === auth()->id() || $isAdmin))
                                        <form method="POST" action="{{ route('approvals.withdraw', $approval) }}">@csrf<button class="btn btn-sm btn-white" title="Withdraw"><x-icon name="undo-2" /></button></form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                @if($requests->hasPages())<div class="card-footer">{{ $requests->links() }}</div>@endif
            @endif
        </div>
    @endif
@endsection
