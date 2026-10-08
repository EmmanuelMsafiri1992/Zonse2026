@extends('layouts.app')
@section('title', 'Approval rules')
@section('content')
    <x-page-header title="Approval rules" sub="Decide what needs a yes before it goes out, such as invoices from a set amount needing an admin to approve them." :crumbs="['Settings' => route('settings.workspace.edit'), 'Approval rules']">
        <a href="{{ route('approvals.index') }}" class="btn btn-white"><x-icon name="circle-check" /> Approvals</a>
        <a href="{{ route('settings.approval-rules.create') }}" class="btn btn-primary"><x-icon name="plus" /> New rule</a>
    </x-page-header>

    <div class="row g-3">
        @foreach(\App\Models\ApprovalRule::SUBJECTS as $subject => $definition)
            @php $list = $rules->where('subject', $subject); @endphp
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">{{ $definition['label'] }} <span class="text-muted fw-normal fs-7">({{ $list->count() }})</span></h5>
                        <a href="{{ route('settings.approval-rules.create', ['subject' => $subject]) }}" class="btn btn-sm btn-white"><x-icon name="plus" /> Rule</a>
                    </div>
                    @unless($enabled[$subject])
                        <div class="alert alert-warning fs-8 py-1 mx-3 mt-3 mb-0">The app for this is off in this workspace, so these rules are not in use.</div>
                    @endunless
                    @if($list->isEmpty())
                        <div class="card-body text-muted fs-7">Any {{ $definition['noun'] }} can go out without approval.</div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($list as $rule)
                                <div class="list-group-item d-flex gap-2 align-items-center">
                                    <x-icon name="shield-check" @class(['zi', 'text-muted' => ! $rule->is_active, 'text-primary' => $rule->is_active]) />
                                    <div class="flex-grow-1 min-w-0">
                                        <a href="{{ route('settings.approval-rules.edit', $rule) }}" class="fw-600 text-body">{{ $rule->name }}</a>
                                        @unless($rule->is_active)<span class="badge bg-light text-muted ms-1">Paused</span>@endunless
                                        <div class="fs-8 text-muted text-truncate">{{ $rule->limitLabel() }} · approver: {{ $rule->approverLabel() }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="card mt-3">
        <div class="card-body fs-7 text-muted">
            <x-icon name="info" class="zi zi-sm" />
            When more than one rule fits, the one with the highest amount applies. The owner, and anyone the rule names as an approver, can send straight away.
            An approval holds while the total stays at or below the approved amount; raise it and it needs approving again.
        </div>
    </div>
@endsection
