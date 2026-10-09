@php
    $family = $template->font === 'serif' ? "'DejaVu Serif', Georgia, 'Times New Roman', serif" : "'DejaVu Sans', 'Segoe UI', Arial, sans-serif";
    $color = $template->color;
@endphp
<style>
    @page { margin: 48px; }
    body.z-doc { font-family: {!! $family !!}; font-size: 12px; line-height: 1.55; color: #1f2933; margin: 0; }
    .z-frame { position: fixed; top: -24px; right: -24px; bottom: -24px; left: -24px; }
    .z-frame-simple { border: 1px solid {{ $color }}; }
    .z-frame-double { border: 5px double {{ $color }}; }
    .z-sheet { text-align: {{ $template->align }}; }
    .z-sheet-framed { padding: 16px 24px; }
    .z-letterhead { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
    .z-letterhead td { vertical-align: top; padding: 0; }
    .z-letterhead .z-business { font-size: 10px; color: #52606d; text-align: right; }
    .z-business-name { font-size: 14px; font-weight: bold; color: {{ $color }}; }
    .z-logo { max-height: 64px; max-width: 200px; }
    .z-centred-logo { text-align: center; margin-bottom: 16px; }
    .z-heading { font-size: {{ $template->kind === 'certificate' ? '30px' : '20px' }}; color: {{ $color }}; margin: 0 0 18px; font-weight: bold; letter-spacing: {{ $template->kind === 'certificate' ? '1px' : '0' }}; }
    .z-body p { margin: 0 0 10px; }
    .z-body h1 { font-size: 26px; margin: 8px 0 14px; color: #111; }
    .z-body h2 { font-size: 18px; margin: 8px 0 10px; }
    .z-body h3 { font-size: 14px; margin: 6px 0 8px; }
    .z-body ul, .z-body ol { margin: 0 0 10px; padding-left: 20px; text-align: left; }
    .z-signatures { width: 100%; border-collapse: separate; border-spacing: 24px 0; margin-top: 48px; }
    .z-signatures td { vertical-align: bottom; text-align: center; padding-top: 36px; }
    .z-signature-line { border-top: 1px solid #52606d; padding-top: 4px; font-size: 10px; color: #52606d; }
    .z-footer { margin-top: 32px; padding-top: 8px; border-top: 1px solid #e4e7eb; font-size: 9px; color: #7b8794; text-align: center; }
</style>
