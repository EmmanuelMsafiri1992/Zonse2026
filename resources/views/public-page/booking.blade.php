@extends('layouts.public')
@section('title', 'Book an appointment')
@section('content')
    <h1 class="h4 mb-3">Book an appointment</h1>

    <div class="card mb-3">
        <form method="GET" action="{{ route('public.booking', $publicWorkspace) }}" class="card-body">
            <div class="row g-2">
                <div class="col-sm-7">
                    <x-form.select name="service" label="Service" :options="$services->mapWithKeys(fn ($s) => [$s->id => $s->name.' · '.$s->durationLabel().($s->price > 0 ? ' · '.\App\Support\Money::format($s->price, $publicWorkspace->currency_code) : '')])->all()" :value="$service?->id" placeholder="Choose a service" required />
                </div>
                <div class="col-sm-5">
                    <x-form.input name="date" type="date" label="Day" :value="$day->format('Y-m-d')" min="{{ $first->format('Y-m-d') }}" max="{{ $last->format('Y-m-d') }}" required />
                </div>
            </div>
            <button class="btn btn-white"><x-icon name="search" /> Show free times</button>
        </form>
    </div>

    @if($service)
        @if($slots === [])
            <div class="card"><div class="card-body"><x-empty icon="calendar-x" title="No free times on {{ $day->format('l d M') }}" text="Please try another day." class="py-2" /></div></div>
        @else
            <form method="POST" action="{{ route('public.booking.store', $publicWorkspace) }}" class="card">
                @csrf
                <input type="hidden" name="service_id" value="{{ $service->id }}">
                <input type="hidden" name="date" value="{{ $day->format('Y-m-d') }}">
                <div class="card-body">
                    <div class="form-label">Free times on {{ $day->format('l d M Y') }}</div>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @foreach($slots as $slot)
                            <input type="radio" class="btn-check" name="time" id="time_{{ str_replace(':', '', $slot) }}" value="{{ $slot }}" @checked(old('time') === $slot) required>
                            <label class="btn btn-white btn-sm" for="time_{{ str_replace(':', '', $slot) }}">{{ $slot }}</label>
                        @endforeach
                    </div>
                    @error('time')<div class="text-danger fs-7 mb-2">{{ $message }}</div>@enderror
                    @include('public-page.partials.visitor')
                    <x-form.textarea name="notes" label="Anything we should know?" rows="2" maxlength="1000" />
                    @if($note)<div class="alert alert-info fs-8">{{ $note }}</div>@endif
                    <button class="btn btn-primary"><x-icon name="calendar-check" /> Book {{ $service->name }}</button>
                </div>
            </form>
        @endif
    @endif
@endsection
