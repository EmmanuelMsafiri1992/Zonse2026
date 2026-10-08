@extends('layouts.app')
@section('title', 'Webhook')
@section('content')
    <x-page-header :title="$endpoint->host()" :sub="$endpoint->description ?: 'Events are sent here as signed POST requests.'" :crumbs="['Settings' => route('settings.workspace.edit'), 'API & webhooks' => route('settings.api.index'), 'Webhook']">
        <form method="POST" action="{{ route('settings.webhooks.test', $endpoint) }}">
            @csrf
            <button class="btn btn-primary" @disabled(! $endpoint->is_active)><x-icon name="send" /> Send test event</button>
        </form>
    </x-page-header>

    @if(! $endpoint->is_active && $endpoint->disabled_at)
        <div class="alert alert-warning fs-7">This webhook was switched off on {{ $endpoint->disabled_at->format('d M Y, H:i') }} after {{ $endpoint->failure_count }} failed deliveries in a row. Fix the address, then tick "On" and save.</div>
    @endif

    <div class="row g-4">
        <div class="col-xl-5">
            <form class="card mb-4" method="POST" action="{{ route('settings.webhooks.update', $endpoint) }}">
                @csrf @method('PUT')
                <div class="card-header"><h5 class="card-title mb-0">Settings</h5></div>
                <div class="card-body">
                    @include('settings.partials.webhook-fields', ['endpoint' => $endpoint])
                    <div class="form-check form-switch mt-3">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="webhook-active" @checked($endpoint->is_active)>
                        <label class="form-check-label" for="webhook-active">On</label>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-between">
                    <button class="btn btn-primary">Save</button>
                </div>
            </form>

            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title mb-0">Signing secret</h5></div>
                <div class="card-body fs-7">
                    <p class="text-muted">Use this to check that a request really came from Zonseo.</p>
                    <div class="input-group mb-3">
                        <input type="password" class="form-control font-monospace" id="webhook-secret" value="{{ $endpoint->secret }}" readonly>
                        <button type="button" class="btn btn-white" onclick="const f = document.getElementById('webhook-secret'); f.type = f.type === 'password' ? 'text' : 'password'">Show</button>
                        <button type="button" class="btn btn-white" onclick="navigator.clipboard.writeText(document.getElementById('webhook-secret').value); this.textContent = 'Copied'"><x-icon name="copy" /></button>
                    </div>
                    <form method="POST" action="{{ route('settings.webhooks.secret', $endpoint) }}" onsubmit="return confirm('Make a new secret? The old one stops working straight away.')">
                        @csrf
                        <button class="btn btn-sm btn-white"><x-icon name="rotate-ccw" /> Make a new secret</button>
                    </form>
                </div>
            </div>

            <form method="POST" action="{{ route('settings.webhooks.destroy', $endpoint) }}" onsubmit="return confirm('Remove this webhook and its delivery history?')">
                @csrf @method('DELETE')
                <button class="btn btn-soft-danger"><x-icon name="trash-2" /> Remove webhook</button>
            </form>
        </div>

        <div class="col-xl-7">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Recent deliveries</h5></div>
                @if($deliveries->isEmpty())
                    <div class="card-body"><x-empty icon="send" title="Nothing sent yet" text="Send a test event, or wait for one of the chosen events to happen." /></div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($deliveries as $delivery)
                            <details class="list-group-item">
                                <summary class="d-flex gap-2 align-items-center" style="cursor:pointer">
                                    <x-pill :status="['succeeded' => 'success', 'failed' => 'danger', 'pending' => 'pending'][$delivery->status]">{{ \App\Models\WebhookDelivery::STATUSES[$delivery->status] }}</x-pill>
                                    <code class="fs-7">{{ $delivery->event }}</code>
                                    <span class="fs-8 text-muted ms-auto">{{ $delivery->response_status ? 'HTTP '.$delivery->response_status.' · ' : '' }}{{ $delivery->attempts }} {{ Str::plural('try', $delivery->attempts) }} · {{ $delivery->created_at->diffForHumans() }}</span>
                                </summary>
                                <div class="mt-2 fs-8">
                                    @if($delivery->error)<div class="text-danger mb-2">{{ $delivery->error }}</div>@endif
                                    <div class="fw-600 mb-1">Sent</div>
                                    <pre class="bg-body-tertiary p-2 rounded mb-2" style="max-height:240px">{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                                    @if($delivery->response_body)
                                        <div class="fw-600 mb-1">Response</div>
                                        <pre class="bg-body-tertiary p-2 rounded mb-2" style="max-height:160px">{{ $delivery->response_body }}</pre>
                                    @endif
                                    @if($delivery->event !== 'ping' && $endpoint->is_active)
                                        <form method="POST" action="{{ route('settings.webhooks.redeliver', [$endpoint, $delivery]) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-white"><x-icon name="refresh-cw" /> Resend</button>
                                        </form>
                                    @endif
                                </div>
                            </details>
                        @endforeach
                    </div>
                    @if($deliveries->hasPages())<div class="card-footer">{{ $deliveries->links() }}</div>@endif
                @endif
            </div>
        </div>
    </div>
@endsection
