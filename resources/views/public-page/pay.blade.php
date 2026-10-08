@extends('layouts.public')
@section('title', 'Make a payment')
@section('content')
    <h1 class="h4 mb-3">Make a payment to {{ $publicWorkspace->name }}</h1>
    <form method="POST" action="{{ route('public.payment.store', $publicWorkspace) }}" class="card">
        @csrf
        <div class="card-body">
            <div class="row">
                <div class="col-sm-5"><x-form.input name="amount" type="number" step="0.01" min="1" max="1000000" label="Amount ({{ $publicWorkspace->currency_code }})" required /></div>
                <div class="col-sm-7"><x-form.input name="purpose" label="What is it for?" maxlength="150" placeholder="e.g. Deposit for the March booking" required /></div>
            </div>
            @include('public-page.partials.visitor')
            <button class="btn btn-primary"><x-icon name="credit-card" /> Continue to payment</button>
        </div>
    </form>
@endsection
