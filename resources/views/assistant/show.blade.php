@extends('layouts.app')
@section('title', $conversation->title)
@section('content')
    <x-page-header :title="$conversation->title" sub="Assistant chat · only you can see it" :crumbs="['Assistant' => route('assistant.index'), $conversation->title]">
        <form method="POST" action="{{ route('assistant.destroy', $conversation) }}" onsubmit="return confirm('Delete this chat?')">
            @csrf @method('DELETE')
            <button class="btn btn-white text-danger"><x-icon name="trash-2" /> Delete</button>
        </form>
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8 order-lg-2">
            @if($record = $conversation->contextRecord())
                <div class="alert alert-light border fs-7">About <a href="{{ $record->url() }}">{{ $record->number }} · {{ $record->title }}</a></div>
            @endif

            <div class="d-grid gap-3 mb-3" id="assistant-messages">
                @foreach($messages as $message)
                    @if($message->role === 'user')
                        <div class="d-flex justify-content-end">
                            <div class="bg-primary text-white rounded-3 px-3 py-2" style="max-width:85%;white-space:pre-wrap">{{ $message->content }}</div>
                        </div>
                    @elseif($message->role === 'tool')
                        <div class="fs-8 text-muted"><x-icon name="search" /> {{ $toolLabel($message->tool_name) }}</div>
                    @elseif(filled($message->content))
                        <div class="d-flex" x-data="{ copied: false }">
                            <div class="card mb-0" style="max-width:92%">
                                <div class="card-body assistant-reply fs-7" x-ref="reply">{{ $message->html() }}</div>
                                <div class="card-footer py-1 d-flex justify-content-end">
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

            @if($conversation->status === 'thinking')
                <div class="card mb-3" id="assistant-thinking" data-status-url="{{ route('assistant.status', $conversation) }}">
                    <div class="card-body d-flex align-items-center gap-2 fs-7">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span>Looking that up…</span>
                    </div>
                </div>
                <script>
                    (function check(delay) {
                        setTimeout(() => fetch(document.getElementById('assistant-thinking').dataset.statusUrl, { headers: { Accept: 'application/json' } })
                            .then(response => response.json())
                            .then(data => data.status === 'thinking' ? check(2000) : window.zonseo.reload(@js(request()->url())))
                            .catch(() => check(5000)), delay);
                    })(1500);
                </script>
            @elseif($conversation->status === 'failed')
                <div class="card mb-3">
                    <div class="card-body">
                        <x-empty icon="triangle-alert" title="The assistant could not answer" :text="$conversation->error ?? 'Something went wrong.'" class="py-2" />
                        <form method="POST" action="{{ route('assistant.retry', $conversation) }}" class="text-center">@csrf<button class="btn btn-primary"><x-icon name="refresh-cw" /> Try again</button></form>
                    </div>
                </div>
            @endif

            @if($provider)
                @include('assistant.partials.ask', ['action' => route('assistant.ask', $conversation), 'placeholder' => 'Ask a follow-up question', 'disabled' => $conversation->status === 'thinking'])
            @endif
        </div>
        <div class="col-lg-4 order-lg-1">
            @include('assistant.partials.sidebar')
        </div>
    </div>
@endsection
