@php $sections = app(\App\Registries\MenuRegistry::class)->visible(); @endphp
<aside class="z-sidebar">
    <a href="{{ route('dashboard') }}" class="z-brand">
        @if($brand['logo_url'])
            <img src="{{ $brand['logo_url'] }}" alt="" class="rounded-2" style="height:32px;max-width:120px;object-fit:contain">
        @elseif($brand['mark_url'])
            <img src="{{ $brand['mark_url'] }}" alt="" class="rounded-2 bg-white" style="width:32px;height:32px;padding:2px;object-fit:contain">
        @else
            <span class="z-brand-mark">{{ $brand['mark'] }}</span>
        @endif
        <span class="text-truncate">{{ $brand['name'] }}</span>
    </a>

    <nav class="z-nav">
        @foreach($sections as $section)
            <div class="z-nav-title">{{ $section['label'] }}</div>
            @foreach($section['items'] as $item)
                @if($item->hasChildren())
                    <div class="z-nav-group {{ $item->isActive() ? 'open' : '' }}" x-data="{ open: {{ $item->isActive() ? 'true' : 'false' }} }" :class="{ open }">
                        <a href="#" class="z-nav-link {{ $item->isActive() ? 'active' : '' }}" @click.prevent="open = !open">
                            {!! \App\Support\Icon::svg($item->icon) !!}
                            <span>{{ $item->label }}</span>
                            {!! \App\Support\Icon::svg('chevron-right', 'zi zi-sm z-caret') !!}
                        </a>
                        <div class="z-nav-sub">
                            @foreach($item->children as $child)
                                <a href="{{ $child->href() }}" class="z-nav-link {{ $child->isActive() ? 'active' : '' }}">
                                    {!! \App\Support\Icon::svg($child->icon, 'zi zi-sm') !!}
                                    <span>{{ $child->label }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ $item->href() }}" class="z-nav-link {{ $item->isActive() ? 'active' : '' }}">
                        {!! \App\Support\Icon::svg($item->icon) !!}
                        <span>{{ $item->label }}</span>
                        @if($item->badge)<span class="z-nav-badge {{ $item->badgeClass }}">{{ $item->badge }}</span>@endif
                    </a>
                @endif
            @endforeach
        @endforeach
    </nav>

    <div class="z-sidebar-foot">
        @if($workspace?->subscription)
            <div class="d-flex justify-content-between align-items-center">
                <span>{{ $workspace->plan()?->name ?? 'No plan' }} plan</span>
                @if($workspace->onTrial())
                    <span class="z-pill z-pill-trial">{{ $workspace->subscription->daysLeftInTrial() }}d trial</span>
                @endif
            </div>
        @endif
    </div>
</aside>
