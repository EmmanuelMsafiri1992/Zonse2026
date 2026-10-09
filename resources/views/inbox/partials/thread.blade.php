@php $channel = $conversation->channel; @endphp
<div class="card mb-3">
    <div class="card-header flex-wrap gap-2">
        <a href="{{ route('inbox.index', request()->only(['view', 'channel', 'q'])) }}" class="btn btn-sm btn-white d-lg-none"><x-icon name="arrow-left" /></a>
        <div class="mw-0">
            <h5 class="card-title mb-0 text-truncate">{{ $conversation->displayName() }}</h5>
            <div class="fs-8 text-muted text-truncate">
                <x-icon :name="$channel?->driver()->icon() ?? 'inbox'" class="zi zi-sm" /> {{ $channel?->name }} · {{ $conversation->handle }}
                @if($conversation->subject) · {{ $conversation->subject }}@endif
            </div>
        </div>
        <div class="ms-auto d-flex gap-2">
            <form method="POST" action="{{ route('inbox.update', $conversation) }}">
                @csrf @method('PATCH')
                @if($conversation->isOpen())
                    <input type="hidden" name="status" value="closed">
                    <button class="btn btn-sm btn-white"><x-icon name="check" /> Close</button>
                @else
                    <input type="hidden" name="status" value="open">
                    <button class="btn btn-sm btn-white"><x-icon name="rotate-ccw" /> Reopen</button>
                @endif
            </form>
        </div>
    </div>
    <div class="card-body bg-body-tertiary" style="max-height:60vh;overflow-y:auto" id="inbox-thread">
        <div class="d-grid gap-2">
            @foreach($conversation->messages as $message)
                @if($message->isIncoming())
                    <div class="d-flex">
                        <div class="bg-white border rounded-3 px-3 py-2" style="max-width:85%">
                            <div style="white-space:pre-wrap">{{ $message->body }}</div>
                            <div class="fs-8 text-muted mt-1">{{ $message->created_at->format('d M, H:i') }}</div>
                        </div>
                    </div>
                @else
                    <div class="d-flex justify-content-end">
                        <div class="{{ $message->status === 'failed' ? 'bg-danger-subtle border border-danger' : 'bg-primary text-white' }} rounded-3 px-3 py-2" style="max-width:85%">
                            <div style="white-space:pre-wrap">{{ $message->body }}</div>
                            <div class="fs-8 mt-1 {{ $message->status === 'failed' ? 'text-danger' : 'opacity-75' }}">
                                {{ $message->sender?->name ?? 'Team' }} · {{ $message->created_at->format('d M, H:i') }} · {{ $message->statusLabel() }}
                                @if($message->status === 'sent' && str_starts_with((string) $message->external_id, 'test-')) (test mode)@endif
                            </div>
                            @if($message->error)<div class="fs-8 text-danger">{{ $message->error }}</div>@endif
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
    <form method="POST" action="{{ route('inbox.reply', $conversation) }}" class="card-footer d-block">
        @csrf
        @if(! $channel?->is_active)
            <div class="alert alert-warning fs-7 mb-2">This channel is switched off, so replies cannot be sent.</div>
        @elseif($channel->test_mode)
            <div class="fs-8 text-muted mb-2"><x-icon name="flask-conical" class="zi zi-sm" /> Test mode: replies are recorded here but not sent.</div>
        @endif
        <textarea name="body" rows="3" class="form-control mb-2 @error('body') is-invalid @enderror" placeholder="Write a reply to {{ $conversation->displayName() }}…" maxlength="{{ \App\Inbox\Inbox::MAX_LENGTH }}" required @disabled(! $channel?->is_active)>{{ old('body') }}</textarea>
        @error('body')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
        <div class="d-flex justify-content-end">
            <button class="btn btn-primary" @disabled(! $channel?->is_active)><x-icon name="send" /> Send reply</button>
        </div>
    </form>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title mb-0">Assigned to</h6></div>
            <div class="card-body">
                <form method="POST" action="{{ route('inbox.update', $conversation) }}" class="d-flex gap-2">
                    @csrf @method('PATCH')
                    <select name="assigned_to" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Nobody</option>
                        @foreach($members as $member)
                            <option value="{{ $member->id }}" @selected($conversation->assigned_to === $member->id)>{{ $member->name }}{{ $member->id === auth()->id() ? ' (me)' : '' }}</option>
                        @endforeach
                    </select>
                    <noscript><button class="btn btn-sm btn-white">Save</button></noscript>
                </form>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><h6 class="card-title mb-0">Contact</h6></div>
            <div class="card-body">
                @if($conversation->contact)
                    <div class="d-flex align-items-center gap-2">
                        <x-icon name="user" />
                        @if(Route::has('contacts.show'))
                            <a href="{{ route('contacts.show', $conversation->contact) }}" class="fw-600">{{ $conversation->contact->displayName() }}</a>
                        @else
                            <span class="fw-600">{{ $conversation->contact->displayName() }}</span>
                        @endif
                        <form method="POST" action="{{ route('inbox.update', $conversation) }}" class="ms-auto">
                            @csrf @method('PATCH')
                            <input type="hidden" name="contact_id" value="">
                            <button class="btn btn-sm btn-link text-muted p-0">Unlink</button>
                        </form>
                    </div>
                @else
                    <form method="POST" action="{{ route('inbox.update', $conversation) }}" class="d-flex gap-2 mb-2">
                        @csrf @method('PATCH')
                        <select name="contact_id" class="form-select form-select-sm" required>
                            <option value="">Link to a contact…</option>
                            @foreach($contacts as $contact)
                                <option value="{{ $contact->id }}">{{ $contact->displayName() }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-white">Link</button>
                    </form>
                    <form method="POST" action="{{ route('inbox.contact', $conversation) }}">
                        @csrf
                        <button class="btn btn-sm btn-soft-primary"><x-icon name="user-plus" /> Save as new contact</button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const thread = document.getElementById('inbox-thread');
        if (thread) { thread.scrollTop = thread.scrollHeight; }
    })();
</script>
