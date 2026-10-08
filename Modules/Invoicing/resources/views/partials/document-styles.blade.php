{{-- Styles for the document itself. Kept to tables and floats so the PDF renderer lays it out the same as a browser. --}}
@php $accent = $design->color; $soft = $design->tint(0.9); @endphp
.doc { color: #323338; font-family: {!! ($pdf ?? false) ? '"DejaVu Sans", sans-serif' : '"Figtree", -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif' !!}; font-size: {{ ($pdf ?? false) ? '11px' : '14px' }}; line-height: 1.45; }
.doc table { width: 100%; border-collapse: collapse; }
.doc .head td { vertical-align: top; padding: 0; }
.doc .brand { color: #676879; font-size: .92em; }
.doc .brand img { max-height: 56px; max-width: 180px; margin-bottom: 6px; }
.doc .brand h1 { font-size: 1.45em; font-weight: 600; color: #323338; margin: 0 0 4px; }
.doc .doc-title { text-align: right; }
.doc .doc-title h2 { margin: 0; font-size: 2em; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: {{ $accent }}; }
.doc .doc-title .num { font-size: 1.05em; font-weight: 600; }
.doc .status { display: inline-block; margin-top: 6px; padding: 3px 10px; border-radius: 4px; font-size: .85em; background: #579bfc; color: #fff; }
.doc .status.paid, .doc .status.accepted { background: #00c875; } .doc .status.draft { background: #c4c4c4; } .doc .status.partial { background: #fdab3d; }
.doc .status.overdue, .doc .status.cancelled, .doc .status.rejected, .doc .status.expired { background: #df2f4a; }
.doc .parties { margin: 28px 0 20px; }
.doc .parties td { vertical-align: top; }
.doc .parties .to { width: 50%; padding-right: 20px; }
.doc h4 { margin: 0 0 6px; font-size: .78em; text-transform: uppercase; letter-spacing: .08em; color: #676879; }
.doc .meta { background: {{ $soft }}; border-radius: 8px; }
.doc .meta td { padding: 10px 12px; font-size: .92em; }
.doc .meta b { display: block; font-size: .8em; text-transform: uppercase; letter-spacing: .06em; color: #676879; font-weight: 600; }
.doc .lines { margin-top: 8px; }
.doc .lines th { text-align: left; font-size: .78em; text-transform: uppercase; letter-spacing: .06em; color: #676879; border-bottom: 2px solid #d0d4e4; padding: 8px 6px; }
.doc .lines td { padding: 9px 6px; border-bottom: 1px solid #e6e9ef; vertical-align: top; }
.doc .r, .doc .lines th.r { text-align: right; } .doc .b { font-weight: 600; }
.doc .totals-wrap { margin-top: 14px; }
.doc .totals-wrap > tbody > tr > td { vertical-align: top; padding: 0; }
.doc .totals td { padding: 5px 6px; }
.doc .totals .grand td { border-top: 2px solid #323338; font-size: 1.2em; font-weight: 700; }
.doc .totals .due td { background: {{ $soft }}; font-weight: 700; color: {{ $accent }}; }
.doc .pay-details { margin-top: 26px; padding: 12px 14px; border-left: 3px solid {{ $accent }}; background: {{ $soft }}; white-space: pre-line; font-size: .95em; }
.doc .notes { margin-top: 26px; font-size: .92em; color: #676879; }
.doc .notes h4 { color: #323338; }
.doc .notes p { margin: 0 0 12px; white-space: pre-line; }
.doc .signature { margin-top: 40px; }
.doc .signature td { width: 45%; border-top: 1px solid #323338; padding-top: 6px; font-size: .85em; color: #676879; }
.doc .signature .gap { width: 10%; border: 0; }
.doc .fiscal { width: 100%; margin-top: 20px; border: 1px solid #d0d4e4; border-radius: 6px; font-size: .85em; }
.doc .fiscal td { padding: 8px 10px; vertical-align: top; }
.doc .fiscal .fiscal-qr { width: 96px; }
.doc .fiscal h4 { margin: 0 0 4px; }
.doc .fiscal .muted { color: #676879; }
.doc .foot { margin-top: 28px; border-top: 1px solid #d0d4e4; padding-top: 10px; font-size: .85em; color: #676879; text-align: center; }
.doc-banner .head { background: {{ $accent }}; }
.doc-banner .head td { padding: 18px 20px; }
.doc-banner .brand, .doc-banner .brand h1, .doc-banner .doc-title h2, .doc-banner .doc-title .num { color: #fff; }
.doc-banner .brand img { background: #fff; padding: 4px; border-radius: 4px; }
.doc-minimal .doc-title h2 { color: #323338; }
.doc-minimal .meta, .doc-minimal .totals .due td, .doc-minimal .pay-details { background: none; }
.doc-minimal .meta { border: 1px solid #d0d4e4; }
.doc-minimal .totals .due td { color: #323338; border-top: 1px solid #d0d4e4; }
.doc-minimal .pay-details { border-left-color: #323338; }
.doc-minimal .status { background: none !important; color: #323338; border: 1px solid #323338; }
