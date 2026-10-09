@extends('layouts.app')
@section('title', $current ? $current->displayName().' · Inbox' : 'Inbox')
@section('content')
    <x-page-header title="Inbox" sub="Email, SMS, WhatsApp, Facebook and Instagram messages in one place." :crumbs="['Inbox']">
        @can('manage-workspace')
            <a href="{{ route('settings.inbox.index') }}" class="btn btn-white"><x-icon name="settings" /> Channels</a>
        @endcan
    </x-page-header>

    @if($channels->isEmpty())
        <div class="card">
            <div class="card-body">
                <x-empty icon="inbox" title="No channels connected yet" text="Connect your support email, SMS number, WhatsApp number or social pages, and every message lands here.">
                    @can('manage-workspace')<a href="{{ route('settings.inbox.index') }}" class="btn btn-primary">Connect a channel</a>@endcan
                </x-empty>
            </div>
        </div>
    @else
        <div class="row g-3">
            <div class="col-lg-4 {{ $current ? 'd-none d-lg-block' : '' }}">
                <div class="card">
                    <div class="card-header d-block">
                        <div class="d-flex flex-wrap gap-1 mb-2">
                            @foreach(\App\Http\Controllers\InboxController::VIEWS as $key => $label)
                                <a href="{{ route('inbox.index', array_filter(['view' => $key, 'channel' => $filters['channel'] ?? null])) }}" class="btn btn-sm {{ $view === $key ? 'btn-primary' : 'btn-white' }}">
                                    {{ $label }}@if(isset($counts[$key]) && $counts[$key] > 0) <span class="opacity-75">{{ $counts[$key] }}</span>@endif
                                </a>
                            @endforeach
                        </div>
                        <form method="GET" class="d-flex gap-2">
                            <input type="hidden" name="view" value="{{ $view }}">
                            <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" class="form-control form-control-sm" placeholder="Search name, number or text">
                            <select name="channel" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
                                <option value="">All channels</option>
                                @foreach($channels as $channel)
                                    <option value="{{ $channel->id }}" @selected((int) ($filters['channel'] ?? 0) === $channel->id)>{{ $channel->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    </div>
                    @if($conversations->isEmpty())
                        <div class="card-body"><x-empty icon="inbox" title="Nothing here" text="{{ $view === 'open' ? 'All caught up. New messages appear here.' : 'No conversations match.' }}" class="py-2" /></div>
                    @else
                        <div class="list-group list-group-flush">
                            @foreach($conversations as $conversation)
                                <a href="{{ route('inbox.show', [$conversation] + request()->only(['view', 'channel', 'q'])) }}"
                                   class="list-group-item list-group-item-action d-flex gap-2 {{ $current?->id === $conversation->id ? 'active' : '' }}">
                                    <span class="z-avatar z-avatar-soft rounded-circle flex-shrink-0"><x-icon :name="$conversation->channel?->driver()->icon() ?? 'inbox'" class="zi zi-sm" /></span>
                                    <span class="mw-0 flex-grow-1">
                                        <span class="d-flex align-items-center gap-2">
                                            <span class="text-truncate {{ $conversation->unread_count ? 'fw-700' : 'fw-600' }}">{{ $conversation->displayName() }}</span>
                                            <span class="ms-auto fs-8 {{ $current?->id === $conversation->id ? '' : 'text-muted' }} text-nowrap">{{ $conversation->last_message_at?->diffForHumans(short: true) }}</span>
                                        </span>
                                        <span class="d-block fs-8 text-truncate {{ $current?->id === $conversation->id ? '' : 'text-muted' }}">
                                            @if($conversation->subject){{ $conversation->subject }} · @endif{{ $conversation->last_message_preview }}
                                        </span>
                                        <span class="d-flex gap-1 mt-1 fs-8">
                                            <span class="{{ $current?->id === $conversation->id ? '' : 'text-muted' }}">{{ $conversation->channel?->name }}</span>
                                            @if($conversation->assignee)<span class="{{ $current?->id === $conversation->id ? '' : 'text-muted' }}">· {{ $conversation->assignee->name }}</span>@endif
                                            @if($conversation->unread_count)<span class="badge bg-primary ms-auto">{{ $conversation->unread_count }}</span>@endif
                                            @if(! $conversation->isOpen())<span class="z-pill z-pill-muted ms-auto">Closed</span>@endif
                                        </span>
                                    </span>
                                </a>
                            @endforeach
                        </div>
                        @if($conversations->hasPages())<div class="card-footer">{{ $conversations->links() }}</div>@endif
                    @endif
                </div>
            </div>

            <div class="col-lg-8">
                @if($current)
                    @include('inbox.partials.thread', ['conversation' => $current])
                @else
                    <div class="card d-none d-lg-block">
                        <div class="card-body"><x-empty icon="message-circle" title="Pick a conversation" text="Choose a conversation on the left to read it and reply." /></div>
                    </div>
                @endif
            </div>
        </div>
    @endif
@endsection
