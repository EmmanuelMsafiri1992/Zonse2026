{{-- A titled list for app logic cards. $rows: list<array{label: string, sub?: string, value?: string, href?: string, tone?: string}> --}}
<div class="card mb-3 h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon :name="$icon ?? 'list'" class="zi me-1" /> {{ $title }}</h5>
        @isset($link)<a href="{{ $link['href'] }}" class="btn btn-sm btn-soft-primary">{{ $link['label'] }}</a>@endisset
    </div>
    @if(empty($rows))
        <div class="card-body fs-7 text-muted">{{ $empty ?? 'Nothing to show.' }}</div>
    @else
        <div class="list-group list-group-flush">
            @foreach($rows as $row)
                @if(isset($row['href']))
                    <a href="{{ $row['href'] }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                @else
                    <div class="list-group-item d-flex align-items-center gap-2">
                @endif
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 fs-7 text-truncate">{{ $row['label'] }}</span>
                        @isset($row['sub'])<span class="d-block fs-8 text-muted text-truncate">{{ $row['sub'] }}</span>@endisset
                    </span>
                    @isset($row['value'])
                        @isset($row['tone'])<x-pill :status="$row['tone']">{{ $row['value'] }}</x-pill>@else<span class="fs-7 fw-600 text-nowrap">{{ $row['value'] }}</span>@endisset
                    @endisset
                {!! isset($row['href']) ? '</a>' : '</div>' !!}
            @endforeach
        </div>
    @endif
</div>
