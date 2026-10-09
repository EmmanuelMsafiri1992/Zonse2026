@extends('layouts.app')
@section('title', 'Notifications')
@section('content')
    <x-page-header title="Notifications" sub="Work assigned to you, notes on your work, payments and team changes in this workspace.">
        <a href="{{ route('profile.edit') }}#notifications" class="btn btn-white"><x-icon name="sliders-horizontal" /> Choose what I get</a>
        @if($unreadCount)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="btn btn-primary"><x-icon name="check-check" /> Mark all read</button>
            </form>
        @endif
    </x-page-header>

    <div class="card">
        <div class="card-header gap-2">
            <div class="btn-group btn-group-sm">
                <a href="{{ route('notifications.index') }}" class="btn {{ $unreadOnly ? 'btn-white' : 'btn-soft-primary' }}">All</a>
                <a href="{{ route('notifications.index', ['unread' => 1]) }}" class="btn {{ $unreadOnly ? 'btn-soft-primary' : 'btn-white' }}">Unread ({{ $unreadCount }})</a>
            </div>
        </div>

        @if($notifications->isEmpty())
            <div class="card-body">
                <x-empty icon="bell" title="You're all caught up" text="{{ $unreadOnly ? 'No unread notifications.' : 'When work is assigned to you or a payment comes in, it will show up here.' }}" />
            </div>
        @else
            <div class="list-group list-group-flush">
                @foreach($notifications as $alert)
                    <div class="list-group-item d-flex gap-3 align-items-start {{ $alert->read_at ? '' : 'z-unread' }}">
                        <x-icon :name="$alert->data['icon'] ?? 'bell'" class="zi zi-lg text-primary mt-1 flex-shrink-0" />
                        <a href="{{ route('notifications.open', $alert->id) }}" data-no-prefetch class="flex-grow-1 min-w-0 text-reset text-decoration-none">
                            <div class="{{ $alert->read_at ? '' : 'fw-600' }}">{{ $alert->data['title'] ?? '' }}</div>
                            @if(! empty($alert->data['body']))
                                <div class="fs-7 text-muted">{{ $alert->data['body'] }}</div>
                            @endif
                            <div class="fs-8 text-muted mt-1" title="{{ $alert->created_at->toDayDateTimeString() }}">
                                {{ \App\Support\Notifier::KINDS[$alert->type]['label'] ?? 'Notification' }} · {{ $alert->created_at->diffForHumans() }}
                            </div>
                        </a>
                        <form method="POST" action="{{ route('notifications.destroy', $alert->id) }}">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-link text-muted p-1" aria-label="Remove notification"><x-icon name="x" /></button>
                        </form>
                    </div>
                @endforeach
            </div>
            @if($notifications->hasPages())
                <div class="card-footer">{{ $notifications->links() }}</div>
            @endif
        @endif
    </div>
@endsection
