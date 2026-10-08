@extends('layouts.public')
@section('title', 'Request received')
@section('content')
    <div class="card">
        <div class="card-body p-4 text-center">
            <x-icon name="circle-check" class="zi zi-lg text-success mb-2" />
            <h1 class="h4 mb-1">Thank you</h1>
            <p class="text-muted mb-0">We have received your request {{ $invoice->number }} for {{ $invoice->money($invoice->total) }}. {{ $publicWorkspace->name }} will email your invoice once it has been checked.</p>
        </div>
    </div>
    <div class="text-center mt-3"><a href="{{ route('public.show', $publicWorkspace) }}" class="fs-7">Back to {{ $publicWorkspace->name }}</a></div>
@endsection
