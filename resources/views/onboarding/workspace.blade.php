@extends('layouts.onboarding')
@section('title', 'Your workspace')
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-body p-4 p-lg-5">
                    <h3 class="mb-1">Tell us about your workspace</h3>
                    <p class="text-muted mb-4">A workspace is your company, clinic, school, church, farm or just you. You can add more later.</p>

                    <form method="POST" action="{{ route('onboarding.store', 1) }}">
                        @csrf
                        <x-form.input name="name" label="Workspace / business name" :value="$workspace?->name" placeholder="e.g. Sunrise Dental, Mukuru Farm, Jane Moyo Consulting" required autofocus />
                        <x-form.select name="type" label="What kind of organisation is it?" :options="$types" :value="$workspace?->type ?? 'company'" required />
                        <div class="row">
                            <div class="col-md-6"><x-form.select name="country_code" label="Country" :options="$countries" :value="$workspace?->country_code ?? 'ZW'" required /></div>
                            <div class="col-md-6"><x-form.select name="currency_code" label="Main currency" :options="$currencies" :value="$workspace?->currency_code ?? 'USD'" required /></div>
                        </div>
                        <div class="row">
                            <div class="col-md-6"><x-form.select name="timezone" label="Time zone" :options="$timezones" :value="$workspace?->timezone ?? 'Africa/Harare'" required /></div>
                            <div class="col-md-6"><x-form.input name="phone" label="Phone (optional)" :value="$workspace?->phone" placeholder="+263 …" /></div>
                        </div>
                        <x-form.input name="email" label="Business email (optional)" type="email" :value="$workspace?->email ?? auth()->user()->email" />

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary btn-lg">Continue <x-icon name="arrow-right" /></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
