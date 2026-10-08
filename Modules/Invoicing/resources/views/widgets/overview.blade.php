<div class="card h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon name="receipt" class="zi me-1" /> Sales</h5>
        <div class="d-flex gap-1">
            @can('create', \Modules\Invoicing\Models\Invoice::class)<a href="{{ route('invoices.create') }}" class="btn btn-sm btn-primary"><x-icon name="plus" class="zi zi-sm" /> Invoice</a>@endcan
            <a href="{{ route('invoices.index') }}" class="btn btn-sm btn-soft-primary">All</a>
        </div>
    </div>
    <div class="card-body pb-0">
        <div class="z-kpi-list row g-2">
            <div class="col-4"><div class="fs-8 text-muted text-uppercase">Outstanding</div><div class="fs-5 fw-600">{{ \App\Support\Money::format($outstanding) }}</div></div>
            <div class="col-4"><div class="fs-8 text-muted text-uppercase">Overdue</div><div class="fs-5 fw-600 {{ $overdue > 0 ? 'text-danger' : '' }}">{{ \App\Support\Money::format($overdue) }}</div></div>
            <div class="col-4"><div class="fs-8 text-muted text-uppercase">Collected this month</div><div class="fs-5 fw-600 text-success">{{ \App\Support\Money::format($collected) }}</div></div>
        </div>
    </div>
    @if($invoices->isEmpty())
        <div class="card-body"><x-empty icon="file-text" title="No invoices yet" text="Your latest invoices and their balances will appear here." class="py-2" /></div>
    @else
        <div class="list-group list-group-flush mt-3">
            @foreach($invoices as $inv)
                <a href="{{ route('invoices.show', $inv) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 fs-7 text-truncate">{{ $inv->number }} <span class="text-muted fw-normal">· {{ $inv->contact?->displayName() }}</span></span>
                        <span class="d-block fs-8 text-muted">Due {{ $inv->due_date->format('d M') }} · {{ $inv->money($inv->total) }}</span>
                    </span>
                    <x-pill :status="$inv->status">{{ $inv->statusLabel() }}</x-pill>
                </a>
            @endforeach
        </div>
    @endif
</div>
