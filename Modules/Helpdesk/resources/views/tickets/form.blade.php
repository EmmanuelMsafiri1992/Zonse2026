@extends('layouts.app')
@section('title', $ticket->exists ? 'Edit ticket' : 'New ticket')
@section('content')
    <x-page-header :title="$ticket->exists ? 'Edit '.$ticket->number : 'New ticket'"
                   :sub="$ticket->exists ? $ticket->subject : 'Log what the customer needs so the team can pick it up.'"
                   :crumbs="['Helpdesk' => route('tickets.index'), $ticket->exists ? 'Edit' : 'New']" />

    <form method="POST" action="{{ $ticket->exists ? route('tickets.update', $ticket) : route('tickets.store') }}">
        @csrf
        @if($ticket->exists) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-body">
                        <x-form.input name="subject" label="Subject" :value="$ticket->subject" placeholder="e.g. Invoice shows the wrong amount" required autofocus />
                        <x-form.textarea name="body" label="What happened" :value="$ticket->body" rows="6" placeholder="What the customer told you, in their words where you can." />
                    </div>
                </div>

                <div class="card mb-3" x-data="{ contact: '{{ old('contact_id', $ticket->contact_id) }}' }">
                    <div class="card-header"><h5 class="card-title">Who is asking</h5></div>
                    <div class="card-body">
                        <x-form.select name="contact_id" label="Contact" :options="$contacts" :value="$ticket->contact_id" placeholder="Not in your contacts (type their details below)" x-model="contact" />
                        <div class="row g-3" x-show="!contact">
                            <div class="col-md-6"><x-form.input name="requester_name" label="Requester name" :value="$ticket->requester_name" placeholder="Walk-in or caller name" /></div>
                            <div class="col-md-6"><x-form.input name="requester_email" type="email" label="Requester email" :value="$ticket->requester_email" placeholder="optional" /></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Triage</h5></div>
                    <div class="card-body">
                        <x-form.select name="channel" label="Came in via" :options="$channels" :value="$ticket->channel" required />
                        <x-form.select name="priority" label="Priority" :options="$priorities" :value="$ticket->priority" required />
                        <x-form.input name="category" label="Category" :value="$ticket->category" placeholder="e.g. Billing, Delivery, Complaint" />
                        <x-form.select name="assignee_id" label="Assigned to" :options="$members" :value="$ticket->assignee_id" placeholder="Unassigned" />
                        @if($branches->count() > 1)
                            <x-form.select name="branch_id" label="Branch" :options="$branches" :value="$ticket->branch_id" placeholder="—" />
                        @endif
                    </div>
                    <div class="card-footer d-flex justify-content-between">
                        <a href="{{ $ticket->exists ? route('tickets.show', $ticket) : route('tickets.index') }}" class="btn btn-white">Cancel</a>
                        <button class="btn btn-primary"><x-icon name="check" /> {{ $ticket->exists ? 'Save changes' : 'Open ticket' }}</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
