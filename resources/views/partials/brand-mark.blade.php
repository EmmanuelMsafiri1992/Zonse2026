@if($brand['logo_url'])
    <img src="{{ $brand['logo_url'] }}" alt="" class="rounded-3 bg-white" style="height:36px;max-width:120px;object-fit:contain">
@else
    <span class="{{ $class }}" style="width:36px;height:36px;display:grid;place-items:center">{{ $brand['mark'] }}</span>
@endif
