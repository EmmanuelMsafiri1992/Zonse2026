<form method="POST" action="{{ $action }}" class="card" x-data="{ question: {{ Js::from(old('question', '')) }} }">
    @csrf
    <div class="card-body">
        <label for="assistant-question" class="visually-hidden">Your question</label>
        <textarea name="question" id="assistant-question" rows="2" maxlength="{{ \App\Http\Controllers\AssistantController::MAX_QUESTION }}" required x-model="question"
                  class="form-control {{ $errors->has('question') ? 'is-invalid' : '' }}" placeholder="{{ $placeholder }}"
                  @keydown.enter="if (! $event.shiftKey) { $event.preventDefault(); if (question.trim()) { $el.form.requestSubmit(); } }" @disabled($disabled ?? false)></textarea>
        @error('question')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="d-flex justify-content-between align-items-center mt-2">
            <span class="fs-8 text-muted">Enter to send · Shift+Enter for a new line</span>
            <button class="btn btn-primary" @disabled($disabled ?? false)><x-icon name="send" /> Ask</button>
        </div>
    </div>
</form>
