@extends('layouts.public')
@section('title', $form->title)
@section('content')
    <div class="card">
        <div class="card-body p-4">
            <h1 class="h4 mb-1">{{ $form->title }}</h1>
            @if($form->intro)<p class="text-muted mb-4" style="white-space: pre-line">{{ $form->intro }}</p>@endif

            @if(! $open)
                <div class="alert alert-secondary mb-0 mt-3">This form is not taking answers at the moment.</div>
            @else
                @error('started')<div class="alert alert-danger fs-7">{{ $message }}</div>@enderror
                <form method="POST" action="{{ route('forms.public.store', ['uuid' => $form->uuid, 'embed' => $embed ? 1 : null]) }}" class="mt-3">
                    <div class="position-absolute" style="left: -10000px" aria-hidden="true">
                        <label for="f_website">Leave this empty</label>
                        <input type="text" name="website" id="f_website" tabindex="-1" autocomplete="off">
                    </div>
                    <input type="hidden" name="started" value="{{ $started }}">

                    @foreach($fields as $field)
                        @switch($field['type'])
                            @case('textarea')
                                <x-form.textarea :name="$field['input']" :label="$field['label']" :help="$field['help']" :required="$field['required']" rows="4" maxlength="5000" />
                                @break
                            @case('select')
                                <x-form.select :name="$field['input']" :label="$field['label']" :options="$field['options']" :help="$field['help']" :required="$field['required']" placeholder="Choose…" />
                                @break
                            @case('checkbox')
                                <x-form.check :name="$field['input']" :label="$field['label']" :help="$field['help']" />
                                @break
                            @default
                                <x-form.input :name="$field['input']" :label="$field['label']" :help="$field['help']" :required="$field['required']"
                                              :type="['email' => 'email', 'phone' => 'tel', 'number' => 'number', 'money' => 'number', 'date' => 'date', 'datetime' => 'datetime-local', 'time' => 'time', 'url' => 'url'][$field['type']] ?? 'text'"
                                              :step="match ($field['type']) { 'number' => 'any', 'money' => '0.01', default => null }"
                                              :maxlength="in_array($field['type'], ['text', 'email', 'phone', 'url'], true) ? 190 : null" />
                        @endswitch
                    @endforeach

                    <button class="btn btn-primary w-100 mt-2">Send</button>
                </form>
            @endif
        </div>
    </div>
@endsection
