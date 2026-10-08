@props(['title', 'sub' => null, 'crumbs' => []])
<div class="z-page-header">
    <div>
        @if($crumbs)
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    @foreach($crumbs as $label => $url)
                        @if(is_int($label))
                            <li class="breadcrumb-item active">{{ $url }}</li>
                        @else
                            <li class="breadcrumb-item"><a href="{{ $url }}">{{ $label }}</a></li>
                        @endif
                    @endforeach
                </ol>
            </nav>
        @endif
        <h1>{{ $title }}</h1>
        @if($sub)<div class="z-page-sub">{{ $sub }}</div>@endif
    </div>
    @if(trim($slot ?? ''))
        <div class="d-flex gap-2 flex-wrap">{{ $slot }}</div>
    @endif
</div>
