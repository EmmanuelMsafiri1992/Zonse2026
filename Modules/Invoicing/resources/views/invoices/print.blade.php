@extends('invoicing::layouts.print')
@section('title', 'Invoice '.$invoice->number)
@section('toolbar')
    @unless($public ?? false)
        <a href="{{ route('invoices.show', $invoice) }}" class="btn">Back to invoice</a>
    @endunless
@endsection
@section('content')
    @include('invoicing::partials.document-print', ['document' => $invoice, 'kind' => 'invoice'])
@endsection
