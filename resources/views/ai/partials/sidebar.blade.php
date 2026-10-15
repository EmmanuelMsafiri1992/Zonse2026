{{-- Balance and chat list for Zonseob AI. Expects $available, $wallet, $chats, $model, $outputWeight; $chat when one is open. --}}
<div class="card mb-3">
    <div class="card-header"><h5 class="card-title">Your tokens</h5></div>
    <div class="card-body">
        <div class="fs-3 fw-600" data-ai-balance="{{ $wallet->balance_tokens }}">{{ number_format($wallet->balance_tokens) }}</div>
        <div class="fs-8 text-muted mb-2">tokens left · {{ number_format($wallet->used_tokens) }} used so far</div>
        @if($available)
            @if(! $wallet->canAsk())
                <div class="alert alert-warning fs-8 py-2 mb-2">You have run out of tokens. Buy a pack to keep asking.</div>
            @endif
            <a href="{{ route('ai.index') }}#buy-tokens" class="btn btn-sm btn-primary w-100"><x-icon name="coins" /> Buy tokens</a>
        @endif
    </div>
</div>

<div class="card mb-3">
    <div class="card-header">
        <h5 class="card-title">Your chats</h5>
        <a href="{{ route('ai.index') }}" class="btn btn-sm btn-white"><x-icon name="plus" /> New</a>
    </div>
    @if($chats->isEmpty())
        <div class="card-body fs-7 text-muted">No chats yet. Only you can see your chats.</div>
    @else
        <div class="list-group list-group-flush">
            @foreach($chats as $item)
                @php $isCurrent = isset($chat) && $chat->is($item); @endphp
                <a href="{{ route('ai.show', $item) }}" class="list-group-item list-group-item-action fs-7 {{ $isCurrent ? 'active' : '' }}">
                    <div class="text-truncate fw-600">{{ $item->title }}</div>
                    <div class="fs-8 {{ $isCurrent ? '' : 'text-muted' }}">{{ $item->last_message_at?->diffForHumans() }}</div>
                </a>
            @endforeach
        </div>
    @endif
</div>

<div class="card">
    <div class="card-body fs-8 text-muted">
        @if($available)
            Answers by <strong>Claude</strong> ({{ $model }}) through {{ config('app.name') }}'s own account, so you need no Anthropic account or foreign currency.
            An answer costs the tokens Claude reads plus {{ $outputWeight }} for every token it writes. A short question and answer is usually a few hundred tokens.
        @else
            Zonseob AI is not switched on here yet.
        @endif
        <div class="mt-2">Zonseob AI cannot see your workspace data. Check facts and figures before relying on them.</div>
    </div>
</div>
