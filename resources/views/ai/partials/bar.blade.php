{{-- Top bar of the Zonseob AI chat area. Expects $available, $wallet; $chat when one is open. --}}
<div class="z-ai-bar">
    <div class="z-ai-bar-title">
        @isset($chat)
            <b>{{ $chat->title }}</b>
            <small>Only you can see this chat</small>
        @else
            <b>Zonseob AI</b>
            <small>Claude for writing, planning, explaining and translating</small>
        @endisset
    </div>
    <div class="z-ai-balance" data-ai-balance="{{ $wallet->balance_tokens }}">
        <b>{{ number_format($wallet->balance_tokens) }}</b> tokens left
    </div>
    @if($available)
        <button type="button" class="btn btn-sm {{ $wallet->canAsk() ? 'btn-white' : 'btn-primary' }}" data-bs-toggle="modal" data-bs-target="#ai-buy"><x-icon name="coins" /> Buy tokens</button>
    @endif
    @isset($chat)
        <form method="POST" action="{{ route('ai.destroy', $chat) }}" onsubmit="return confirm('Delete this chat?')">
            @csrf @method('DELETE')
            <button class="btn btn-sm btn-white text-danger" title="Delete chat"><x-icon name="trash-2" /></button>
        </form>
    @endisset
</div>
@if($available && ! $wallet->canAsk())
    <div class="alert alert-warning rounded-0 border-0 border-bottom mb-0 fs-7">You have run out of tokens. Buy a pack to keep asking.</div>
@endif
