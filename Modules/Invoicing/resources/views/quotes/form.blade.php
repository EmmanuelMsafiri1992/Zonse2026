@extends('layouts.app')
@section('title', $quote->exists ? 'Edit '.$quote->number : 'New quote')
@section('content')
    <x-page-header :title="$quote->exists ? 'Edit '.$quote->number : 'New quote'"
                   sub="Send a priced proposal. Once accepted, turn it into an invoice in one click."
                   :crumbs="['Quotes' => route('quotes.index'), $quote->exists ? $quote->number : 'New']" />
    @include('invoicing::partials.document-form', ['document' => $quote, 'kind' => 'quote'])
@endsection
