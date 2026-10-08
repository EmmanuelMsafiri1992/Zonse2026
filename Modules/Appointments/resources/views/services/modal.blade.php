@php $sfx = $service?->id ?? 'new'; $isOld = old('_service', 'new') === (string) $sfx; @endphp
<div class="modal fade" id="serviceModal_{{ $sfx }}" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="POST" action="{{ $service ? route('services.update', $service) : route('services.store') }}">
            @csrf
            @if($service) @method('PUT') @endif
            <input type="hidden" name="_service" value="{{ $sfx }}">
            <div class="modal-header"><h5 class="modal-title">{{ $service ? 'Edit '.$service->name : 'New service' }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <x-form.input name="name" label="Name" :value="$isOld ? old('name') : $service?->name" id="s_name_{{ $sfx }}" required placeholder="e.g. Consultation" />
                <x-form.textarea name="description" label="Description" :value="$isOld ? old('description') : $service?->description" rows="2" id="s_desc_{{ $sfx }}" />
                <div class="row">
                    <div class="col-md-6"><x-form.input name="duration_minutes" type="number" min="5" max="1440" step="5" label="Duration (minutes)" :value="$isOld ? old('duration_minutes') : ($service?->duration_minutes ?? 30)" id="s_dur_{{ $sfx }}" required /></div>
                    <div class="col-md-6"><x-form.input name="price" type="number" step="0.01" min="0" label="Price ({{ $workspace->currency_code }})" :value="$isOld ? old('price') : ($service?->price ?? 0)" id="s_price_{{ $sfx }}" required /></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Colour on the calendar</label>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($colors as $color)
                            <label class="position-relative" title="{{ $color }}">
                                <input type="radio" name="color" value="{{ $color }}" class="form-check-input position-absolute opacity-0" @checked(($isOld ? old('color') : ($service?->color ?? $colors[0])) === $color)>
                                <span class="d-inline-block rounded-circle border border-2 border-white shadow-sm" style="width:26px;height:26px;background:{{ $color }};outline:2px solid {{ ($isOld ? old('color') : ($service?->color ?? $colors[0])) === $color ? $color : 'transparent' }};outline-offset:2px"></span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @if($service)<x-form.check name="is_active" label="Active (bookable)" :checked="$isOld ? (bool) old('is_active') : (bool) $service->is_active" id="s_active_{{ $sfx }}" switch />@endif
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><x-icon name="check" /> {{ $service ? 'Save changes' : 'Add service' }}</button>
            </div>
        </form>
    </div>
</div>
