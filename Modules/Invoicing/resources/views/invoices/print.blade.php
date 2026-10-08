@extends('invoicing::layouts.print')
@section('title', 'Invoice '.$invoice->number)
@section('toolbar')
    @unless($public ?? false)
        <a href="{{ route('invoices.show', $invoice) }}" class="btn">Back to invoice</a>
    @endunless
@endsection
@section('notice')
    @if($public ?? false)
        @if(session('flash'))
            <div class="notice notice-{{ session('flash')['type'] ?? 'info' }}" role="status">{{ session('flash')['message'] }}</div>
        @endif
        @if(! empty($gateways))
            <div class="pay">
                <div>
                    <div class="pay-title">Pay {{ $invoice->money($invoice->balance) }} online</div>
                    <div class="pay-sub">Secure checkout. You'll come back here once it's done.</div>
                </div>
                <div class="pay-options">
                    @foreach($gateways as $key => $gateway)
                        <form method="POST" action="{{ route('invoices.public.pay', [$invoice->uuid, $key]) }}">
                            @csrf
                            <button class="btn btn-primary">Pay with {{ $gateway->label() }}</button>
                            <div class="pay-sub">{{ $gateway->methods() }}</div>
                        </form>
                    @endforeach
                </div>
            </div>
        @endif
    @endif
@endsection
@section('content')
    @include('invoicing::partials.document-print', ['document' => $invoice, 'kind' => 'invoice'])
@endsection
