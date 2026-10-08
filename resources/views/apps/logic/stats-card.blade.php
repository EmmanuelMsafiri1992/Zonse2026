{{-- Figures for app logic cards. $stats: list<array{label: string, value: string, tone?: string}> --}}
<div class="card mb-3 h-100">
    <div class="card-header"><h5 class="card-title"><x-icon :name="$icon ?? 'chart-column'" class="zi me-1" /> {{ $title }}</h5></div>
    <div class="card-body">
        <div class="row g-3">
            @foreach($stats as $stat)
                <div class="col-6 col-md-{{ max(3, intdiv(12, max(1, count($stats)))) }}">
                    <div class="fs-8 text-muted">{{ $stat['label'] }}</div>
                    <div class="fs-5 fw-700 {{ isset($stat['tone']) ? 'text-'.$stat['tone'] : '' }}">{{ $stat['value'] }}</div>
                </div>
            @endforeach
        </div>
        @isset($note)<div class="fs-8 text-muted mt-3">{{ $note }}</div>@endisset
    </div>
</div>
