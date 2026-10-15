{{-- Composer for Zonseob AI, pinned under the thread. Expects $action, $placeholder, $wallet. --}}
<form method="POST" action="{{ $action }}" class="z-ai-compose" x-data="{ question: {{ Js::from(old('question', '')) }}, busy: false }" x-on:submit="busy = true">
    @csrf
    <label for="ai-question" class="visually-hidden">Your question</label>
    <textarea name="question" id="ai-question" rows="2" maxlength="{{ \App\Assistant\ZonseobAi::MAX_QUESTION }}" required x-model="question"
              class="form-control {{ $errors->has('question') ? 'is-invalid' : '' }}" placeholder="{{ $placeholder }}"
              @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); if (question.trim() && ! busy) { $el.form.requestSubmit(); } }" @disabled(! $wallet->canAsk())></textarea>
    @error('question')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    <div class="z-ai-compose-foot">
        <span class="fs-8 text-muted">
            <span x-show="!busy">Enter to send · Shift+Enter for a new line</span>
            <span x-show="busy" x-cloak><span class="spinner-border spinner-border-sm text-primary me-1" role="status"></span> Claude is writing…</span>
        </span>
        @if($wallet->canAsk())
            <button class="btn btn-primary" :disabled="busy"><x-icon name="send" /> Ask</button>
        @else
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ai-buy"><x-icon name="coins" /> Buy tokens to ask</button>
        @endif
    </div>
</form>
