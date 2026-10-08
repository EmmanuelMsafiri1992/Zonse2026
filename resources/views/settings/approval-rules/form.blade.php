@extends('layouts.app')
@section('title', $rule->exists ? $rule->name : 'New approval rule')
@section('content')
    <x-page-header :title="$rule->exists ? $rule->name : 'New approval rule'" :sub="$rule->exists ? $rule->subjectLabel().' · '.$rule->limitLabel() : 'Drafts that fit this rule wait for a yes before they can go out.'" :crumbs="['Settings' => route('settings.workspace.edit'), 'Approval rules' => route('settings.approval-rules.index'), $rule->exists ? 'Edit' : 'New']" />

    <div class="row g-4">
        <div class="col-xl-7">
            <form method="POST" action="{{ $rule->exists ? route('settings.approval-rules.update', $rule) : route('settings.approval-rules.store') }}" class="card" x-data="{ approver: @js(old('approver', $rule->approver ?? 'admins')) }">
                @csrf
                @if($rule->exists) @method('PUT') @endif
                <div class="card-body">
                    <x-form.input name="name" label="Name" :value="$rule->name" maxlength="120" required placeholder="e.g. Large invoices" />
                    <x-form.select name="subject" label="What needs approval" :options="$subjects" :value="$rule->subject" required />
                    <div class="row">
                        <div class="col-md-6"><x-form.input name="min_amount" type="number" step="0.01" min="0" label="From this total" :value="$rule->min_amount ?? 0" help="0 means every one." /></div>
                        <div class="col-md-6"><x-form.select name="currency_code" label="Currency" :options="$currencies" :value="$rule->currency_code" placeholder="Any currency" help="Leave as any to apply the amount whatever the currency." /></div>
                    </div>
                    <x-form.select name="approver" label="Who approves" :options="collect($approvers)->map(fn ($approver) => is_array($approver) ? $approver['label'] : $approver)->all()" :value="$rule->approver ?? 'admins'" required x-model="approver" />
                    <div x-show="approver === 'person'" x-cloak>
                        <x-form.select name="approver_id" label="Person" :options="$people" :value="$rule->approver_id" placeholder="Choose someone" help="The owner can always approve too." />
                    </div>
                    <x-form.check name="is_active" label="Rule is on" :checked="$rule->exists ? $rule->is_active : true" help="Turn it off to pause it without losing it." switch />
                </div>
                <div class="card-footer d-flex gap-2">
                    <button class="btn btn-primary"><x-icon name="check" /> Save rule</button>
                    <a href="{{ route('settings.approval-rules.index') }}" class="btn btn-white">Cancel</a>
                </div>
            </form>
        </div>
        <div class="col-xl-5">
            <div class="card mb-3">
                <div class="card-body fs-7">
                    <h6>How it works</h6>
                    <ul class="text-muted mb-0 ps-3">
                        <li>Someone who can't approve sees a request button on the draft instead of send.</li>
                        <li>Approvers are told straight away, and see it under Approvals.</li>
                        <li>Approvers sending their own drafts skip the wait.</li>
                    </ul>
                </div>
            </div>
            @if($rule->exists)
                <div class="card">
                    <div class="card-body">
                        <h6>Remove this rule</h6>
                        <p class="fs-7 text-muted">Drafts it was holding can go out straight away. Decisions already made are kept.</p>
                        <form method="POST" action="{{ route('settings.approval-rules.destroy', $rule) }}" onsubmit="return confirm('Remove this rule?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-soft-danger"><x-icon name="trash-2" /> Remove rule</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
