@extends('layouts.app')
@section('title', $ticket->number.' · '.$ticket->subject)
@section('content')
    <x-page-header :title="$ticket->subject" :sub="$ticket->number.' · '.$ticket->requesterName().' · via '.strtolower($ticket->channelLabel())"
                   :crumbs="['Helpdesk' => route('tickets.index'), $ticket->number]">
        @can('reply', $ticket)
            @if($ticket->isActive())
                <form method="POST" action="{{ route('tickets.triage', $ticket) }}">
                    @csrf <input type="hidden" name="status" value="resolved">
                    <button class="btn btn-primary"><x-icon name="check-check" /> Resolve</button>
                </form>
            @elseif($ticket->status === 'resolved')
                <form method="POST" action="{{ route('tickets.triage', $ticket) }}">
                    @csrf <input type="hidden" name="status" value="closed">
                    <button class="btn btn-white"><x-icon name="archive" /> Close</button>
                </form>
                <form method="POST" action="{{ route('tickets.triage', $ticket) }}">
                    @csrf <input type="hidden" name="status" value="open">
                    <button class="btn btn-white"><x-icon name="rotate-ccw" /> Reopen</button>
                </form>
            @else
                <form method="POST" action="{{ route('tickets.triage', $ticket) }}">
                    @csrf <input type="hidden" name="status" value="open">
                    <button class="btn btn-white"><x-icon name="rotate-ccw" /> Reopen</button>
                </form>
            @endif
        @endcan
        @can('update', $ticket)
            <a href="{{ route('tickets.edit', $ticket) }}" class="btn btn-white"><x-icon name="pencil" /> Edit</a>
        @endcan
        @can('delete', $ticket)
            <form method="POST" action="{{ route('tickets.destroy', $ticket) }}" onsubmit="return confirm('Delete this ticket?')">
                @csrf @method('DELETE')
                <button class="btn btn-soft-danger btn-icon" title="Delete"><x-icon name="trash-2" /></button>
            </form>
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <x-pill :status="$ticket->status">{{ $ticket->statusLabel() }}</x-pill>
                        <x-pill :status="$ticket->priority">{{ $ticket->priorityLabel() }}</x-pill>
                    </div>
                    <ul class="list-unstyled fs-7 mb-0 d-grid gap-2">
                        <li class="d-flex gap-2"><x-icon name="user-round" class="zi zi-sm text-muted" />
                            <span>
                                @if($ticket->contact)<a href="{{ route('contacts.show', $ticket->contact) }}">{{ $ticket->contact->displayName() }}</a>@else {{ $ticket->requesterName() }} @endif
                                @if($ticket->requesterEmail())<span class="d-block text-muted">{{ $ticket->requesterEmail() }}</span>@endif
                            </span>
                        </li>
                        <li class="d-flex gap-2"><x-icon name="user-check" class="zi zi-sm text-muted" /> {{ $ticket->assignee?->name ?? 'Unassigned' }}</li>
                        <li class="d-flex gap-2"><x-icon name="radio" class="zi zi-sm text-muted" /> {{ $ticket->channelLabel() }}{{ $ticket->category ? ' · '.$ticket->category : '' }}</li>
                        @if($ticket->branch)<li class="d-flex gap-2"><x-icon name="map-pin" class="zi zi-sm text-muted" /> {{ $ticket->branch->name }}</li>@endif
                        @if($ticket->first_replied_at)<li class="d-flex gap-2"><x-icon name="reply" class="zi zi-sm text-muted" /> First reply {{ $ticket->first_replied_at->diffForHumans($ticket->created_at, true) }} after opening</li>@endif
                        @if($ticket->resolved_at)<li class="d-flex gap-2 text-success"><x-icon name="check-check" class="zi zi-sm" /> Resolved {{ $ticket->resolved_at->diffForHumans() }}</li>@endif
                        <li class="d-flex gap-2 text-muted"><x-icon name="info" class="zi zi-sm" /> Opened {{ $ticket->created_at?->diffForHumans() }}{{ $ticket->creator ? ' by '.$ticket->creator->name : '' }}</li>
                    </ul>
                </div>
            </div>

            @can('reply', $ticket)
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Triage</h5></div>
                    <form method="POST" action="{{ route('tickets.triage', $ticket) }}" class="card-body">
                        @csrf
                        <x-form.select name="priority" label="Priority" :options="$priorities" :value="$ticket->priority" />
                        <x-form.select name="assignee_id" label="Assigned to" :options="$members" :value="$ticket->assignee_id" placeholder="Unassigned" />
                        <x-form.select name="status" label="Status" :options="$statuses" :value="$ticket->status" />
                        <button class="btn btn-sm btn-soft-primary"><x-icon name="check" class="zi zi-sm" /> Apply</button>
                    </form>
                </div>
            @endcan

            @if($others->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Other tickets from {{ $ticket->contact->displayName() }}</h5></div>
                    <div class="list-group list-group-flush">
                        @foreach($others as $other)
                            <a href="{{ route('tickets.show', $other) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2">
                                <span class="mw-0"><span class="d-block fs-7 fw-600 text-truncate">{{ $other->subject }}</span><span class="fs-8 text-muted">{{ $other->number }} · {{ $other->created_at->diffForHumans() }}</span></span>
                                <x-pill :status="$other->status">{{ $other->statusLabel() }}</x-pill>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Conversation</h5></div>
                <div class="card-body">
                    <div class="z-timeline">
                        <div class="z-timeline-item">
                            <span class="z-avatar z-avatar-sm z-avatar-soft">{{ \Illuminate\Support\Str::of($ticket->requesterName())->substr(0, 1) }}</span>
                            <div class="flex-grow-1">
                                <div class="fs-8 text-muted"><strong class="text-body">{{ $ticket->requesterName() }}</strong> · opened the ticket · {{ $ticket->created_at->diffForHumans() }}</div>
                                <div class="fs-7" style="white-space:pre-line">{{ $ticket->body ?: 'No details were recorded.' }}</div>
                            </div>
                        </div>
                        @foreach($thread as $entry)
                            <div class="z-timeline-item {{ $entry->is_internal ? 'z-timeline-internal' : '' }}">
                                <span class="z-avatar z-avatar-sm {{ $entry->is_internal ? 'z-avatar-warning' : 'z-avatar-soft' }}">{{ \Illuminate\Support\Str::of($entry->authorName())->substr(0, 1) }}</span>
                                <div class="flex-grow-1">
                                    <div class="fs-8 text-muted">
                                        <strong class="text-body">{{ $entry->authorName() }}</strong>
                                        · {{ $entry->is_internal ? 'internal note' : ($entry->user_id ? 'replied to the customer' : 'customer said') }}
                                        · {{ $entry->created_at->diffForHumans() }}
                                        @if($entry->is_internal)<span class="z-pill z-pill-warning ms-1">Not visible to customer</span>@endif
                                    </div>
                                    <div class="fs-7" style="white-space:pre-line">{{ $entry->body }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            @can('reply', $ticket)
                <div class="card" x-data="{ kind: 'reply' }">
                    <div class="card-header flex-wrap gap-2">
                        <h5 class="card-title">Add to the conversation</h5>
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn" :class="kind === 'reply' ? 'btn-primary' : 'btn-white'" @click="kind = 'reply'"><x-icon name="reply" class="zi zi-sm" /> Reply to customer</button>
                            <button type="button" class="btn" :class="kind === 'note' ? 'btn-warning' : 'btn-white'" @click="kind = 'note'"><x-icon name="sticky-note" class="zi zi-sm" /> Internal note</button>
                            <button type="button" class="btn" :class="kind === 'customer' ? 'btn-info' : 'btn-white'" @click="kind = 'customer'"><x-icon name="phone-incoming" class="zi zi-sm" /> Customer said</button>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('tickets.replies.store', $ticket) }}" class="card-body">
                        @csrf
                        <input type="hidden" name="kind" :value="kind">
                        <x-form.textarea name="body" rows="3" required
                            x-bind:placeholder="kind === 'note' ? 'Only your team sees this.' : (kind === 'customer' ? 'What the customer told you by phone, email or in person.' : 'Your answer to the customer. This parks the ticket as waiting on them.')" />
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fs-8 text-muted" x-text="kind === 'note' ? 'Internal notes never leave the workspace.' : (kind === 'customer' ? 'Logging a customer message reopens the ticket.' : 'Replies are stored on the ticket. Email sending is not connected yet.')"></span>
                            <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> <span x-text="kind === 'note' ? 'Add note' : (kind === 'customer' ? 'Log message' : 'Send reply')"></span></button>
                        </div>
                    </form>
                </div>
            @endcan
        </div>
    </div>
@endsection
