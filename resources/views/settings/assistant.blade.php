@extends('layouts.app')
@section('title', 'Assistant settings')
@section('content')
    <x-page-header title="Assistant settings" sub="Choose the AI that answers questions about your workspace." :crumbs="['Assistant' => route('assistant.index'), 'Settings']" />

    <div class="row g-4">
        <div class="col-lg-7">
            <form method="POST" action="{{ route('settings.assistant.update') }}" class="card" x-data="{ provider: {{ Js::from(old('provider', $current ?? '')) }} }">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title">Who answers</h5></div>
                <div class="card-body">
                    <p class="fs-7 text-muted">Questions are answered with your own account at the provider, so you pay them directly at their rates. Start with <strong>Test mode</strong> to try the assistant without paying for anything.</p>

                    <label class="border rounded p-3 mb-2 d-flex gap-2 align-items-start">
                        <input type="radio" class="form-check-input mt-1" name="provider" value="" x-model="provider">
                        <span><span class="fw-600">Off</span><span class="d-block fs-8 text-muted">Nobody can ask the assistant anything.</span></span>
                    </label>
                    @foreach($providers as $key => $row)
                        <div class="border rounded p-3 mb-2">
                            <label class="d-flex gap-2 align-items-start mb-0">
                                <input type="radio" class="form-check-input mt-1" name="provider" value="{{ $key }}" x-model="provider">
                                <span class="flex-grow-1">
                                    <span class="fw-600">{{ $row['provider']->label() }}</span>
                                    @if($current === $key && $row['configured'])
                                        <span class="badge bg-soft-success text-success ms-1">Active</span>
                                    @elseif($current === $key)
                                        <span class="badge bg-soft-warning text-warning ms-1">Needs its key</span>
                                    @endif
                                    <span class="d-block fs-8 text-muted">{{ $row['provider']->description() }}</span>
                                </span>
                            </label>
                            @if($row['provider']->fields())
                                <div class="row mt-3" x-show="provider === {{ Js::from($key) }}" x-cloak>
                                    @foreach($row['provider']->fields() as $field => $meta)
                                        <div class="col-md-6">
                                            @if($meta['secret'])
                                                <x-form.input name="{{ $key }}[{{ $field }}]" type="password" :label="$meta['label']" autocomplete="new-password"
                                                    :placeholder="$row['values'][$field] ? 'Saved '.$row['values'][$field].' · leave blank to keep' : ''" :help="$meta['help'] ?? null" />
                                            @else
                                                <x-form.input name="{{ $key }}[{{ $field }}]" :label="$meta['label']" :value="$row['values'][$field]" :help="$meta['help'] ?? null" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endforeach
                    <div class="fs-8 text-muted mb-3">Keys are stored encrypted.</div>

                    <x-form.input name="monthly_limit" type="number" min="1" max="100000" label="Questions per month" :value="$limit" required
                                  help="For the whole workspace, to keep provider costs predictable. One question can take several lookups." />
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save settings</button></div>
            </form>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">This month</h5></div>
                <table class="table mb-0 fs-7">
                    <tr><td class="text-muted">Questions asked</td><td>{{ number_format($usage['questions']) }} of {{ number_format($limit) }}</td></tr>
                    <tr><td class="text-muted">Tokens sent</td><td>{{ number_format($usage['input_tokens']) }}</td></tr>
                    <tr><td class="text-muted">Tokens received</td><td>{{ number_format($usage['output_tokens']) }}</td></tr>
                </table>
            </div>
            <div class="card">
                <div class="card-header"><h5 class="card-title">How it works</h5></div>
                <div class="card-body fs-7">
                    <ol class="ps-3 mb-3">
                        <li class="mb-2">Anyone in the workspace can ask a question. Chats are private to the person who started them.</li>
                        <li class="mb-2">The assistant looks things up in the apps that are switched on, your contacts and your invoices, and answers from what it finds.</li>
                        <li>It can draft emails and summarise records for you to copy, but it can only read. It never changes, sends or deletes anything.</li>
                    </ol>
                    <p class="text-muted mb-0">With Anthropic or OpenAI, each question and the data looked up to answer it are sent to that provider. Test mode sends nothing outside Zonseob.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
