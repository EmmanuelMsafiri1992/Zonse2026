@if(session('flash'))
    <div data-flash="{{ session('flash')['message'] ?? '' }}" data-flash-type="{{ session('flash')['type'] ?? 'success' }}"></div>
@endif
@if(session('status'))
    <div data-flash="{{ is_string(session('status')) ? ucfirst(str_replace('-', ' ', session('status'))) : 'Done' }}" data-flash-type="success"></div>
@endif
@if($errors->any())
    <div class="alert alert-danger d-flex gap-2 align-items-start">
        <x-icon name="alert-circle" class="zi mt-1" />
        <div>
            <strong>Please fix the following:</strong>
            <ul class="mb-0 ps-3">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    </div>
@endif
