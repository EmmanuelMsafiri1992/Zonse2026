@extends('layouts.app')
@section('title', 'Assistant')
@section('content')
    <x-page-header title="Assistant" sub="Ask questions about your data, get drafts of emails and summaries of records.">
        @if($canConfigure)
            <a href="{{ route('settings.assistant.edit') }}" class="btn btn-white"><x-icon name="settings" /> Settings</a>
        @endif
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8 order-lg-2">
            @if(! $provider)
                <div class="card">
                    <div class="card-body">
                        <x-empty icon="sparkles" title="The assistant is not set up yet"
                                 :text="$canConfigure ? 'Choose a provider in assistant settings. Test mode is free and works without an AI account.' : 'Ask a workspace owner or admin to switch it on in assistant settings.'">
                            @if($canConfigure)
                                <a href="{{ route('settings.assistant.edit') }}" class="btn btn-primary">Set up the assistant</a>
                            @endif
                        </x-empty>
                    </div>
                </div>
            @else
                @if($provider->key() === 'test')
                    <div class="alert alert-info fs-7">Test mode: answers come from simple keyword matching, without an AI model. Choose Anthropic or OpenAI in settings for real conversations.</div>
                @endif
                @include('assistant.partials.ask', ['action' => route('assistant.store'), 'placeholder' => 'Ask anything about your workspace, e.g. "How much did we spend last month?"'])

                <h6 class="text-muted fs-7 mt-4 mb-2">Try asking</h6>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($starters as $starter)
                        <form method="POST" action="{{ route('assistant.store') }}">
                            @csrf
                            <input type="hidden" name="question" value="{{ $starter }}">
                            <button class="btn btn-sm btn-white">{{ $starter }}</button>
                        </form>
                    @endforeach
                </div>
            @endif
        </div>
        <div class="col-lg-4 order-lg-1">
            @include('assistant.partials.sidebar')
        </div>
    </div>
@endsection
