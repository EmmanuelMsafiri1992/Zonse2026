@extends('layouts.app')
@section('title', $contact->exists ? 'Edit '.$contact->displayName() : 'New contact')
@section('content')
    <x-page-header :title="$contact->exists ? 'Edit contact' : 'New contact'"
                   :sub="$contact->exists ? $contact->displayName() : 'Add a customer, supplier, lead or anyone else you work with.'"
                   :crumbs="['Contacts' => route('contacts.index'), $contact->exists ? 'Edit' : 'New']" />

    <form method="POST" action="{{ $contact->exists ? route('contacts.update', $contact) : route('contacts.store') }}" x-data="{ kind: '{{ old('kind', $contact->kind ?? 'person') }}' }">
        @csrf
        @if($contact->exists) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Who is this?</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6"><x-form.select name="type" label="Type" :options="$types" :value="$contact->type" required /></div>
                            <div class="col-md-6"><x-form.select name="kind" label="Person or company" :options="$kinds" :value="$contact->kind" required x-model="kind" /></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><x-form.input name="name" :label="'Full name'" :value="$contact->name" required help="For a company, the main person you deal with." /></div>
                            <div class="col-md-6"><x-form.input name="company_name" label="Company / organisation" :value="$contact->company_name" ::required="kind === 'company'" /></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><x-form.input name="email" type="email" label="Email" :value="$contact->email" /></div>
                            <div class="col-md-3"><x-form.input name="phone" label="Phone" :value="$contact->phone" /></div>
                            <div class="col-md-3"><x-form.input name="mobile" label="Mobile / WhatsApp" :value="$contact->mobile" /></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Address & billing</h5></div>
                    <div class="card-body">
                        <x-form.input name="address" label="Street address" :value="$contact->address" />
                        <div class="row">
                            <div class="col-md-4"><x-form.input name="city" label="City / town" :value="$contact->city" /></div>
                            <div class="col-md-4"><x-form.select name="country_code" label="Country" :options="$countries" :value="$contact->country_code ?? $workspace->country_code" placeholder="—" /></div>
                            <div class="col-md-4"><x-form.select name="currency_code" label="Billing currency" :options="$currencies" :value="$contact->currency_code ?? $workspace->currency_code" placeholder="Workspace default" /></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><x-form.input name="tax_number" label="Tax / VAT number" :value="$contact->tax_number" /></div>
                            <div class="col-md-6"><x-form.select name="branch_id" label="Branch" :options="$branches" :value="$contact->branch_id" placeholder="Any branch" /></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Organise</h5></div>
                    <div class="card-body">
                        <x-form.input name="tags" label="Tags" :value="implode(', ', $contact->tags ?? [])" help="Comma separated, e.g. vip, wholesale" />
                        <x-form.textarea name="notes" label="Notes" :value="$contact->notes" rows="5" />
                        <x-form.check name="is_active" label="Active" :checked="$contact->is_active ?? true" help="Archived contacts are hidden from pickers but keep their history." />
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><x-icon name="check" /> {{ $contact->exists ? 'Save changes' : 'Add contact' }}</button>
                    <a href="{{ $contact->exists ? route('contacts.show', $contact) : route('contacts.index') }}" class="btn btn-white">Cancel</a>
                </div>
            </div>
        </div>
    </form>
@endsection
