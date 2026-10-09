@extends('layouts.app')
@section('title', 'Inbox channels')
@section('content')
    <x-page-header title="Inbox channels" sub="Connect the email address, numbers and social pages your customers write to. Every message lands in one inbox." :crumbs="['Settings' => route('settings.workspace.edit'), 'Inbox channels']">
        <a href="{{ route('inbox.index') }}" class="btn btn-white"><x-icon name="inbox" /> Open inbox</a>
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-4">
            <form method="POST" action="{{ route('settings.inbox.store') }}" class="card" x-data="{ type: @js(old('type', array_key_first($drivers))) }">
                @csrf
                <div class="card-header"><h5 class="card-title mb-0">Add a channel</h5></div>
                <div class="card-body">
                    <label class="form-label">Type</label>
                    <div class="d-grid gap-2 mb-3">
                        @foreach($drivers as $key => $driver)
                            <label class="border rounded p-2 d-flex gap-2 align-items-start" style="cursor:pointer" :class="type === @js($key) ? 'border-primary' : ''">
                                <input type="radio" name="type" value="{{ $key }}" class="form-check-input mt-1" x-model="type">
                                <span>
                                    <span class="fw-600"><x-icon :name="$driver->icon()" class="zi zi-sm" /> {{ $driver->label() }}</span>
                                    <span class="d-block fs-8 text-muted">{{ $driver->description() }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('type')<div class="text-danger fs-8 mb-2">{{ $message }}</div>@enderror

                    <x-form.input name="name" label="Name" :value="old('name')" required help="What your team sees, e.g. Support email or Shop WhatsApp." />
                    @foreach($drivers as $key => $driver)
                        <div x-show="type === @js($key)" x-cloak>
                            <x-form.input name="address" :id="'add-address-'.$key" :label="$driver->addressLabel()" :value="old('address')" x-bind:disabled="type !== {{ Js::from($key) }}" />
                            @foreach($driver->fields() as $field => $meta)
                                <x-form.input :name="'credentials['.$key.']['.$field.']'" :label="$meta['label'].($meta['required'] ? '' : ' (optional)')" :type="$meta['secret'] ? 'password' : 'text'" :help="$meta['help'] ?? null" autocomplete="off" />
                            @endforeach
                        </div>
                    @endforeach
                    <x-form.check name="test_mode" label="Test mode" :checked="old('test_mode', true)" switch help="Replies are recorded but not sent, and you can send yourself pretend messages. Switch off once the keys are in." />
                </div>
                <div class="card-footer"><button class="btn btn-primary"><x-icon name="plus" /> Add channel</button></div>
            </form>
        </div>

        <div class="col-xl-8">
            @forelse($channels as $channel)
                @php $driver = $channel->driver(); @endphp
                <div class="card mb-4">
                    <div class="card-header gap-2">
                        <span class="z-avatar z-avatar-soft rounded-circle"><x-icon :name="$driver->icon()" class="zi zi-sm" /></span>
                        <div>
                            <h5 class="card-title mb-0">{{ $channel->name }}</h5>
                            <div class="fs-8 text-muted">{{ $driver->label() }}{{ $channel->address ? ' · '.$channel->address : '' }} · {{ $channel->conversations_count }} {{ Str::plural('conversation', $channel->conversations_count) }}</div>
                        </div>
                        <div class="ms-auto d-flex gap-1">
                            @if(! $channel->is_active)
                                <x-pill status="muted">Off</x-pill>
                            @elseif($channel->test_mode)
                                <x-pill status="warning">Test mode</x-pill>
                            @elseif($channel->isConfigured())
                                <x-pill status="success">Live</x-pill>
                            @else
                                <x-pill status="danger">Keys missing</x-pill>
                            @endif
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="mb-3 fs-7">
                            <label class="form-label">Webhook URL</label>
                            <div class="input-group mb-1">
                                <input type="text" class="form-control font-monospace fs-8" id="inbox-url-{{ $channel->id }}" value="{{ $channel->webhookUrl() }}" readonly>
                                <button type="button" class="btn btn-white" onclick="navigator.clipboard.writeText(document.getElementById('inbox-url-{{ $channel->id }}').value); this.textContent = 'Copied'"><x-icon name="copy" /></button>
                            </div>
                            <div class="fs-8 text-muted">{{ $driver->setupHelp() }}</div>
                            @if(in_array($channel->type, ['whatsapp', 'facebook', 'instagram'], true))
                                <label class="form-label mt-2">Verify token</label>
                                <div class="input-group">
                                    <input type="password" class="form-control font-monospace fs-8" id="inbox-token-{{ $channel->id }}" value="{{ $channel->token }}" readonly>
                                    <button type="button" class="btn btn-white" onclick="const f = document.getElementById('inbox-token-{{ $channel->id }}'); f.type = f.type === 'password' ? 'text' : 'password'">Show</button>
                                    <button type="button" class="btn btn-white" onclick="navigator.clipboard.writeText(document.getElementById('inbox-token-{{ $channel->id }}').value); this.textContent = 'Copied'"><x-icon name="copy" /></button>
                                </div>
                            @endif
                        </div>

                        <details @if($errors->any() && old('_channel') == $channel->id) open @endif>
                            <summary class="fw-600 mb-2" style="cursor:pointer">Edit settings</summary>
                            <form method="POST" action="{{ route('settings.inbox.update', $channel) }}">
                                @csrf @method('PUT')
                                <input type="hidden" name="_channel" value="{{ $channel->id }}">
                                <div class="row g-2">
                                    <div class="col-md-6"><x-form.input name="name" :id="'inbox-name-'.$channel->id" label="Name" :value="$channel->name" required /></div>
                                    <div class="col-md-6"><x-form.input name="address" :id="'inbox-address-'.$channel->id" :label="$driver->addressLabel()" :value="$channel->address" /></div>
                                    @foreach($driver->fields() as $field => $meta)
                                        <div class="col-md-6">
                                            @if($meta['secret'])
                                                <x-form.input :name="'credentials['.$channel->type.']['.$field.']'" :id="'inbox-'.$channel->id.'-'.$field" :label="$meta['label']" type="password" autocomplete="off" :placeholder="$channel->credential($field) ? 'Saved — leave blank to keep' : ''" :help="$meta['help'] ?? null" />
                                            @else
                                                <x-form.input :name="'credentials['.$channel->type.']['.$field.']'" :id="'inbox-'.$channel->id.'-'.$field" :label="$meta['label']" :value="$channel->credential($field)" :help="$meta['help'] ?? null" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex flex-wrap gap-4 mt-2">
                                    <x-form.check name="test_mode" label="Test mode" :checked="$channel->test_mode" switch :id="'inbox-test-'.$channel->id" />
                                    <x-form.check name="is_active" label="On" :checked="$channel->is_active" switch :id="'inbox-active-'.$channel->id" />
                                </div>
                                <div class="d-flex justify-content-between mt-2">
                                    <button class="btn btn-primary btn-sm">Save</button>
                                </div>
                            </form>
                            <form method="POST" action="{{ route('settings.inbox.destroy', $channel) }}" class="mt-2" onsubmit="return confirm('Remove {{ e($channel->name) }} and all its conversations?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-soft-danger"><x-icon name="trash-2" /> Remove channel</button>
                            </form>
                        </details>

                        @if($channel->test_mode && $channel->is_active)
                            <details class="mt-3">
                                <summary class="fw-600 mb-2" style="cursor:pointer">Send yourself a test message</summary>
                                <form method="POST" action="{{ route('settings.inbox.simulate', $channel) }}" class="row g-2">
                                    @csrf
                                    <div class="col-md-6"><x-form.input name="from" :id="'sim-from-'.$channel->id" :label="$channel->type === 'email' ? 'From email' : (in_array($channel->type, ['sms', 'whatsapp'], true) ? 'From number' : 'From user id')" required /></div>
                                    <div class="col-md-6"><x-form.input name="name" :id="'sim-name-'.$channel->id" label="Their name" /></div>
                                    <div class="col-12"><x-form.textarea name="body" :id="'sim-body-'.$channel->id" label="Message" rows="2" required /></div>
                                    <div class="col-12"><button class="btn btn-sm btn-white"><x-icon name="send" /> Receive it</button></div>
                                </form>
                            </details>
                        @endif
                    </div>
                </div>
            @empty
                <div class="card">
                    <div class="card-body"><x-empty icon="inbox" title="No channels yet" text="Add your first channel on the left. Start in test mode to try the inbox before connecting any provider." /></div>
                </div>
            @endforelse
        </div>
    </div>
@endsection
