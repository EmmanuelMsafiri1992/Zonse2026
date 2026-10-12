@props(['for', 'small' => false])
{{-- Print buttons for the workspace's document templates that fill in from this contact, payment or record, and sending them for signing. --}}
@php
    $documentTemplates = \App\Support\DocumentTemplates::availableFor($for);
    $workspace = app(\App\Tenancy\WorkspaceContext::class)->get();
    $canSign = $workspace && auth()->user()?->roleIn($workspace) !== null && auth()->user()->roleIn($workspace) !== 'viewer';
@endphp
@if($documentTemplates->isNotEmpty())
    @php $buttonClass = $small ? 'btn btn-sm btn-white' : 'btn btn-white'; @endphp
    @if($documentTemplates->count() === 1 && ! $canSign)
        <a href="{{ route('documents.show', [$documentTemplates->first(), $for->getKey()]) }}" target="_blank" rel="noopener" class="{{ $buttonClass }}" data-no-ajax data-no-prefetch
           title="Print {{ $documentTemplates->first()->name }}"><x-icon name="printer" :class="$small ? 'zi zi-sm' : 'zi'" />@unless($small) {{ $documentTemplates->first()->name }} @endunless</a>
    @else
        <div class="dropdown">
            <button type="button" class="{{ $buttonClass }} dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Print a document">
                <x-icon name="printer" :class="$small ? 'zi zi-sm' : 'zi'" />@unless($small) Print @endunless
            </button>
            <div class="dropdown-menu dropdown-menu-end shadow-z">
                @foreach($documentTemplates as $documentTemplate)
                    <a href="{{ route('documents.show', [$documentTemplate, $for->getKey()]) }}" target="_blank" rel="noopener" class="dropdown-item" data-no-ajax data-no-prefetch>{{ $documentTemplate->name }}</a>
                @endforeach
                @if($canSign)
                    <div class="dropdown-divider"></div>
                    <h6 class="dropdown-header">Send for signing</h6>
                    @foreach($documentTemplates as $documentTemplate)
                        <a href="{{ route('signatures.create', ['template' => $documentTemplate->id, 'subject' => $for->getKey()]) }}" class="dropdown-item">{{ $documentTemplate->name }}</a>
                    @endforeach
                @endif
            </div>
        </div>
    @endif
@endif
