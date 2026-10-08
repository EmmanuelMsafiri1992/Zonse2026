@props(['status'])
<span {{ $attributes->merge(['class' => 'z-pill z-pill-'.\Illuminate\Support\Str::slug($status, '_')]) }}>{{ $slot->isEmpty() ? str_replace('_', ' ', $status) : $slot }}</span>
