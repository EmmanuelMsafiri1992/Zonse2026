@if($list->isEmpty())
    <div class="card-body text-muted fs-7 py-2">No extra fields on {{ mb_strtolower($label) }} yet.</div>
@else
    <div class="list-group list-group-flush">
        @foreach($list as $field)
            <div class="list-group-item d-flex gap-2 align-items-center">
                <x-icon :name="\App\Models\CustomField::TYPES[$field->type]['icon'] ?? 'type'" class="zi text-muted" />
                <div class="flex-grow-1 min-w-0">
                    <a href="{{ route('settings.custom-fields.edit', $field) }}" class="fw-600 text-body">{{ $field->label }}</a>
                    @if($field->is_required)<span class="text-danger" title="Required">*</span>@endif
                    <div class="fs-8 text-muted text-truncate">{{ $field->typeLabel() }}@if($field->type === 'select') · {{ implode(', ', $field->options ?? []) }}@endif</div>
                </div>
                <form method="POST" action="{{ route('settings.custom-fields.move', $field) }}" class="d-flex gap-1">
                    @csrf
                    <button name="direction" value="up" class="btn btn-sm btn-white" title="Move up" @disabled($loop->first)><x-icon name="arrow-up" /></button>
                    <button name="direction" value="down" class="btn btn-sm btn-white" title="Move down" @disabled($loop->last)><x-icon name="arrow-down" /></button>
                </form>
            </div>
        @endforeach
    </div>
@endif
