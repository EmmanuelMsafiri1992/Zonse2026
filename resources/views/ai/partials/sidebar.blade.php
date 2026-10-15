{{-- Chat list for Zonseob AI. Expects $available, $chats, $model, $outputWeight; $chat when one is open. --}}
<aside class="z-ai-list">
    <div class="z-ai-list-head">
        <span>Your chats</span>
        <a href="{{ route('ai.index') }}" class="btn btn-sm btn-white"><x-icon name="plus" /> New</a>
    </div>
    <div class="z-ai-list-body">
        @if($chats->isEmpty())
            <div class="text-muted fs-7 p-3">No chats yet. Only you can see your chats.</div>
        @else
            @foreach($chats as $item)
                <a href="{{ route('ai.show', $item) }}" class="z-ai-chat {{ isset($chat) && $chat->is($item) ? 'is-current' : '' }}">
                    <b>{{ $item->title }}</b>
                    <small>{{ $item->last_message_at?->diffForHumans() }}</small>
                </a>
            @endforeach
        @endif
    </div>
    <div class="z-ai-list-foot">
        @if($available)
            Answers by Claude ({{ $model }}) through {{ config('app.name') }}'s own account, so you need no Anthropic account or foreign currency. An answer costs the tokens Claude reads plus {{ $outputWeight }} for every token it writes.
        @else
            Zonseob AI is not switched on here yet.
        @endif
        Zonseob AI cannot see your workspace data. Check facts and figures before relying on them.
    </div>
</aside>
