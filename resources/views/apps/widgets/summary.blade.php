<div class="card h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon :name="$app->icon" class="zi me-1" /> {{ $app->name }}</h5>
        <div class="d-flex gap-1">
            @can('create', \App\Models\Record::class)
                @php $primary = $app->primaryEntity(); @endphp
                <a href="{{ route('apps.records.create', [$app->key, $primary->key]) }}" class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> {{ $primary->label }}</a>
            @endcan
            <a href="{{ route('apps.show', $app->key) }}" class="btn btn-sm btn-soft-primary">Open</a>
        </div>
    </div>
    <div class="card-body pb-0">
        <div class="row g-2">
            @foreach(array_slice($app->entities, 0, 3) as $entity)
                <div class="col-4">
                    <a href="{{ route('apps.records.index', [$app->key, $entity->key]) }}" class="text-reset text-decoration-none">
                        <div class="fs-8 text-muted text-uppercase text-truncate">{{ $entity->plural }}</div>
                        <div class="fs-5 fw-600">{{ number_format($counts[$entity->key] ?? 0) }}</div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>
    @if($recent->isEmpty())
        <div class="card-body"><x-empty :icon="$app->icon" title="Nothing recorded yet" class="py-2" /></div>
    @else
        <div class="list-group list-group-flush mt-3">
            @foreach($recent as $record)
                <a href="{{ $record->url() }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 fs-7 text-truncate">{{ $record->title }}</span>
                        <span class="d-block fs-8 text-truncate text-muted">{{ $record->number }} · {{ $record->definition()->label }} · {{ $record->created_at?->diffForHumans() }}</span>
                    </span>
                    <x-pill :status="$record->statusTone()">{{ $record->statusLabel() }}</x-pill>
                </a>
            @endforeach
        </div>
    @endif
</div>
