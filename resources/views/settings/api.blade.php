@extends('layouts.app')
@section('title', 'API & webhooks')
@section('content')
    <x-page-header title="API & webhooks" sub="Connect other software to this workspace: read and write data with an API key, or have events sent to your own URL." :crumbs="['Settings' => route('settings.workspace.edit'), 'API & webhooks']" />

    @if(session('newApiKey'))
        <div class="alert alert-success">
            <div class="fw-600 mb-1">Your new API key</div>
            <p class="fs-7 mb-2">Copy it now and keep it somewhere safe. For your security it will not be shown again.</p>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="new-api-key" value="{{ session('newApiKey') }}" readonly onclick="this.select()">
                <button type="button" class="btn btn-white" onclick="navigator.clipboard.writeText(document.getElementById('new-api-key').value); this.textContent = 'Copied'"><x-icon name="copy" /> Copy</button>
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-xl-7">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0">API keys</h5>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#apiKeyModal"><x-icon name="plus" /> New key</button>
                </div>
                @if($tokens->isEmpty())
                    <div class="card-body"><x-empty icon="key-round" title="No API keys yet" text="Make a key to let another app read or update this workspace's contacts, tasks, tickets, invoices and appointments." /></div>
                @else
                    <div class="z-table-wrap">
                        <table class="table z-table">
                            <thead><tr><th>Key</th><th>Access</th><th>Last used</th><th>Expires</th><th></th></tr></thead>
                            <tbody>
                            @foreach($tokens as $token)
                                <tr>
                                    <td>
                                        <div class="z-row-title">{{ $token->name }}</div>
                                        <div class="z-row-sub">Acts as {{ $token->tokenable?->name ?? 'a former member' }} · made {{ $token->created_at->format('d M Y') }}</div>
                                    </td>
                                    <td><x-pill :status="$token->can('write') ? 'warning' : 'info'">{{ $token->accessLabel() }}</x-pill></td>
                                    <td class="fs-7">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="fs-7">
                                        @if($token->isExpired())<x-pill status="expired">Expired</x-pill>@else{{ $token->expires_at?->format('d M Y') ?? 'Never' }}@endif
                                    </td>
                                    <td class="text-end">
                                        <form method="POST" action="{{ route('settings.api.keys.destroy', $token->id) }}" onsubmit="return confirm('Revoke {{ e($token->name) }}? Apps using it will stop working.')">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-soft-danger">Revoke</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0">Webhooks</h5>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#webhookModal"><x-icon name="plus" /> Add webhook</button>
                </div>
                @if($endpoints->isEmpty())
                    <div class="card-body"><x-empty icon="webhook" title="No webhooks yet" text="Add a URL and Zonseo will POST to it whenever something you pick happens, such as a payment coming in." /></div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($endpoints as $endpoint)
                            <a href="{{ route('settings.webhooks.show', $endpoint) }}" class="list-group-item list-group-item-action d-flex gap-3 align-items-center">
                                <x-icon :name="$endpoint->is_active ? 'webhook' : 'webhook-off'" class="zi zi-lg {{ $endpoint->is_active ? 'text-primary' : 'text-muted' }}" />
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-600 text-truncate">{{ $endpoint->url }}</div>
                                    <div class="fs-8 text-muted">{{ $endpoint->description ?: count($endpoint->events).' events' }} · {{ $endpoint->last_delivered_at ? 'last delivered '.$endpoint->last_delivered_at->diffForHumans() : 'nothing delivered yet' }}</div>
                                </div>
                                @if(! $endpoint->is_active)
                                    <x-pill status="inactive">Off</x-pill>
                                @elseif($endpoint->failed_count)
                                    <x-pill status="danger">{{ $endpoint->failed_count }} failed today</x-pill>
                                @else
                                    <x-pill status="active">On</x-pill>
                                @endif
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">Using the API</h5></div>
                <div class="card-body fs-7">
                    <p>Send the key as a Bearer token. Every key reaches only this workspace and acts with the role of the person who made it.</p>
                    <pre class="bg-body-tertiary p-2 rounded fs-8 mb-3"><code>curl {{ url('/api/v1/me') }} \
  -H "Authorization: Bearer YOUR_KEY" \
  -H "Accept: application/json"</code></pre>
                    <table class="table table-sm fs-8 mb-3">
                        <thead><tr><th>Endpoint</th><th>Methods</th></tr></thead>
                        <tbody>
                            <tr><td><code>/api/v1/me</code></td><td>GET</td></tr>
                            <tr><td><code>/api/v1/contacts</code></td><td>GET, POST, PATCH, DELETE</td></tr>
                            <tr><td><code>/api/v1/tasks</code></td><td>GET, POST, PATCH, DELETE</td></tr>
                            <tr><td><code>/api/v1/tickets</code></td><td>GET, POST, PATCH</td></tr>
                            <tr><td><code>/api/v1/invoices</code>, <code>/api/v1/payments</code></td><td>GET</td></tr>
                            <tr><td><code>/api/v1/appointments</code></td><td>GET</td></tr>
                        </tbody>
                    </table>
                    <p class="mb-2">Lists are paged: add <code>?page=2&amp;per_page=50</code> (up to 100). Most lists take <code>q</code> to search and <code>status</code> to filter. Each key may make 120 requests a minute.</p>
                    <p class="mb-0">Webhooks are signed. The <code>X-Zonseo-Signature</code> header holds <code>t=&lt;time&gt;,v1=&lt;signature&gt;</code>, where the signature is the HMAC-SHA256 of <code>&lt;time&gt;.&lt;raw body&gt;</code> using the endpoint's signing secret. Failed deliveries are retried for about 9 hours.</p>
                </div>
            </div>
        </div>
    </div>

    @push('modals')
        <div class="modal fade" id="apiKeyModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="POST" action="{{ route('settings.api.keys.store') }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">New API key</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label" for="key-name">Name</label>
                            <input type="text" class="form-control" id="key-name" name="name" maxlength="80" placeholder="e.g. Website booking form" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Access</label>
                            @foreach(\App\Models\ApiToken::ACCESS as $value => $access)
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="access" id="access-{{ $value }}" value="{{ $value }}" @checked($value === 'read')>
                                    <label class="form-check-label" for="access-{{ $value }}">{{ $access['label'] }}</label>
                                </div>
                            @endforeach
                        </div>
                        <div>
                            <label class="form-label" for="key-expiry">Expires after</label>
                            <select class="form-select" id="key-expiry" name="expires_in">
                                @foreach(\App\Models\ApiToken::EXPIRY_DAYS as $days => $label)
                                    <option value="{{ $days }}" @selected($days === '90')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Create key</button></div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="webhookModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <form class="modal-content" method="POST" action="{{ route('settings.webhooks.store') }}">
                    @csrf
                    <div class="modal-header"><h5 class="modal-title">Add webhook</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        @include('settings.partials.webhook-fields', ['endpoint' => null])
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-white" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add webhook</button></div>
                </form>
            </div>
        </div>
    @endpush
@endsection
