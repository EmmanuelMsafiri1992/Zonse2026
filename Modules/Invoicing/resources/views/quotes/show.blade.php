@extends('layouts.app')
@section('title', $quote->number)
@section('content')
    <x-page-header :title="$quote->number" :sub="($quote->contact?->displayName() ?? '').' · issued '.$quote->issue_date->format('d M Y').($quote->valid_until ? ' · valid until '.$quote->valid_until->format('d M Y') : '')"
                   :crumbs="['Quotes' => route('quotes.index'), $quote->number]">
        <a href="{{ route('quotes.print', $quote) }}" target="_blank" class="btn btn-white"><x-icon name="printer" /> Print / PDF</a>
        <button type="button" class="btn btn-white" onclick="navigator.clipboard.writeText('{{ $quote->publicUrl() }}').then(() => zonseo.toast('Public link copied'))"><x-icon name="link" /> Copy link</button>
        @can('update', $quote)
            @if($quote->status === 'draft')
                <form method="POST" action="{{ route('quotes.send', $quote) }}">@csrf<button class="btn btn-soft-primary"><x-icon name="send" /> Mark as sent</button></form>
            @endif
            @if($quote->isEditable())
                <a href="{{ route('quotes.edit', $quote) }}" class="btn btn-white"><x-icon name="pencil" /> Edit</a>
            @endif
            @if(in_array($quote->status, ['sent', 'expired', 'draft']))
                <form method="POST" action="{{ route('quotes.accept', $quote) }}">@csrf<button class="btn btn-soft-primary"><x-icon name="check" /> Accepted</button></form>
                <form method="POST" action="{{ route('quotes.reject', $quote) }}">@csrf<button class="btn btn-white text-danger"><x-icon name="x" /> Rejected</button></form>
            @endif
            @if(in_array($quote->status, ['accepted', 'sent', 'draft', 'expired']) && $workspace->hasModule('invoicing'))
                @can('create', \Modules\Invoicing\Models\Invoice::class)
                    <form method="POST" action="{{ route('quotes.convert', $quote) }}">@csrf<button class="btn btn-primary"><x-icon name="arrow-right" /> Turn into invoice</button></form>
                @endcan
            @endif
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">Quote</h5>
                        <x-pill :status="$quote->status">{{ $quote->statusLabel() }}</x-pill>
                        @if($quote->invoice)<span class="fs-8 text-muted">invoiced as <a href="{{ route('invoices.show', $quote->invoice) }}">{{ $quote->invoice->number }}</a></span>@endif
                    </div>
                    <span class="fs-8 text-muted">{{ $quote->currency_code }}{{ $quote->reference ? ' · ref '.$quote->reference : '' }}{{ $quote->branch ? ' · '.$quote->branch->name : '' }}</span>
                </div>
                @include('invoicing::partials.document-lines', ['document' => $quote])
            </div>
            @if($quote->notes || $quote->terms)
                <div class="card mb-3">
                    <div class="card-body fs-7">
                        @if($quote->notes)<div class="mb-2"><strong>Notes</strong><div style="white-space:pre-line">{{ $quote->notes }}</div></div>@endif
                        @if($quote->terms)<div><strong>Terms</strong><div class="text-muted" style="white-space:pre-line">{{ $quote->terms }}</div></div>@endif
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="fs-8 text-muted text-uppercase fw-600">Quote total</div>
                    <div class="fs-2 fw-600">{{ $quote->money($quote->total) }}</div>
                    @if($quote->isExpired())<div class="fs-8 text-danger">Expired {{ $quote->valid_until->diffForHumans() }}</div>
                    @elseif($quote->accepted_at)<div class="fs-8 text-success">Accepted {{ $quote->accepted_at->format('d M Y') }}</div>@endif
                </div>
                <ul class="list-group list-group-flush fs-7">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Customer</span>
                        @if(Route::has('contacts.show') && $quote->contact)<a href="{{ route('contacts.show', $quote->contact) }}">{{ $quote->contact->displayName() }}</a>@else<span>{{ $quote->contact?->displayName() }}</span>@endif
                    </li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Sent</span><span>{{ $quote->sent_at?->format('d M Y') ?? 'Not yet' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Created by</span><span>{{ $quote->creator?->name ?? '—' }}</span></li>
                    <li class="list-group-item">
                        <div class="text-muted mb-1">Public link</div>
                        <input type="text" class="form-control form-control-sm" readonly value="{{ $quote->publicUrl() }}" onclick="this.select()">
                    </li>
                </ul>
                @can('delete', $quote)
                    @if($quote->status !== 'converted')
                        <div class="card-footer">
                            <form method="POST" action="{{ route('quotes.destroy', $quote) }}" onsubmit="return confirm('Delete {{ $quote->number }}?')">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger"><x-icon name="trash-2" class="zi zi-sm" /> Delete quote</button></form>
                        </div>
                    @endif
                @endcan
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title">Notes & activity</h5></div>
                @can('update', $quote)
                    <form method="POST" action="{{ route('quotes.comments.store', $quote) }}" class="card-body border-bottom">
                        @csrf
                        <x-form.textarea name="body" placeholder="Internal note (not shown to the customer)…" rows="2" required />
                        <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Add note</button>
                    </form>
                @endcan
                <div class="card-body">
                    @if($quote->comments->isEmpty())
                        <x-empty icon="message-square" title="No notes yet" class="py-2" />
                    @else
                        <div class="z-timeline">
                            @foreach($quote->comments as $note)
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
