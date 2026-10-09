@props(['for', 'small' => false])
{{-- Print buttons for the workspace's document templates that fill in from this contact, payment or record. --}}
@php $documentTemplates = \App\Support\DocumentTemplates::availableFor($for); @endphp
@if($documentTemplates->isNotEmpty())
    @php $buttonClass = $small ? 'btn btn-sm btn-white' : 'btn btn-white'; @endphp
    @if($documentTemplates->count() === 1)
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
            </div>
        </div>
    @endif
@endif
