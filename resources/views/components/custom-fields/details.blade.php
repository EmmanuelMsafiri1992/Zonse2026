@props(['record'])
@php $details = \App\Support\CustomFields::details($record); @endphp
@if($details !== [])
    <div class="card mb-3">
        <div class="card-header"><h5 class="card-title">More details</h5></div>
        <ul class="list-group list-group-flush fs-7">
            @foreach($details as $detail)
                <li class="list-group-item d-flex gap-3">
                    <span class="text-muted text-nowrap">{{ $detail['label'] }}</span>
                    <span class="ms-auto text-end text-break" @if($detail['type'] === 'textarea') style="white-space:pre-line" @endif>
                        @if($detail['type'] === 'url' && preg_match('#^https?://#i', $detail['value']))
                            <a href="{{ $detail['value'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $detail['value'] }}</a>
                        @elseif($detail['type'] === 'email')
                            <a href="mailto:{{ $detail['value'] }}">{{ $detail['value'] }}</a>
                        @else
                            {{ $detail['value'] }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
