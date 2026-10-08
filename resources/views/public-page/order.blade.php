@extends('layouts.public')
@section('title', 'Order online')
@section('content')
    <h1 class="h4 mb-3">Order online</h1>
    <form method="POST" action="{{ route('public.order.store', $publicWorkspace) }}">
        @csrf
        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Item</th><th class="text-end">Price</th><th style="width:110px">Qty</th></tr></thead>
                    <tbody>
                        @foreach($items as $item)
                            <tr>
                                <td><span class="fw-600">{{ $item->name }}</span>@if($item->description)<span class="d-block fs-8 text-muted">{{ $item->description }}</span>@endif</td>
                                <td class="text-end text-nowrap">{{ \App\Support\Money::format($item->price, $publicWorkspace->currency_code) }}@if($item->unit)<span class="fs-8 text-muted"> / {{ $item->unit }}</span>@endif</td>
                                <td>
                                    <input type="number" name="quantities[{{ $item->id }}]" value="{{ old('quantities.'.$item->id, 0) }}" min="0" max="{{ $item->tracksStock() ? (int) $item->stock_qty : 999 }}" class="form-control form-control-sm @error('quantities.'.$item->id) is-invalid @enderror" aria-label="Quantity of {{ $item->name }}">
                                    @error('quantities.'.$item->id)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @error('quantities')<div class="card-body pt-2 pb-2 text-danger fs-7">{{ $message }}</div>@enderror
        </div>
        <div class="card">
            <div class="card-body">
                @include('public-page.partials.visitor')
                <x-form.textarea name="notes" label="Delivery or collection notes" rows="2" maxlength="1000" />
                <button class="btn btn-primary"><x-icon name="shopping-bag" /> Place order</button>
                <p class="fs-8 text-muted mt-2 mb-0">You get an invoice straight away and can pay it online.</p>
            </div>
        </div>
    </form>
@endsection
