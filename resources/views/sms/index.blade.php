@extends('layouts.app')
@section('title', 'Text messages')
@section('content')
    <x-page-header title="Text messages" sub="Every SMS sent from this workspace, and a quick way to send more." :crumbs="['Text messages']">
        <a href="{{ route('settings.sms.edit') }}" class="btn btn-white"><x-icon name="settings" /> SMS settings</a>
    </x-page-header>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><x-stat label="Sent this month" :value="$stats['sent']" icon="send" color="primary" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Message parts this month" :value="$stats['segments']" icon="layers" color="info" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Failed this month" :value="$stats['failed']" icon="circle-alert" color="danger" :href="route('sms.index', ['status' => 'failed'])" /></div>
        <div class="col-6 col-xl-3"><x-stat label="Provider" :value="$provider?->label() ?? 'Off'" icon="radio-tower" color="purple" :href="route('settings.sms.edit')" /></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4 order-lg-2">
            <form method="POST" action="{{ route('sms.store') }}" class="card" x-data="{ audience: {{ Js::from(old('audience', 'number')) }}, body: {{ Js::from(old('body', '')) }} }">
                @csrf
                <div class="card-header"><h5 class="card-title">New message</h5></div>
                @if($provider)
                    <div class="card-body">
                        <x-form.select name="audience" label="Send to" :options="$audiences" value="number" x-model="audience" />
                        <div x-show="audience === 'number'">
                            <x-form.input name="to" label="Mobile number" placeholder="0771234567" />
                        </div>
                        <div class="fs-8 text-muted mb-3" x-show="audience !== 'number'" x-cloak>Contacts without a usable number are skipped. At most {{ \App\Http\Controllers\SmsController::MAX_RECIPIENTS }} recipients per send.</div>
                        <x-form.textarea name="body" label="Message" rows="5" maxlength="{{ \App\Sms\SmsService::MAX_LENGTH }}" x-model="body" required />
                        <div class="fs-8 text-muted mt-n2" x-text="body.length + ' / {{ \App\Sms\SmsService::MAX_LENGTH }} characters · ' + (body.length <= 160 ? 1 : Math.ceil(body.length / 153)) + ' part(s) per recipient'"></div>
                    </div>
                    <div class="card-footer d-flex justify-content-end">
                        <button class="btn btn-primary" x-on:click="if (audience !== 'number' && ! confirm('Send this message to everyone in that group?')) $event.preventDefault()"><x-icon name="send" /> Send</button>
                    </div>
                @else
                    <div class="card-body">
                        <x-empty icon="message-square" title="SMS is not set up" text="Choose a provider (or Test mode) to start sending.">
                            <a href="{{ route('settings.sms.edit') }}" class="btn btn-primary">Set up SMS</a>
                        </x-empty>
                    </div>
                @endif
            </form>
        </div>

        <div class="col-lg-8 order-lg-1">
            <div class="card">
                <div class="card-header flex-wrap gap-2">
                    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                        <select name="purpose" class="form-select w-auto" onchange="this.form.submit()">
                            <option value="">All messages</option>
                            @foreach(\App\Models\SmsMessage::PURPOSES as $key => $label)
                                <option value="{{ $key }}" @selected(($filters['purpose'] ?? null) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <select name="status" class="form-select w-auto" onchange="this.form.submit()">
                            <option value="">Any status</option>
                            @foreach(\App\Models\SmsMessage::STATUSES as $key => $label)
                                <option value="{{ $key }}" @selected(($filters['status'] ?? null) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @if($filters)
                            <a href="{{ route('sms.index') }}" class="btn btn-link btn-sm text-muted">Clear</a>
                        @endif
                    </form>
                    <span class="fs-8 text-muted">{{ $messages->total() }} {{ \Illuminate\Support\Str::plural('message', $messages->total()) }}</span>
                </div>

                @if($messages->isEmpty())
                    <div class="card-body"><x-empty icon="message-square" title="No messages yet" text="Messages you send, and automatic reminders and receipts, appear here." /></div>
                @else
                    <div class="z-table-wrap">
                        <table class="table z-table align-middle">
                            <thead><tr><th>To</th><th>Message</th><th>Type</th><th>Status</th><th>When</th></tr></thead>
                            <tbody>
                            @foreach($messages as $message)
                                <tr>
                                    <td class="fs-7 text-nowrap">
                                        <span class="z-row-title d-block">{{ $message->contact?->displayName() ?? $message->to }}</span>
                                        @if($message->contact)<span class="z-row-sub">{{ $message->to }}</span>@endif
                                    </td>
                                    <td class="fs-7" style="min-width:220px">
                                        {{ \Illuminate\Support\Str::limit($message->body, 120) }}
                                        @if($message->error)<div class="fs-8 text-danger">{{ $message->error }}</div>@endif
                                    </td>
                                    <td class="fs-8 text-nowrap">{{ $message->purposeLabel() }}@if($message->provider === 'test')<span class="d-block text-muted">Test mode</span>@endif</td>
                                    <td><x-pill :status="$message->status">{{ $message->statusLabel() }}</x-pill></td>
                                    <td class="fs-8 text-muted text-nowrap" title="{{ $message->created_at }}">{{ $message->created_at->diffForHumans() }}@if($message->sender)<span class="d-block">by {{ $message->sender->name }}</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($messages->hasPages())
                        <div class="card-footer">{{ $messages->links() }}</div>
                    @endif
                @endif
            </div>
        </div>
    </div>
@endsection
