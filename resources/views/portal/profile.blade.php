@extends('layouts.portal')
@section('title', 'My details')
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-7">
            <h1 class="h4 mb-3">My details</h1>
            <div class="card">
                <form method="POST" action="{{ route('portal.profile.update', $portalWorkspace) }}" class="card-body">
                    @csrf
                    @method('PUT')
                    <div class="mb-3">
                        <div class="form-label">Name</div>
                        <div class="fw-600">{{ $contact->displayName() }}</div>
                        <div class="fs-8 text-muted">Signed in as {{ $portalAccess->email }}. Ask {{ $portalWorkspace->name }} to change your name or email.</div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6"><x-form.input name="phone" label="Phone" :value="$contact->phone" maxlength="40" /></div>
                        <div class="col-sm-6"><x-form.input name="mobile" label="Mobile" :value="$contact->mobile" maxlength="40" /></div>
                    </div>
                    <x-form.textarea name="address" label="Address" :value="$contact->address" rows="2" />
                    <x-form.input name="city" label="City" :value="$contact->city" maxlength="120" />
                    <button class="btn btn-primary">Save my details</button>
                </form>
            </div>
        </div>
    </div>
@endsection
