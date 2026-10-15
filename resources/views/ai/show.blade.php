@extends('layouts.app')
@section('title', $chat->title)
@section('content')
    <x-page-header :title="$chat->title" sub="Zonseob AI chat · only you can see it" :crumbs="['Zonseob AI' => route('ai.index'), $chat->title]">
        <form method="POST" action="{{ route('ai.destroy', $chat) }}" onsubmit="return confirm('Delete this chat?')">
            @csrf @method('DELETE')
            <button class="btn btn-white text-danger"><x-icon name="trash-2" /> Delete</button>
        </form>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8 order-lg-2">
            <div class="d-grid gap-3 mb-3" id="ai-messages">
                @foreach($messages as $message)
                    @if($message->isFromUser())
                        <div class="d-flex justify-content-end">
                            <div class="bg-primary text-white rounded-3 px-3 py-2" style="max-width:85%;white-space:pre-wrap">{{ $message->content }}</div>
                        </div>
                    @else
                        <div class="d-flex" x-data="{ copied: false }">
                            <div class="card mb-0" style="max-width:92%">
                                <div class="card-body assistant-reply fs-7" x-ref="reply">{{ $message->html() }}</div>
                                <div class="card-footer py-1 d-flex justify-content-between align-items-center">
                                    <span class="fs-8 text-muted">{{ number_format($message->cost_tokens) }} tokens</span>
                                    <button type="button" class="btn btn-sm btn-link text-muted p-0"
                                            @click="navigator.clipboard.writeText($refs.reply.innerText).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                                        <x-icon name="copy" /> <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            @if($available)
                @include('ai.partials.ask', ['action' => route('ai.ask', $chat), 'placeholder' => 'Ask a follow-up question', 'wallet' => $wallet])
            @endif
        </div>
        <div class="col-lg-4 order-lg-1">
            @include('ai.partials.sidebar')
        </div>
    </div>
@endsection
