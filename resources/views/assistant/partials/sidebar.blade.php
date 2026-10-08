<div class="card mb-3">
    <div class="card-header">
        <h5 class="card-title">Your chats</h5>
        <a href="{{ route('assistant.index') }}" class="btn btn-sm btn-white"><x-icon name="plus" /> New</a>
    </div>
    @if($conversations->isEmpty())
        <div class="card-body fs-7 text-muted">No chats yet. Only you can see your chats.</div>
    @else
        <div class="list-group list-group-flush">
            @foreach($conversations as $item)
                @php $isCurrent = isset($conversation) && $conversation->is($item); @endphp
                <a href="{{ route('assistant.show', $item) }}" class="list-group-item list-group-item-action fs-7 {{ $isCurrent ? 'active' : '' }}">
                    <div class="text-truncate fw-600">{{ $item->title }}</div>
                    <div class="fs-8 {{ $isCurrent ? '' : 'text-muted' }}">
                        {{ $item->last_message_at?->diffForHumans() }}@if($item->status === 'failed') · could not answer @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</div>

<div class="card">
    <div class="card-body fs-8 text-muted">
        @if($provider)
            Answers by <strong>{{ $provider->label() }}</strong>. {{ $usage['questions'] }} of {{ number_format($limit) }} questions used this month.
        @else
            The assistant is not set up yet.
        @endif
        @if($canConfigure)
            <a href="{{ route('settings.assistant.edit') }}">Assistant settings</a>
        @endif
        <div class="mt-2">The assistant can only read your data. It cannot change, send or delete anything. Check figures before relying on them.</div>
    </div>
</div>
