@extends('layouts.app')
@section('title', $contact->displayName())
@section('content')
    <x-page-header :title="$contact->displayName()" :sub="$contact->typeLabel().($contact->kind === 'company' ? ' · contact person: '.$contact->name : '').($contact->branch ? ' · '.$contact->branch->name : '')"
                   :crumbs="['Contacts' => route('contacts.index'), $contact->displayName()]">
        @if(Route::has('invoices.create') && $workspace->hasModule('invoicing') && $contact->type !== 'supplier')
            <a href="{{ route('invoices.create', ['contact' => $contact->id]) }}" class="btn btn-white"><x-icon name="file-text" /> New invoice</a>
        @endif
        @if(Route::has('appointments.create') && $workspace->hasModule('appointments'))
            <a href="{{ route('appointments.create', ['contact' => $contact->id]) }}" class="btn btn-white"><x-icon name="calendar-plus" /> Book</a>
        @endif
        @can('manage-workspace')
            <a href="{{ route('settings.portal.index', ['contact' => $contact->id]) }}" class="btn btn-white"><x-icon name="door-open" /> Portal access</a>
        @endcan
        @can('update', $contact)
            <a href="{{ route('contacts.edit', $contact) }}" class="btn btn-primary"><x-icon name="pencil" /> Edit</a>
        @endcan
        @can('delete', $contact)
            <form method="POST" action="{{ route('contacts.destroy', $contact) }}" onsubmit="return confirm('Delete {{ addslashes($contact->displayName()) }}? Their history stays but they will no longer appear anywhere.')">
                @csrf @method('DELETE')
                <button class="btn btn-soft-danger btn-icon" title="Delete"><x-icon name="trash-2" /></button>
            </form>
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body text-center">
                    <span class="z-avatar z-avatar-lg z-avatar-soft mx-auto mb-2">{{ $contact->initials() }}</span>
                    <h5 class="mb-0">{{ $contact->displayName() }}</h5>
                    <div class="text-muted fs-7 mb-2">{{ $contact->kind === 'company' ? $contact->name : ($contact->company_name ?: 'Individual') }}</div>
                    <x-pill :status="$contact->type" /> @unless($contact->is_active)<x-pill status="archived" class="ms-1" />@endunless
                    <div class="mt-2">@foreach($contact->tags ?? [] as $tag)<span class="z-chip">{{ $tag }}</span>@endforeach</div>
                </div>
                <ul class="list-group list-group-flush fs-7">
                    <li class="list-group-item d-flex gap-2"><x-icon name="mail" class="zi zi-sm text-muted" /> {{ $contact->email ?: '—' }}</li>
                    <li class="list-group-item d-flex gap-2"><x-icon name="phone" class="zi zi-sm text-muted" /> {{ $contact->phone ?: '—' }}</li>
                    <li class="list-group-item d-flex gap-2"><x-icon name="smartphone" class="zi zi-sm text-muted" /> {{ $contact->mobile ?: '—' }}</li>
                    <li class="list-group-item d-flex gap-2"><x-icon name="map-pin" class="zi zi-sm text-muted" /> <span>{{ $contact->address ?: '—' }}@if($contact->city)<br>{{ $contact->city }}@endif @if($contact->country_code) · {{ \App\Support\Lists::country($contact->country_code) }}@endif</span></li>
                    <li class="list-group-item d-flex gap-2"><x-icon name="receipt" class="zi zi-sm text-muted" /> Tax no. {{ $contact->tax_number ?: '—' }} · {{ $contact->currency_code ?: $workspace->currency_code }}</li>
                    <li class="list-group-item d-flex gap-2 text-muted"><x-icon name="clock" class="zi zi-sm" /> Added {{ $contact->created_at?->diffForHumans() }}{{ $contact->creator ? ' by '.$contact->creator->name : '' }}</li>
                </ul>
            </div>
            @if($contact->notes)
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Notes</h5></div>
                    <div class="card-body fs-7" style="white-space:pre-line">{{ $contact->notes }}</div>
                </div>
            @endif
            <x-custom-fields.details :record="$contact" />
        </div>

        <div class="col-lg-8">
            @php
                $related = collect([
                    ['invoicing', 'invoices.index', 'Invoices', 'file-text'],
                    ['invoicing', 'quotes.index', 'Quotes', 'file-signature'],
                    ['appointments', 'appointments.index', 'Appointments', 'calendar'],
                    ['tasks', 'tasks.index', 'Tasks', 'check-square'],
                    ['helpdesk', 'tickets.index', 'Tickets', 'life-buoy'],
                ])->filter(fn ($r) => Route::has($r[1]) && $workspace->hasModule($r[0]));
            @endphp
            @if($related->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Across your apps</h5></div>
                    <div class="card-body d-flex flex-wrap gap-2">
                        @foreach($related as [$module, $routeName, $label, $icon])
                            <a href="{{ route($routeName, ['contact' => $contact->id]) }}" class="btn btn-white btn-sm"><x-icon :name="$icon" class="zi zi-sm" /> {{ $label }}</a>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h5 class="card-title">Notes & activity</h5></div>
                @can('update', $contact)
                    <form method="POST" action="{{ route('contacts.comments.store', $contact) }}" class="card-body border-bottom">
                        @csrf
                        <x-form.textarea name="body" placeholder="Write a note about this contact…" rows="2" required />
                        <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Add note</button>
                    </form>
                @endcan
                <div class="card-body">
                    @if($contact->comments->isEmpty())
                        <x-empty icon="message-square" title="No notes yet" text="Calls, meetings, agreements — keep the story of this relationship here." class="py-3" />
                    @else
                        <div class="z-timeline">
                            @foreach($contact->comments as $note)
                                <div class="z-timeline-item">
                                    <span class="z-avatar z-avatar-sm z-avatar-soft">{{ \Illuminate\Support\Str::of($note->authorName())->substr(0, 1) }}</span>
                                    <div>
                                        <div class="fs-8 text-muted"><strong class="text-body">{{ $note->authorName() }}</strong> · {{ $note->created_at->diffForHumans() }}</div>
                                        <div class="fs-7" style="white-space:pre-line">{{ $note->body }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
