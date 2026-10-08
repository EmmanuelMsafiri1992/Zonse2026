@extends('invoicing::layouts.print')
@section('title', 'Quote '.$quote->number)
@section('toolbar')
    @unless($public ?? false)
        <a href="{{ route('quotes.show', $quote) }}" class="btn">Back to quote</a>
    @endunless
@endsection
@section('content')
    @include('invoicing::partials.document-print', ['document' => $quote, 'kind' => 'quote'])
@endsection
