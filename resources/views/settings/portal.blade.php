@extends('layouts.app')
@section('title', 'Client portal')
@section('content')
    <x-page-header title="Client portal" sub="Give customers, suppliers, patients, parents, tenants, members and donors their own sign-in to see invoices, bookings, requests and documents." :crumbs="['Settings' => route('settings.workspace.edit'), 'Client portal']" />

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center"><h5 class="card-title mb-0">People with access</h5><span class="ms-auto fs-8 text-muted">{{ $accesses->count() }}</span></div>
                @if($accesses->isEmpty())
                    <div class="card-body"><x-empty icon="door-open" title="No one has portal access yet" text="Choose a contact below to send them an invitation." class="py-2" /></div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Contact</th><th>Type</th><th>Status</th><th>Last sign-in</th><th class="text-end">Actions</th></tr></thead>
                            <tbody>
                                @foreach($accesses as $access)
                                    <tr>
                                        <td>
                                            @if($access->contact)
                                                <a href="{{ route('contacts.show', $access->contact) }}" class="fw-600 text-decoration-none">{{ $access->contact->displayName() }}</a>
                                            @else
                                                <span class="fw-600 text-muted">Deleted contact</span>
                                            @endif
                                            <span class="d-block fs-8 text-muted">{{ $access->email }}</span>
                                        </td>
                                        <td>{{ $access->audienceLabel() }}</td>
                                        <td><x-pill :status="$access->status">{{ \App\Models\PortalAccess::STATUSES[$access->status] ?? $access->status }}</x-pill></td>
                                        <td class="fs-7">{{ $access->last_login_at?->diffForHumans() ?? 'Never' }}</td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                @if($access->isActive())
                                                    <form method="POST" action="{{ route('settings.portal.resend', $access) }}">@csrf<button class="btn btn-white btn-sm" title="Email a new sign-in link"><x-icon name="send" class="zi zi-sm" /> Send link</button></form>
                                                @endif
                                                <form method="POST" action="{{ route('settings.portal.toggle', $access) }}">@csrf<button class="btn btn-white btn-sm">{{ $access->isActive() ? 'Turn off' : 'Turn on' }}</button></form>
                                                <form method="POST" action="{{ route('settings.portal.destroy', $access) }}" onsubmit="return confirm('Remove portal access for {{ $access->email }}?')">@csrf @method('DELETE')<button class="btn btn-white btn-sm text-danger" title="Remove"><x-icon name="trash-2" class="zi zi-sm" /></button></form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <form method="POST" action="{{ route('settings.portal.invite') }}" class="card-footer">
                    @csrf
                    <div class="row g-2 align-items-start">
                        <div class="col-md-4"><x-form.select name="contact_id" label="Contact" :options="$contacts->mapWithKeys(fn ($c) => [$c->id => $c->displayName().($c->email ? ' · '.$c->email : '')])->all()" :value="$selectedContact" placeholder="Choose a contact" required /></div>
                        <div class="col-md-3"><x-form.select name="audience" label="They are a" :options="$audiences" value="customer" required /></div>
                        <div class="col-md-5"><x-form.input name="email" type="email" label="Email" help="Leave empty to use the contact's email." /></div>
                    </div>
                    <button class="btn btn-primary"><x-icon name="mail" /> Send invitation</button>
                </form>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header"><h5 class="card-title mb-0">Portal link</h5></div>
                <div class="card-body">
                    <p class="fs-7 text-muted">Share this on your website or in emails. People sign in with the email you invited them with.</p>
                    <input type="text" class="form-control form-control-sm" value="{{ $loginUrl }}" readonly onclick="this.select()" aria-label="Portal sign-in link">
                    <a href="{{ $loginUrl }}" target="_blank" rel="noopener" class="btn btn-white btn-sm mt-2"><x-icon name="external-link" class="zi zi-sm" /> Open the portal</a>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h5 class="card-title mb-0">What the portal shows</h5></div>
                <form method="POST" action="{{ route('settings.portal.update') }}" class="card-body">
                    @csrf
                    @method('PUT')
                    @foreach($available as $key => $section)
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="sections[]" value="{{ $key }}" id="section_{{ $key }}" @checked(! in_array($key, $hidden, true))>
                            <label class="form-check-label" for="section_{{ $key }}">{{ $section['label'] }}</label>
                        </div>
                    @endforeach
                    <p class="fs-8 text-muted">Sections only appear when their app is switched on.</p>
                    <x-form.textarea name="welcome" label="Welcome message" :value="$welcome" rows="3" maxlength="{{ \App\Support\Portal::WELCOME_MAX }}" help="Shown on the sign-in page and the portal overview." />
                    <button class="btn btn-primary">Save</button>
                </form>
            </div>
        </div>
    </div>
@endsection
