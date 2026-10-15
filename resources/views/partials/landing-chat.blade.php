{{-- Question-and-answer chat for visitors, fixed to the bottom-right of the public pages. --}}
<div class="z-chat" x-data="landingChat()" x-init="init()">
    <button type="button" class="z-chat-launch" x-show="!open" x-on:click="toggle()" aria-label="Ask a question">
        <x-icon name="message-circle" class="zi" /> <span>Ask a question</span>
    </button>

    <section class="z-chat-panel" x-show="open" x-cloak aria-label="Chat with {{ config('app.name') }}">
        <header class="z-chat-head">
            <div>
                <div class="fw-600">Ask {{ config('app.name') }}</div>
                <div class="fs-8 text-muted">Answers about plans, apps and paying. Not a person.</div>
            </div>
            <button type="button" class="btn btn-sm btn-link text-muted" x-on:click="reset()" title="Start again" aria-label="Start again"><x-icon name="rotate-ccw" class="zi zi-sm" /></button>
            <button type="button" class="btn btn-sm btn-link text-muted" x-on:click="toggle()" aria-label="Close"><x-icon name="x" class="zi zi-sm" /></button>
        </header>

        <div class="z-chat-body" x-ref="body">
            <div class="z-chat-msg from-bot">
                <div>Hello! Ask me anything about {{ config('app.name') }}: what it costs, which apps are there for your work, or how to pay.</div>
            </div>
            <template x-for="(m, i) in messages" :key="i">
                <div class="z-chat-msg" :class="m.role === 'user' ? 'from-me' : 'from-bot'">
                    <div x-html="m.html"></div>
                </div>
            </template>
            <div class="z-chat-msg from-bot" x-show="busy"><div class="text-muted">Thinking…</div></div>
            <div class="z-chat-suggest" x-show="!messages.length && !busy">
                @foreach(\App\Assistant\LandingChat::SUGGESTIONS as $suggestion)
                    <button type="button" x-on:click="ask({{ Js::from($suggestion) }})">{{ $suggestion }}</button>
                @endforeach
            </div>
        </div>

        <form class="z-chat-form" x-on:submit.prevent="ask(draft)">
            <input type="text" name="website" tabindex="-1" autocomplete="off" class="d-none" aria-hidden="true">
            <input type="text" class="form-control" x-model="draft" x-ref="input" maxlength="1000" placeholder="Type your question" :disabled="busy" aria-label="Your question" required>
            <button type="submit" class="btn btn-primary" :disabled="busy || draft.trim().length < 2">Send</button>
        </form>
        <div class="z-chat-foot fs-8 text-muted">
            Prefer a person? Email <a href="mailto:{{ config('mail.from.address') }}">{{ config('mail.from.address') }}</a>.
        </div>
    </section>
</div>

<script>
function landingChat() {
    const KEY = 'zonseob.chat';
    return {
        open: false, busy: false, draft: '', messages: [],
        init() {
            try { const saved = JSON.parse(sessionStorage.getItem(KEY) || 'null'); if (saved && Array.isArray(saved.messages)) { this.messages = saved.messages; this.open = !!saved.open; } } catch (e) {}
        },
        save() { try { sessionStorage.setItem(KEY, JSON.stringify({ messages: this.messages, open: this.open })); } catch (e) {} },
        toggle() { this.open = !this.open; this.save(); if (this.open) this.$nextTick(() => { this.$refs.input?.focus(); this.scroll(); }); },
        scroll() { this.$nextTick(() => { const b = this.$refs.body; if (b) b.scrollTop = b.scrollHeight; }); },
        escape(s) { return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])); },
        async ask(text) {
            text = (text || '').trim();
            if (text.length < 2 || this.busy) return;
            this.draft = '';
            this.messages.push({ role: 'user', html: this.escape(text) });
            this.busy = true; this.save(); this.scroll();
            try {
                const res = await fetch({{ Js::from(route('chat.store')) }}, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' },
                    body: JSON.stringify({ message: text }),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(res.status === 429 ? 'You are sending questions a little fast. Please wait a minute and try again.' : (data.message || 'Something went wrong.'));
                this.messages.push({ role: 'bot', html: data.html });
            } catch (e) {
                this.messages.push({ role: 'bot', html: this.escape(e.message || 'Something went wrong. Please try again.') });
            } finally {
                this.busy = false; this.save(); this.scroll();
                this.$nextTick(() => this.$refs.input?.focus());
            }
        },
        async reset() {
            this.messages = []; this.draft = ''; this.save();
            try { await fetch({{ Js::from(route('chat.destroy')) }}, { method: 'DELETE', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '' } }); } catch (e) {}
        },
    };
}
</script>
