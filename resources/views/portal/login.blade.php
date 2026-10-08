@extends('layouts.portal')
@section('title', 'Sign in')
@section('content')
    <div class="row justify-content-center">
        <div class="col-md-7 col-lg-5">
            <div class="card">
                <div class="card-body p-4">
                    <h1 class="h4 mb-1">Sign in to your portal</h1>
                    <p class="text-muted fs-7">{{ $welcome ?? 'See your invoices, bookings, requests and documents with '.$portalWorkspace->name.'.' }}</p>
                    <form method="POST" action="{{ route('portal.login.send', $portalWorkspace) }}">
                        @csrf
                        <x-form.input name="email" type="email" label="Your email address" required autocomplete="email" autofocus />
                        <button class="btn btn-primary w-100"><x-icon name="mail" /> Email me a sign-in link</button>
                    </form>
                    <p class="fs-8 text-muted mt-3 mb-0">No password needed. We send a link that works once and expires after {{ \App\Models\PortalAccess::LINK_MINUTES }} minutes. Use the email address {{ $portalWorkspace->name }} has on file for you.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
