<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign {{ $signatureRequest->title }} · {{ $signatureRequest->workspace->name }}</title>
    @include('partials.fonts')
    @include('partials.favicon')
    <style>
        :root { --ink: #323338; --muted: #676879; --line: #d0d4e4; --brand: #0073ea; --soft: #e6f1fd; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eceff8; color: var(--ink); font: 14px/1.5 "Figtree", -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .wrap { max-width: 860px; margin: 0 auto; padding: 20px 16px 40px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 20px 24px; margin-bottom: 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; } h2 { font-size: 16px; margin: 0 0 12px; }
        .muted { color: var(--muted); font-size: 13px; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 4px; border: 1px solid var(--line); background: #fff; color: var(--ink); text-decoration: none; cursor: pointer; font: inherit; }
        .btn-primary { background: var(--brand); border-color: var(--brand); color: #fff; } .btn-primary:disabled { opacity: .5; cursor: not-allowed; }
        .btn-link { border: 0; background: none; color: #b91c1c; padding: 0; text-decoration: underline; }
        .notice { padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
        .notice-success { background: #ecfdf5; color: #047857; } .notice-info { background: var(--soft); color: #1e40af; } .notice-danger { background: #fef2f2; color: #b91c1c; }
        .viewer { width: 100%; height: 70vh; min-height: 420px; border: 1px solid var(--line); border-radius: 6px; background: #f6f7fb; }
        .tabs { display: flex; gap: 6px; margin-bottom: 10px; }
        .tabs button { padding: 6px 14px; border-radius: 4px; border: 1px solid var(--line); background: #fff; cursor: pointer; font: inherit; }
        .tabs button[aria-selected="true"] { background: var(--soft); border-color: var(--brand); color: var(--brand); }
        .pad { width: 100%; height: 180px; border: 1px dashed #9aa0b5; border-radius: 6px; background: #fff; touch-action: none; cursor: crosshair; display: block; }
        input[type=text], textarea { width: 100%; padding: 9px 12px; border: 1px solid var(--line); border-radius: 4px; font: inherit; }
        .typed { font-family: "Brush Script MT", "Segoe Script", "Lucida Handwriting", cursive; font-size: 34px; min-height: 64px; padding: 6px 4px; border-bottom: 1px solid var(--line); margin-top: 8px; }
        label.field { display: block; font-weight: 600; margin: 14px 0 6px; }
        .error { color: #b91c1c; font-size: 13px; margin-top: 6px; }
        .row { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .signer-list { list-style: none; padding: 0; margin: 0; } .signer-list li { display: flex; justify-content: space-between; padding: 6px 0; border-top: 1px solid #eef0f6; font-size: 13px; }
        details summary { cursor: pointer; color: var(--muted); font-size: 13px; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="row">
            <div>
                <div class="muted">{{ $signatureRequest->workspace->name }} asks you to sign</div>
                <h1>{{ $signatureRequest->title }}</h1>
                <div class="muted">For {{ $signer->name }} ({{ $signer->email }})</div>
            </div>
            <a href="{{ route('signing.document', $signer->token) }}" target="_blank" rel="noopener" class="btn">Open the PDF</a>
        </div>
        @if($signatureRequest->message)<p style="white-space:pre-line;margin:12px 0 0">“{{ $signatureRequest->message }}”</p>@endif
    </div>

    @if(session('flash'))
        <div class="notice notice-{{ session('flash')['type'] ?? 'info' }}" role="status">{{ session('flash')['message'] }}</div>
    @endif

    @if($signatureRequest->status === 'completed')
        <div class="card">
            <h2>Signed by everyone</h2>
            <p class="muted">Completed {{ $signatureRequest->completed_at->format('d M Y H:i') }}. Keep a copy of the document and its signing certificate.</p>
            <div class="row" style="justify-content:flex-start">
                <a href="{{ route('signing.document', $signer->token) }}" class="btn">Download the document</a>
                <a href="{{ route('signing.certificate', $signer->token) }}" class="btn btn-primary">Download the certificate</a>
            </div>
        </div>
    @elseif($signer->status === 'signed')
        <div class="notice notice-success">You signed on {{ $signer->signed_at->format('d M Y H:i') }}. We will email you a copy once everyone has signed.</div>
    @elseif($signer->status === 'declined')
        <div class="notice notice-danger">You declined to sign this document.</div>
    @elseif(! $signatureRequest->isPending() || $signatureRequest->isOverdue())
        <div class="notice notice-danger">This document can no longer be signed ({{ $signatureRequest->isOverdue() ? 'the deadline has passed' : strtolower($signatureRequest->statusLabel()) }}). Please contact {{ $signatureRequest->workspace->name }}.</div>
    @elseif(! $canSign)
        <div class="notice notice-info">It is not your turn yet. {{ $waitingOn ? $waitingOn->name.' signs first' : 'Others sign first' }}, then we will email you.</div>
    @endif

    <div class="card">
        <iframe src="{{ route('signing.document', $signer->token) }}" class="viewer" title="Document to sign"></iframe>
        <div class="muted" style="margin-top:6px">Can't see the document? <a href="{{ route('signing.document', $signer->token) }}" target="_blank" rel="noopener">Open the PDF</a> in a new tab.</div>
    </div>

    @if($canSign)
        <form method="POST" action="{{ route('signing.sign', $signer->token) }}" class="card" id="sign-form">
            @csrf
            <h2>Your signature</h2>
            <div class="tabs" role="tablist">
                <button type="button" role="tab" data-mode="draw" aria-selected="{{ old('signature_type', 'draw') === 'draw' ? 'true' : 'false' }}">Draw</button>
                <button type="button" role="tab" data-mode="type" aria-selected="{{ old('signature_type') === 'type' ? 'true' : 'false' }}">Type</button>
            </div>
            <input type="hidden" name="signature_type" id="signature-type" value="{{ old('signature_type', 'draw') }}">
            <input type="hidden" name="signature" id="signature-value">

            <div data-panel="draw">
                <canvas class="pad" id="pad" aria-label="Draw your signature here"></canvas>
                <div class="row" style="margin-top:6px"><span class="muted">Sign with your finger, stylus or mouse.</span><button type="button" class="btn" id="clear">Clear</button></div>
            </div>
            <div data-panel="type" hidden>
                <input type="text" id="typed-input" maxlength="120" placeholder="Type your full name" value="{{ old('signature_type') === 'type' ? old('signature') : $signer->name }}" autocomplete="name">
                <div class="typed" id="typed-preview"></div>
            </div>
            @error('signature')<div class="error">{{ $message }}</div>@enderror

            <label class="field" for="signed-name">Full name</label>
            <input type="text" name="signed_name" id="signed-name" maxlength="120" required value="{{ old('signed_name', $signer->name) }}" autocomplete="name">
            @error('signed_name')<div class="error">{{ $message }}</div>@enderror

            <label style="display:flex;gap:8px;align-items:flex-start;margin:16px 0">
                <input type="checkbox" name="agree" value="1" required style="margin-top:4px" @checked(old('agree'))>
                <span>I have read the document and agree that my electronic signature is the legal equivalent of my handwritten signature on it.</span>
            </label>
            @error('agree')<div class="error">{{ $message }}</div>@enderror

            <button class="btn btn-primary" id="submit-sign">Sign the document</button>
        </form>

        <div class="card">
            <details @if($errors->has('reason')) open @endif>
                <summary>I don't want to sign this</summary>
                <form method="POST" action="{{ route('signing.decline', $signer->token) }}" style="margin-top:10px" onsubmit="return confirm('Decline to sign? The sender will be told and the request will close.')">
                    @csrf
                    <textarea name="reason" rows="2" maxlength="1000" placeholder="Reason (optional, shared with the sender)">{{ old('reason') }}</textarea>
                    @error('reason')<div class="error">{{ $message }}</div>@enderror
                    <button class="btn" style="margin-top:8px;color:#b91c1c">Decline to sign</button>
                </form>
            </details>
        </div>
    @endif

    <div class="card">
        <h2>Signers</h2>
        <ul class="signer-list">
            @foreach($signatureRequest->signers as $person)
                <li><span>{{ $person->name }}</span><span class="muted">{{ $person->statusLabel() }}{{ $person->signed_at ? ' · '.$person->signed_at->format('d M Y') : '' }}</span></li>
            @endforeach
        </ul>
        <p class="muted" style="margin:12px 0 0">Document fingerprint (SHA-256): <code style="word-break:break-all">{{ $signatureRequest->document_hash }}</code></p>
    </div>
</div>

@if($canSign)
<script>
(function () {
    var form = document.getElementById('sign-form');
    var modeInput = document.getElementById('signature-type');
    var valueInput = document.getElementById('signature-value');
    var canvas = document.getElementById('pad');
    var ctx = canvas.getContext('2d');
    var typedInput = document.getElementById('typed-input');
    var typedPreview = document.getElementById('typed-preview');
    var drawn = false, drawing = false, last = null;

    function setMode(mode) {
        modeInput.value = mode;
        document.querySelectorAll('[data-mode]').forEach(function (tab) { tab.setAttribute('aria-selected', tab.dataset.mode === mode ? 'true' : 'false'); });
        document.querySelectorAll('[data-panel]').forEach(function (panel) { panel.hidden = panel.dataset.panel !== mode; });
        if (mode === 'draw') { resize(); }
    }
    function resize() {
        var ratio = Math.max(window.devicePixelRatio || 1, 1);
        var width = canvas.offsetWidth, height = canvas.offsetHeight;
        if (!width) { return; }
        var keep = drawn ? canvas.toDataURL() : null;
        canvas.width = Math.min(width * ratio, 2000);
        canvas.height = Math.min(height * ratio, 1000);
        ctx.setTransform(canvas.width / width, 0, 0, canvas.height / height, 0, 0);
        ctx.lineWidth = 2.2; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1f2a5a';
        if (keep) { var img = new Image(); img.onload = function () { ctx.drawImage(img, 0, 0, width, height); }; img.src = keep; }
    }
    function point(event) { var box = canvas.getBoundingClientRect(); return { x: event.clientX - box.left, y: event.clientY - box.top }; }

    canvas.addEventListener('pointerdown', function (event) { drawing = true; last = point(event); canvas.setPointerCapture(event.pointerId); event.preventDefault(); });
    canvas.addEventListener('pointermove', function (event) {
        if (!drawing) { return; }
        var next = point(event);
        ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(next.x, next.y); ctx.stroke();
        last = next; drawn = true;
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (name) { canvas.addEventListener(name, function () { drawing = false; }); });
    document.getElementById('clear').addEventListener('click', function () { ctx.clearRect(0, 0, canvas.width, canvas.height); drawn = false; });
    document.querySelectorAll('[data-mode]').forEach(function (tab) { tab.addEventListener('click', function () { setMode(tab.dataset.mode); }); });

    function preview() { typedPreview.textContent = typedInput.value; }
    typedInput.addEventListener('input', preview);
    preview();

    form.addEventListener('submit', function (event) {
        if (modeInput.value === 'draw') {
            if (!drawn) { event.preventDefault(); alert('Please draw your signature first.'); return; }
            valueInput.value = canvas.toDataURL('image/png');
        } else {
            if (!typedInput.value.trim()) { event.preventDefault(); typedInput.focus(); return; }
            valueInput.value = typedInput.value.trim();
        }
    });
    window.addEventListener('resize', function () { if (modeInput.value === 'draw') { resize(); } });
    setMode(modeInput.value);
})();
</script>
@endif
</body>
</html>
