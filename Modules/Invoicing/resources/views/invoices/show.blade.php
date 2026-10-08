@extends('layouts.app')
@section('title', $invoice->number)
@section('content')
    <x-page-header :title="$invoice->number" :sub="($invoice->contact?->displayName() ?? '').' · issued '.$invoice->issue_date->format('d M Y').' · due '.$invoice->due_date->format('d M Y')"
                   :crumbs="['Invoices' => route('invoices.index'), $invoice->number]">
        <a href="{{ route('invoices.print', $invoice) }}" target="_blank" class="btn btn-white"><x-icon name="printer" /> Print / PDF</a>
        @php $sendHeld = \App\Support\Approvals::blocking($invoice, 'invoice.send', auth()->user()) !== null; @endphp
        @unless(\App\Support\Approvals::blocking($invoice, 'invoice.send'))
            <button type="button" class="btn btn-white" onclick="navigator.clipboard.writeText('{{ $invoice->publicUrl() }}').then(() => zonseo.toast('Public link copied'))"><x-icon name="link" /> Copy link</button>
        @endunless
        @can('update', $invoice)
            @if($invoice->status === 'draft' && ! $sendHeld)
                <form method="POST" action="{{ route('invoices.send', $invoice) }}">@csrf<button class="btn btn-soft-primary"><x-icon name="send" /> Mark as sent</button></form>
            @endif
            @if($invoice->status !== 'cancelled' && ! $sendHeld && $invoice->contact && (filled($invoice->contact->mobile) || filled($invoice->contact->phone)) && app(\App\Sms\SmsService::class)->enabled($workspace))
                <form method="POST" action="{{ route('invoices.sms', $invoice) }}">@csrf<button class="btn btn-white"><x-icon name="message-square" /> Send by SMS</button></form>
            @endif
            @if($invoice->isEditable())
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-white"><x-icon name="pencil" /> Edit</a>
            @endif
        @endcan
        @can('pay', $invoice)
            @if($invoice->balance > 0 && $invoice->status !== 'cancelled')
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal"><x-icon name="banknote" /> Record payment</button>
            @endif
        @endcan
    </x-page-header>

    <div class="row g-3">
        <div class="col-lg-8">
            <x-approvals.panel :model="$invoice" subject="invoice.send" />
            <div class="card mb-3">
                <div class="card-header">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="card-title mb-0">Invoice</h5>
                        <x-pill :status="$invoice->status">{{ $invoice->statusLabel() }}</x-pill>
                        @if($invoice->quote)<span class="fs-8 text-muted">from quote <a href="{{ route('quotes.show', $invoice->quote) }}">{{ $invoice->quote->number }}</a></span>@endif
                    </div>
                    <span class="fs-8 text-muted">{{ $invoice->currency_code }}{{ $invoice->reference ? ' · ref '.$invoice->reference : '' }}{{ $invoice->branch ? ' · '.$invoice->branch->name : '' }}</span>
                </div>
                @include('invoicing::partials.document-lines', ['document' => $invoice])
            </div>

            @if($invoice->notes || $invoice->terms)
                <div class="card mb-3">
                    <div class="card-body fs-7">
                        @if($invoice->notes)<div class="mb-2"><strong>Notes</strong><div style="white-space:pre-line">{{ $invoice->notes }}</div></div>@endif
                        @if($invoice->terms)<div><strong>Terms</strong><div class="text-muted" style="white-space:pre-line">{{ $invoice->terms }}</div></div>@endif
                    </div>
                </div>
            @endif

            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Payments</h5><span class="fs-8 text-muted">{{ $invoice->money($invoice->amount_paid) }} received</span></div>
                @if($invoice->payments->isEmpty())
                    <div class="card-body"><x-empty icon="banknote" title="No payments yet" text="Record cash, bank, mobile money or card payments as they come in." class="py-2" /></div>
                @else
                    <div class="z-table-wrap">
                        <table class="table z-table align-middle mb-0">
                            <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Reference</th><th>Received by</th><th class="text-end">Amount</th><th></th></tr></thead>
                            <tbody>
                            @foreach($invoice->payments as $p)
                                <tr>
                                    <td class="z-row-title">{{ $p->number }}</td>
                                    <td class="fs-7">{{ $p->paid_on->format('d M Y') }}</td>
                                    <td class="fs-7">{{ $p->methodLabel() }}</td>
                                    <td class="fs-7">{{ $p->reference ?: '—' }}</td>
                                    <td class="fs-7">{{ $p->receiver?->name ?? '—' }}</td>
                                    <td class="text-end fw-600 fs-7">{{ $p->money() }}</td>
                                    <td class="text-end">
                                        @can('delete', $p)
                                            <form method="POST" action="{{ route('payments.destroy', $p) }}" onsubmit="return confirm('Remove this payment of {{ $p->money() }}?')">@csrf @method('DELETE')
                                                <button class="btn btn-sm btn-icon btn-soft-danger" title="Remove"><x-icon name="trash-2" class="zi zi-sm" /></button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="fs-8 text-muted text-uppercase fw-600">Balance due</div>
                    <div class="fs-2 fw-600 {{ $invoice->isOverdue() ? 'text-danger' : '' }}">{{ $invoice->status === 'cancelled' ? '—' : $invoice->money($invoice->balance) }}</div>
                    @if($invoice->isOverdue())<div class="fs-8 text-danger">{{ $invoice->due_date->diffForHumans() }} overdue</div>
                    @elseif($invoice->status === 'paid')<div class="fs-8 text-success">Paid {{ $invoice->paid_at?->format('d M Y') }}</div>
                    @elseif($invoice->isOpen())<div class="fs-8 text-muted">Due {{ $invoice->due_date->diffForHumans() }}</div>@endif
                </div>
                <ul class="list-group list-group-flush fs-7">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Customer</span>
                        @if(Route::has('contacts.show') && $invoice->contact)<a href="{{ route('contacts.show', $invoice->contact) }}">{{ $invoice->contact->displayName() }}</a>@else<span>{{ $invoice->contact?->displayName() }}</span>@endif
                    </li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Sent</span><span>{{ $invoice->sent_at?->format('d M Y') ?? 'Not yet' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Created by</span><span>{{ $invoice->creator?->name ?? '—' }}</span></li>
                    @unless(\App\Support\Approvals::blocking($invoice, 'invoice.send'))
                        <li class="list-group-item">
                            <div class="text-muted mb-1">Public link</div>
                            <input type="text" class="form-control form-control-sm" readonly value="{{ $invoice->publicUrl() }}" onclick="this.select()">
                        </li>
                    @endunless
                </ul>
                @can('update', $invoice)
                    @if($invoice->status !== 'cancelled' && $invoice->amount_paid <= 0)
                        <div class="card-footer d-flex gap-2">
                            <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" onsubmit="return confirm('Cancel {{ $invoice->number }}?')">@csrf<button class="btn btn-sm btn-white"><x-icon name="ban" class="zi zi-sm" /> Cancel invoice</button></form>
                            @can('delete', $invoice)
                                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}" onsubmit="return confirm('Delete {{ $invoice->number }}? This cannot be undone.')">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger"><x-icon name="trash-2" class="zi zi-sm" /> Delete</button></form>
                            @endcan
                        </div>
                    @endif
                @endcan
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title">Notes & activity</h5></div>
                @can('update', $invoice)
                    <form method="POST" action="{{ route('invoices.comments.store', $invoice) }}" class="card-body border-bottom">
                        @csrf
                        <x-form.textarea name="body" placeholder="Internal note (not shown to the customer)…" rows="2" required />
                        <button class="btn btn-sm btn-primary"><x-icon name="send" class="zi zi-sm" /> Add note</button>
                    </form>
                @endcan
                <div class="card-body">
                    @if($invoice->comments->isEmpty())
                        <x-empty icon="message-square" title="No notes yet" class="py-2" />
                    @else
                        <div class="z-timeline">
                            @foreach($invoice->comments as $note)
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

    @can('pay', $invoice)
        @push('modals')
            <div class="modal fade" id="paymentModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <form class="modal-content" method="POST" action="{{ route('invoices.payments.store', $invoice) }}">
                        @csrf
                        <div class="modal-header"><h5 class="modal-title">Record a payment for {{ $invoice->number }}</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body">
                            <div class="row">
                                <div class="col-md-6"><x-form.input name="amount" type="number" step="0.01" min="0.01" label="Amount ({{ $invoice->currency_code }})" :value="max(0, $invoice->balance)" required /></div>
                                <div class="col-md-6"><x-form.input name="paid_on" type="date" label="Date received" :value="today()->format('Y-m-d')" required /></div>
                            </div>
                            <div class="row">
                                <div class="col-md-6"><x-form.select name="method" label="Method" :options="$methods" value="cash" required /></div>
                                <div class="col-md-6"><x-form.input name="reference" label="Reference" placeholder="Transaction ID, cheque no…" /></div>
                            </div>
                            <x-form.textarea name="notes" label="Notes" rows="2" />
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary"><x-icon name="check" /> Save payment</button>
                        </div>
                    </form>
                </div>
            </div>
        @endpush
        @if($errors->has('amount') || $errors->has('paid_on') || $errors->has('method'))
            @push('scripts')<script>document.addEventListener('DOMContentLoaded', () => new bootstrap.Modal('#paymentModal').show());</script>@endpush
        @endif
    @endcan
@endsection
