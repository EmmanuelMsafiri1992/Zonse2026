@extends('layouts.app')
@section('title', $chat->title)
@section('content')
    <div class="z-ai">
        @include('ai.partials.sidebar')
        <div class="z-ai-main">
            @include('ai.partials.bar')
            <div class="z-ai-thread" id="ai-messages" x-data x-init="$el.scrollTop = $el.scrollHeight">
                @foreach($messages as $message)
                    @if($message->isFromUser())
                        <div class="z-ai-msg from-me"><div>{{ $message->content }}</div></div>
                    @else
                        <div class="z-ai-msg from-ai" x-data="{ copied: false }">
                            <div class="assistant-reply" x-ref="reply">{{ $message->html() }}</div>
                            <div class="z-ai-meta">
                                <span>{{ number_format($message->cost_tokens) }} tokens</span>
                                <button type="button" class="btn btn-sm btn-link text-muted p-0"
                                        @click="navigator.clipboard.writeText($refs.reply.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                    <x-icon name="copy" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                </button>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
            @if($available)
                @include('ai.partials.ask', ['action' => route('ai.ask', $chat), 'placeholder' => 'Ask a follow-up question', 'wallet' => $wallet])
            @endif
        </div>
    </div>
    @if($available)
        @push('modals')
            @include('ai.partials.buy')
        @endpush
    @endif
@endsection
