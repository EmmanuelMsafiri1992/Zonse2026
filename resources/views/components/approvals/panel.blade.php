@props(['model', 'subject'])
@php
    use App\Models\ApprovalRule;
    use App\Support\Approvals;

    $user = auth()->user();
    $meta = ApprovalRule::SUBJECTS[$subject];
    $rule = $model->getAttribute('status') === ($meta['while'] ?? null) ? Approvals::ruleFor($model, $subject) : null;
    $pending = $rule ? Approvals::pending($model, $subject) : null;
    $approval = $rule && ! $pending ? Approvals::approval($model, $subject) : null;
    $latest = $rule && ! $pending && ! $approval ? Approvals::latest($model, $subject) : null;
@endphp
@if($rule)
    <div class="card mb-3 border-0">
        @if($pending)
            <div class="alert alert-info mb-0">
                <div class="d-flex gap-2">
                    <x-icon name="hourglass" />
                    <div class="flex-grow-1">
                        <strong>Waiting for approval.</strong>
                        <span class="fs-7">{{ $pending->requester?->name ?? 'Someone' }} asked {{ $pending->created_at->diffForHumans() }}; {{ mb_strtolower($rule->approverLabel()) }} can approve.</span>
                        @if($pending->note)<div class="fs-7 mt-1" style="white-space:pre-line">“{{ $pending->note }}”</div>@endif
                        @if($pending->canBeDecidedBy($user))
                            <form method="POST" class="mt-2">
                                @csrf
                                <textarea name="decision_note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Note for {{ $pending->requester?->name ?? 'them' }} (optional)"></textarea>
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-success" formaction="{{ route('approvals.approve', $pending) }}"><x-icon name="check" class="zi zi-sm" /> Approve</button>
                                    <button class="btn btn-sm btn-white text-danger" formaction="{{ route('approvals.reject', $pending) }}"><x-icon name="x" class="zi zi-sm" /> Turn down</button>
                                </div>
                            </form>
                        @elseif($pending->requested_by === $user->id || $user->isAdminOf($pending->workspace))
                            <form method="POST" action="{{ route('approvals.withdraw', $pending) }}" class="mt-2">@csrf<button class="btn btn-sm btn-white"><x-icon name="undo-2" class="zi zi-sm" /> Withdraw request</button></form>
                        @endif
                    </div>
                </div>
            </div>
        @elseif($approval)
            <div class="alert alert-success mb-0 d-flex gap-2">
                <x-icon name="circle-check" />
                <div class="fs-7"><strong>Approved</strong> by {{ $approval->decider?->name ?? 'an approver' }} {{ $approval->decided_at?->diffForHumans() }}. It can go out now.
                    @if($approval->decision_note)<div class="mt-1" style="white-space:pre-line">“{{ $approval->decision_note }}”</div>@endif
                </div>
            </div>
        @elseif($rule->allowsApprovalBy($user))
            <div class="alert alert-light mb-0 d-flex gap-2 fs-7">
                <x-icon name="shield-check" />
                <div>Covered by “{{ $rule->name }}”. You can approve these, so you can send it straight away.</div>
            </div>
        @else
            <div class="alert alert-warning mb-0">
                <div class="d-flex gap-2">
                    <x-icon name="shield-check" />
                    <div class="flex-grow-1">
                        <strong>Needs approval before it goes out.</strong>
                        <span class="fs-7">“{{ $rule->name }}”: {{ mb_strtolower($rule->limitLabel()) }}, approved by {{ mb_strtolower($rule->approverLabel()) }}.</span>
                        @if($latest?->status === 'rejected')
                            <div class="fs-7 mt-1 text-danger">Turned down by {{ $latest->decider?->name ?? 'an approver' }} {{ $latest->decided_at?->diffForHumans() }}@if($latest->decision_note): “{{ $latest->decision_note }}”@endif. Make any changes, then ask again.</div>
                        @endif
                        @can('update', $model)
                            <form method="POST" action="{{ route('approvals.store') }}" class="mt-2">
                                @csrf
                                <input type="hidden" name="subject" value="{{ $subject }}">
                                <input type="hidden" name="id" value="{{ $model->getKey() }}">
                                <textarea name="note" class="form-control form-control-sm mb-2" rows="2" maxlength="1000" placeholder="Anything the approver should know (optional)"></textarea>
                                <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Ask for approval</button>
                            </form>
                        @endcan
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
