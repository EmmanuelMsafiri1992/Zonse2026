@php $chosen = old('events', $endpoint?->events ?? []); @endphp
<div class="mb-3">
    <label class="form-label" for="webhook-url">Endpoint URL</label>
    <input type="url" class="form-control @error('url') is-invalid @enderror" id="webhook-url" name="url" maxlength="500" value="{{ old('url', $endpoint?->url) }}" placeholder="https://example.com/zonseo-webhook" required>
    @error('url')<div class="invalid-feedback">{{ $message }}</div>@enderror
</div>
<div class="mb-3">
    <label class="form-label" for="webhook-description">Description <span class="text-muted fw-normal">(optional)</span></label>
    <input type="text" class="form-control" id="webhook-description" name="description" maxlength="160" value="{{ old('description', $endpoint?->description) }}" placeholder="e.g. Accounting sync">
</div>
<label class="form-label">Send these events</label>
@error('events')<div class="text-danger fs-8 mb-1">{{ $message }}</div>@enderror
<div class="row g-1">
    @foreach(\App\Support\Webhooks::EVENTS as $event => $label)
        <div class="col-sm-6">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="events[]" value="{{ $event }}" id="event-{{ $endpoint?->id ?? 'new' }}-{{ $event }}" @checked(in_array($event, $chosen, true))>
                <label class="form-check-label fs-7" for="event-{{ $endpoint?->id ?? 'new' }}-{{ $event }}">{{ $label }} <code class="fs-8 text-muted">{{ $event }}</code></label>
            </div>
        </div>
    @endforeach
</div>
