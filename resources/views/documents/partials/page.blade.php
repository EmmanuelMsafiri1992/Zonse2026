@if($template->border !== 'none')<div class="z-frame z-frame-{{ $template->border }}"></div>@endif
<div class="z-sheet {{ $template->border !== 'none' ? 'z-sheet-framed' : '' }}">
    @if($template->align === 'center')
        @if($logo)<div class="z-centred-logo"><img src="{{ $logo }}" alt="" class="z-logo"></div>@endif
        @if($template->kind !== 'certificate')<div class="z-business-name" style="margin-bottom: 16px">{{ $workspace->name }}</div>@endif
    @else
        <table class="z-letterhead">
            <tr>
                <td>@if($logo)<img src="{{ $logo }}" alt="" class="z-logo">@else<span class="z-business-name">{{ $workspace->name }}</span>@endif</td>
                <td class="z-business">
                    @if($logo)<div class="z-business-name">{{ $workspace->name }}</div>@endif
                    @foreach(array_filter([$workspace->address, $workspace->city, $workspace->phone, $workspace->email]) as $line)<div>{{ $line }}</div>@endforeach
                    @if($workspace->tax_number)<div>Tax no. {{ $workspace->tax_number }}</div>@endif
                </td>
            </tr>
        </table>
    @endif

    @if($heading)<div class="z-heading">{{ $heading }}</div>@endif

    <div class="z-body">{{ $body }}</div>

    @if($signatures)
        <table class="z-signatures">
            <tr>
                @foreach($signatures as $role)
                    <td style="width: {{ floor(100 / count($signatures)) }}%"><div class="z-signature-line">{{ $role }}</div></td>
                @endforeach
            </tr>
        </table>
    @endif

    @if($footer)<div class="z-footer">{{ $footer }}</div>@endif
</div>
