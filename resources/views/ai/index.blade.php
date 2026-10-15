@extends('layouts.app')
@section('title', 'Zonseob AI')
@section('content')
    <x-page-header title="Zonseob AI" sub="Claude for anything you need: writing, planning, explaining, translating. Paid for with tokens in your own currency.">
        @if($available)
            <a href="#buy-tokens" class="btn btn-white"><x-icon name="coins" /> Buy tokens</a>
        @endif
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8 order-lg-2">
            @if(! $available)
                <div class="card">
                    <div class="card-body">
                        <x-empty icon="bot" title="Zonseob AI is not switched on here yet"
                                 text="This Zonseob has no Claude key of its own, so there is nothing to ask or buy. Your workspace Assistant still works with your own AI account in assistant settings." />
                    </div>
                </div>
            @else
                @include('ai.partials.ask', ['action' => route('ai.store'), 'placeholder' => 'Ask anything, e.g. "Write a quotation email for a roof repair"', 'wallet' => $wallet])

                <h6 class="text-muted fs-7 mt-4 mb-2">Try asking</h6>
                <div class="d-flex flex-wrap gap-2">
                    @foreach($starters as $starter)
                        <form method="POST" action="{{ route('ai.store') }}">
                            @csrf
                            <input type="hidden" name="question" value="{{ $starter }}">
                            <button class="btn btn-sm btn-white" @disabled(! $wallet->canAsk())>{{ $starter }}</button>
                        </form>
                    @endforeach
                </div>

                <div class="mt-4" id="buy-tokens">
                    @include('ai.partials.buy')
                </div>
            @endif
        </div>
        <div class="col-lg-4 order-lg-1">
            @include('ai.partials.sidebar')
        </div>
    </div>
@endsection
