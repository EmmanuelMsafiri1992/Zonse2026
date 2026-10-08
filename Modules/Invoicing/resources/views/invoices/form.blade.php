@extends('layouts.app')
@section('title', $invoice->exists ? 'Edit '.$invoice->number : 'New invoice')
@section('content')
    <x-page-header :title="$invoice->exists ? 'Edit '.$invoice->number : 'New invoice'"
                   sub="Bill a customer for products or services. Totals update as you type."
                   :crumbs="['Invoices' => route('invoices.index'), $invoice->exists ? $invoice->number : 'New']" />
    @include('invoicing::partials.document-form', ['document' => $invoice, 'kind' => 'invoice'])
@endsection
