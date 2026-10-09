@extends('layouts.app')
@section('title', $form->name)
@section('content')
    <x-page-header :title="$form->name" :sub="'Each answer creates: '.$form->targetLabel().'.'" :crumbs="['Forms' => route('forms.index'), $form->name]">
        <a href="{{ $form->publicUrl() }}" class="btn btn-white" target="_blank" rel="noopener"><x-icon name="external-link" /> Open form</a>
        <a href="{{ route('forms.edit', $form) }}" class="btn btn-primary"><x-icon name="pencil" /> Edit questions</a>
    </x-page-header>

    @unless($available)
        <div class="alert alert-warning">The app this form fills in is switched off, so the form shows as closed to visitors.</div>
    @endunless

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Answers <span class="text-muted fw-normal fs-7">({{ number_format($form->submissions_count) }})</span></h5></div>
                @if($submissions->isEmpty())
                    <div class="card-body"><x-empty icon="inbox" title="No answers yet" text="Share the link or embed the form on your website. Each answer shows here and in the record it creates." /></div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($submissions as $submission)
                            @php $url = \App\Support\WebForms::subjectUrl($submission->subject); @endphp
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between gap-2 mb-1">
                                    <span class="fs-8 text-muted">{{ $submission->created_at->format('d M Y H:i') }}</span>
                                    @if($url)<a href="{{ $url }}" class="fs-8">Open record</a>@else<span class="fs-8 text-muted">Record deleted</span>@endif
                                </div>
                                <dl class="row mb-0 fs-7">
                                    @foreach($submission->data as $answer)
                                        <dt class="col-sm-4 fw-normal text-muted">{{ $answer['label'] }}</dt>
                                        <dd class="col-sm-8 mb-1 text-break">{{ $answer['value'] ?? '—' }}</dd>
                                    @endforeach
                                </dl>
                            </div>
                        @endforeach
                    </div>
                    @if($submissions->hasPages())<div class="card-footer">{{ $submissions->links() }}</div>@endif
                @endif
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title mb-0">Share</h5></div>
                <div class="card-body">
                    <p class="mb-2"><x-pill :status="$form->is_active ? 'active' : 'inactive'">{{ $form->is_active ? 'Taking answers' : 'Closed' }}</x-pill></p>
                    <label class="form-label fs-7" for="form-link">Link</label>
                    <input type="text" id="form-link" class="form-control form-control-sm mb-3" value="{{ $form->publicUrl() }}" readonly onclick="this.select()">
                    <label class="form-label fs-7" for="form-embed">Put it on your website</label>
                    <textarea id="form-embed" class="form-control form-control-sm font-monospace fs-8" rows="4" readonly onclick="this.select()">{{ $form->embedCode() }}</textarea>
                    <div class="form-text">Paste this where the form should appear on your site.</div>
                </div>
            </div>
            <form method="POST" action="{{ route('forms.destroy', $form) }}" onsubmit="return confirm('Delete this form? Records it already created are kept.')">
                @csrf
                @method('DELETE')
                <button class="btn btn-sm btn-white text-danger"><x-icon name="trash-2" /> Delete form</button>
            </form>
        </div>
    </div>
@endsection
