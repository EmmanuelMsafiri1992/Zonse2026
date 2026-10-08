@php $sfx = $b?->id ?? 'new'; @endphp
<div class="row">
    <div class="col-md-8"><x-form.input name="name" label="Branch name" :value="$b?->name" required id="b_name_{{ $sfx }}" /></div>
    <div class="col-md-4"><x-form.input name="code" label="Code" :value="$b?->code" placeholder="e.g. HRE-01" id="b_code_{{ $sfx }}" /></div>
</div>
<div class="row">
    <div class="col-md-6"><x-form.input name="phone" label="Phone" :value="$b?->phone" id="b_phone_{{ $sfx }}" /></div>
    <div class="col-md-6"><x-form.input name="email" label="Email" type="email" :value="$b?->email" id="b_email_{{ $sfx }}" /></div>
</div>
<x-form.input name="address" label="Address" :value="$b?->address" id="b_address_{{ $sfx }}" />
<x-form.input name="city" label="City" :value="$b?->city" id="b_city_{{ $sfx }}" />
<x-form.check name="is_default" label="Make this the default branch" :checked="(bool) $b?->is_default" id="b_default_{{ $sfx }}" switch />
@if($b)
    <x-form.check name="is_active" label="Active" :checked="(bool) $b->is_active" id="b_active_{{ $sfx }}" switch />
@endif
