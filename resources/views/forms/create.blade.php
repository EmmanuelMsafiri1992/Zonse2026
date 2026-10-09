@extends('layouts.app')
@section('title', 'New form')
@section('content')
    <x-page-header title="New form" sub="Name the form and choose what each answer becomes. You pick the questions next." :crumbs="['Forms' => route('forms.index'), 'New']" />

    <div class="row">
        <div class="col-xl-6">
            @if($targets === [])
                <div class="alert alert-warning">Switch on Contacts, Helpdesk or an app first: a form needs somewhere to put its answers.</div>
            @else
                <form method="POST" action="{{ route('forms.store') }}" class="card">
                    @csrf
                    <div class="card-body">
                        <x-form.input name="name" label="Form name" required maxlength="120" placeholder="e.g. Newsletter sign-up" />
                        <x-form.select name="target" label="Each answer creates" :options="$targets" :value="$target" placeholder="Choose…" required
                                       help="Answers to contact forms update the person if their email is already on file." />
                    </div>
                    <div class="card-footer text-end">
                        <a href="{{ route('forms.index') }}" class="btn btn-white">Cancel</a>
                        <button class="btn btn-primary">Continue</button>
                    </div>
                </form>
            @endif
        </div>
    </div>
@endsection
