@extends('layouts.public')
@section('title', 'Thank you')
@section('content')
    <div class="card">
        <div class="card-body p-4 text-center">
            <x-icon name="circle-check" class="zi zi-lg text-success mb-2" />
            <h1 class="h4 mb-1">Thank you</h1>
            <p class="text-muted mb-3" style="white-space: pre-line">{{ $form->success_message ?: 'We have received your answers. '.$publicWorkspace->name.' will be in touch.' }}</p>
            <a href="{{ route('forms.public.show', ['uuid' => $form->uuid, 'embed' => $embed ? 1 : null]) }}" class="fs-7">Send another</a>
        </div>
    </div>
@endsection
