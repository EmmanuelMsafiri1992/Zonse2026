@extends('layouts.app')
@section('title', 'Zonseob AI')
@section('content')
    <div class="z-ai">
        @include('ai.partials.sidebar')
        <div class="z-ai-main">
            @include('ai.partials.bar')
            <div class="z-ai-thread">
                @if(! $available)
                    <x-empty icon="bot" title="Zonseob AI is not switched on here yet"
                             text="This Zonseob has no Claude key of its own, so there is nothing to ask or buy. Your workspace Assistant still works with your own AI account in assistant settings." />
                @else
                    <div class="z-ai-welcome">
                        <h2>What can I help you with?</h2>
                        <p>Ask anything: a quotation email, a lesson plan, a contract clause explained, a translation. Only you can see your chats.</p>
                        <div class="fs-8 text-muted text-uppercase mb-2">Try asking</div>
                        <div class="z-ai-starters">
                            @foreach($starters as $starter)
                                <form method="POST" action="{{ route('ai.store') }}">
                                    @csrf
                                    <input type="hidden" name="question" value="{{ $starter }}">
                                    <button type="submit" @disabled(! $wallet->canAsk())>{{ $starter }}</button>
                                </form>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
            @if($available)
                @include('ai.partials.ask', ['action' => route('ai.store'), 'placeholder' => 'Ask anything, e.g. "Write a quotation email for a roof repair"', 'wallet' => $wallet])
            @endif
        </div>
    </div>
    @if($available)
        @push('modals')
            @include('ai.partials.buy')
        @endpush
    @endif
@endsection
