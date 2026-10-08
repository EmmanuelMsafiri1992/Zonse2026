@extends('layouts.app')
@section('title', 'Send for signing')
@section('content')
    <x-page-header title="Send for signing" :sub="$quote ? 'Quote '.$quote->number.' will be attached as a PDF. Once everyone signs, the quote is marked accepted.' : 'Upload a PDF and choose who must sign it.'"
                   :crumbs="['E-signatures' => route('signatures.index'), 'New']" />

    <div class="row g-4">
        <div class="col-xl-7">
            <form method="POST" action="{{ route('signatures.store') }}" enctype="multipart/form-data" class="card" x-data="{ signers: @js(array_values($signers)), max: {{ \App\Support\Signatures::MAX_SIGNERS }} }">
                @csrf
                <div class="card-body">
                    <x-form.input name="title" label="Title" :value="$quote ? 'Quote '.$quote->number : null" maxlength="190" required placeholder="e.g. Tenancy agreement, 12 Samora Machel Ave" />

                    @if($quote)
                        <input type="hidden" name="quote_id" value="{{ $quote->id }}">
                        <div class="border rounded p-3 mb-3 d-flex align-items-center gap-2">
                            <x-icon name="file-text" />
                            <div class="flex-grow-1">
                                <div class="fw-600">Quote {{ $quote->number }}</div>
                                <div class="fs-8 text-muted">{{ $quote->contact?->displayName() }} · {{ $quote->money($quote->total) }}</div>
                            </div>
                            <a href="{{ route('quotes.pdf', $quote) }}" target="_blank" class="btn btn-sm btn-white"><x-icon name="eye" class="zi zi-sm" /> View</a>
                        </div>
                    @else
                        <div class="mb-3">
                            <label for="f_document" class="form-label required">Document (PDF, up to 10 MB)</label>
                            <input type="file" name="document" id="f_document" accept="application/pdf,.pdf" required @class(['form-control', 'is-invalid' => $errors->has('document')])>
                            @error('document')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif

                    <div class="form-label required">Who signs</div>
                    <template x-for="(signer, index) in signers" :key="index">
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-auto fs-8 text-muted" style="width:24px" x-text="index + 1"></div>
                            <div class="col"><input type="text" class="form-control" :name="`signers[${index}][name]`" x-model="signer.name" placeholder="Full name" maxlength="120" required></div>
                            <div class="col"><input type="email" class="form-control" :name="`signers[${index}][email]`" x-model="signer.email" placeholder="Email address" maxlength="190" required></div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-icon btn-soft-danger" title="Remove" @click="signers.splice(index, 1)" :disabled="signers.length === 1"><x-icon name="x" /></button>
                            </div>
                        </div>
                    </template>
                    @foreach($errors->getMessages() as $key => $messages)
                        @if(str_starts_with($key, 'signers'))<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endif
                    @endforeach
                    <button type="button" class="btn btn-sm btn-white mb-3" @click="signers.push({ name: '', email: '' })" x-show="signers.length < max"><x-icon name="plus" class="zi zi-sm" /> Add signer</button>

                    <div class="row">
                        <div class="col-md-7"><x-form.select name="signing_order" label="Signing order" :options="$orders" value="parallel" required /></div>
                        <div class="col-md-5"><x-form.input name="expires_in_days" type="number" min="1" max="365" label="Days to sign" value="30" help="Leave blank for no deadline." /></div>
                    </div>
                    <x-form.textarea name="message" label="Message to signers" rows="3" maxlength="2000" placeholder="Optional note shown in the email and on the signing page." />
                </div>
                <div class="card-footer d-flex gap-2">
                    <button class="btn btn-primary"><x-icon name="send" /> Send for signing</button>
                    <a href="{{ $quote ? route('quotes.show', $quote) : route('signatures.index') }}" class="btn btn-white">Cancel</a>
                </div>
            </form>
        </div>
        <div class="col-xl-5">
            <div class="card">
                <div class="card-body fs-7">
                    <h6>How it works</h6>
                    <ul class="text-muted mb-0 ps-3">
                        <li>Each signer gets their own private link by email. No account is needed, and it works on a phone.</li>
                        <li>They read the document, then draw or type their signature.</li>
                        <li>The PDF is fingerprinted (SHA-256) when sent, so nobody can swap it before signing.</li>
                        <li>When everyone has signed, you and every signer get a certificate with each signature, the time, IP address and the full history.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
