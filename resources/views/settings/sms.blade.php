@extends('layouts.app')
@section('title', 'SMS settings')
@section('content')
    <x-page-header title="SMS settings" sub="Text invoices, receipts and reminders to your customers." :crumbs="['Text messages' => route('sms.index'), 'Settings']" />

    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.sms.update') }}" class="card" x-data="{ provider: {{ Js::from(old('provider', $current ?? '')) }} }">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Provider</h5></div>
                <div class="card-body">
                    <p class="fs-7 text-muted">Messages are sent from your own account with the provider, so you pay them directly at their rates. Start with <strong>Test mode</strong> to see what would be sent without paying for anything.</p>

                    <label class="border rounded p-3 mb-2 d-flex gap-2 align-items-start">
                        <input type="radio" class="form-check-input mt-1" name="provider" value="" x-model="provider">
                        <span><span class="fw-600">Off</span><span class="d-block fs-8 text-muted">No text messages are sent.</span></span>
                    </label>
                    @foreach($providers as $key => $row)
                        <div class="border rounded p-3 mb-2">
                            <label class="d-flex gap-2 align-items-start mb-0">
                                <input type="radio" class="form-check-input mt-1" name="provider" value="{{ $key }}" x-model="provider">
                                <span class="flex-grow-1">
                                    <span class="fw-600">{{ $row['provider']->label() }}</span>
                                    @if($current === $key && $row['configured'])
                                        <span class="badge bg-soft-success text-success ms-1">Active</span>
                                    @elseif($current === $key)
                                        <span class="badge bg-soft-warning text-warning ms-1">Needs credentials</span>
                                    @endif
                                    <span class="d-block fs-8 text-muted">{{ $row['provider']->description() }}</span>
                                </span>
                            </label>
                            @if($row['provider']->fields())
                                <div class="row mt-3" x-show="provider === {{ Js::from($key) }}" x-cloak>
                                    @foreach($row['provider']->fields() as $field => $meta)
                                        <div class="col-md-6">
                                            @if($meta['secret'])
                                                <x-form.input name="{{ $key }}[{{ $field }}]" type="password" :label="$meta['label']" autocomplete="new-password"
                                                    :placeholder="$row['values'][$field] ? 'Saved '.$row['values'][$field].' · leave blank to keep' : ''" :help="$meta['help'] ?? null" />
                                            @else
                                                <x-form.input name="{{ $key }}[{{ $field }}]" :label="$meta['label']" :value="$row['values'][$field]" :help="$meta['help'] ?? null" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                    <div class="fs-8 text-muted mb-4">Keys are stored encrypted. Numbers are read in your workspace's country unless they start with + or 00.</div>

                    <h6 class="mb-3">Send automatically</h6>
                    @foreach($automations as $key => $automation)
                        <x-form.check name="notify[{{ $key }}]" :label="$automation['label']" :help="$automation['help']" :checked="(bool) $workspace->setting('sms.notify.'.$key)" switch />
                    @endforeach
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save SMS settings</button></div>
            </form>
        </div>

        <div class="col-lg-5">
            <form method="POST" action="{{ route('settings.sms.test') }}" class="card">
                @csrf
                <div class="card-header"><h5 class="card-title">Send a test message</h5></div>
                <div class="card-body">
                    @if($active)
                        <p class="fs-7 text-muted">Sends one short message through {{ $active->label() }} so you can check the sender ID and delivery.</p>
                        <x-form.input name="to" label="Mobile number" :value="$workspace->phone" placeholder="0771234567" required />
                    @else
                        <x-empty icon="message-square" title="SMS is off" text="Choose a provider and save its credentials, then send yourself a test." class="py-2" />
                    @endif
                </div>
                @if($active)
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-white"><x-icon name="send" /> Send test</button></div>
                @endif
            </form>
        </div>
    </div>
@endsection
