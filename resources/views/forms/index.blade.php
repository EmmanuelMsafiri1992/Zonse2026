@extends('layouts.app')
@section('title', 'Forms')
@section('content')
    <x-page-header title="Forms" sub="Build sign-up, enquiry and request forms. Share the link or put the form on your website, and every answer lands here as a record.">
        <a href="{{ route('forms.create') }}" class="btn btn-primary"><x-icon name="plus" /> New form</a>
    </x-page-header>

    <div class="card">
        @if($forms->isEmpty())
            <div class="card-body">
                <x-empty icon="clipboard-list" title="No forms yet"
                         text="Make a form for newsletter sign-ups, support requests or new patient registration. Visitors fill it in without an account." />
            </div>
        @else
            <div class="z-table-wrap">
                <table class="table z-table align-middle mb-0">
                    <thead><tr><th>Form</th><th>Creates</th><th>Answers</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach($forms as $form)
                        <tr>
                            <td class="z-row-title">
                                <a href="{{ route('forms.show', $form) }}">{{ $form->name }}</a>
                                <div class="fs-8 text-muted">{{ count($form->fields ?? []) }} {{ \Illuminate\Support\Str::plural('question', count($form->fields ?? [])) }}</div>
                            </td>
                            <td class="fs-7">{{ $form->targetLabel() }}</td>
                            <td class="fs-7">{{ number_format($form->submissions_count) }}@if($form->last_submitted_at)<div class="fs-8 text-muted">Last {{ $form->last_submitted_at->diffForHumans() }}</div>@endif</td>
                            <td><x-pill :status="$form->is_active ? 'active' : 'inactive'">{{ $form->is_active ? 'Taking answers' : 'Closed' }}</x-pill></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
