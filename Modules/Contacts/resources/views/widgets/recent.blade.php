<div class="card h-100">
    <div class="card-header">
        <h5 class="card-title"><x-icon name="contact" class="zi me-1" /> Recent contacts</h5>
        <a href="{{ route('contacts.index') }}" class="btn btn-sm btn-soft-primary">All</a>
    </div>
    @if($contacts->isEmpty())
        <div class="card-body"><x-empty icon="contact" title="No contacts yet" class="py-2">
            @can('create', \Modules\Contacts\Models\Contact::class)<a href="{{ route('contacts.create') }}" class="btn btn-sm btn-primary">Add one</a>@endcan
        </x-empty></div>
    @else
        <div class="list-group list-group-flush">
            @foreach($contacts as $c)
                <a href="{{ route('contacts.show', $c) }}" class="list-group-item list-group-item-action d-flex align-items-center gap-2">
                    <span class="z-avatar z-avatar-sm z-avatar-soft">{{ $c->initials() }}</span>
                    <span class="mw-0 flex-grow-1">
                        <span class="d-block fw-600 text-truncate fs-7">{{ $c->displayName() }}</span>
                        <span class="d-block fs-8 text-muted text-truncate">{{ $c->email ?: $c->phone }}</span>
                    </span>
                    <x-pill :status="$c->type" />
                </a>
            @endforeach
        </div>
    @endif
</div>
