@extends('layouts.app')
@section('title', 'Workspace paused')
@section('content')
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body text-center py-5">
                    <span class="z-avatar z-avatar-lg z-bg-warning rounded-3 mb-3 d-inline-flex"><x-icon name="pause" class="zi zi-lg" /></span>
                    <h3 class="mb-2">{{ $workspace->name }} is paused</h3>
                    <p class="text-muted mb-4">The plan for this workspace has not been paid, so its apps are closed for now. Nothing has been deleted. Ask {{ $workspace->owner?->name ?? 'the workspace owner' }} to pay for a plan and everything opens again straight away.</p>
                    <div class="d-flex justify-content-center gap-2 flex-wrap">
                        <a href="{{ route('profile.edit') }}" class="btn btn-white">Your profile</a>
                        <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-white">Sign out</button></form>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
