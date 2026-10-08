@extends('layouts.app')
@section('title', 'My profile')
@section('content')
    <x-page-header title="My profile" sub="Your personal details, password and security." />

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Profile information</h5></div>
                <form method="POST" action="{{ route('user-profile-information.update') }}">
                    @csrf @method('PUT')
                    <div class="card-body">
                        <x-form.input name="name" label="Full name" :value="$user->name" required bag="updateProfileInformation" />
                        <x-form.input name="email" label="Email address" type="email" :value="$user->email" required bag="updateProfileInformation" />
                        <div class="row">
                            <div class="col-md-6"><x-form.input name="phone" label="Phone" :value="$user->phone" bag="updateProfileInformation" /></div>
                            <div class="col-md-6"><x-form.select name="timezone" label="Time zone" :options="$timezones" :value="$user->timezone" placeholder="Use workspace time zone" bag="updateProfileInformation" /></div>
                        </div>
                    </div>
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary">Save profile</button></div>
                </form>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Change password</h5></div>
                <form method="POST" action="{{ route('user-password.update') }}">
                    @csrf @method('PUT')
                    <div class="card-body">
                        <x-form.input name="current_password" label="Current password" type="password" required autocomplete="current-password" bag="updatePassword" />
                        <x-form.input name="password" label="New password" type="password" required autocomplete="new-password" bag="updatePassword" />
                        <x-form.input name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" bag="updatePassword" />
                    </div>
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary">Update password</button></div>
                </form>
            </div>

            <div class="card mb-3" id="notifications">
                <div class="card-header"><h5 class="card-title">Notifications</h5></div>
                <form method="POST" action="{{ route('profile.notifications.update') }}">
                    @csrf @method('PUT')
                    <div class="z-table-wrap">
                        <table class="table z-table align-middle mb-0">
                            <thead><tr><th>Tell me about</th>@foreach(\App\Support\Notifier::CHANNELS as $label)<th class="text-center">{{ $label }}</th>@endforeach</tr></thead>
                            <tbody>
                            @foreach(\App\Support\Notifier::KINDS as $kind => $meta)
                                <tr>
                                    <td><span class="z-row-title">{{ $meta['label'] }}</span><div class="z-row-sub">{{ $meta['hint'] }}</div></td>
                                    @foreach(array_keys(\App\Support\Notifier::CHANNELS) as $channel)
                                        <td class="text-center">
                                            <input type="hidden" name="preferences[{{ $kind }}][{{ $channel }}]" value="0">
                                            <input type="checkbox" class="form-check-input" name="preferences[{{ $kind }}][{{ $channel }}]" value="1" aria-label="{{ $meta['label'] }}: {{ \App\Support\Notifier::CHANNELS[$channel] }}" @checked(\App\Support\Notifier::wants($user, $kind, $channel))>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary">Save notification choices</button></div>
                </form>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="card-title">Two-factor authentication</h5>
                    <x-pill :status="$user->two_factor_confirmed_at ? 'active' : 'inactive'">{{ $user->two_factor_confirmed_at ? 'Enabled' : 'Off' }}</x-pill>
                </div>
                <div class="card-body">
                    @if($user->two_factor_secret && ! $user->two_factor_confirmed_at)
                        <p class="fs-7">Scan this QR code with Google Authenticator, Authy or 1Password, then enter the 6-digit code to finish.</p>
                        <div class="text-center mb-3">{!! $user->twoFactorQrCodeSvg() !!}</div>
                        <p class="fs-8 text-muted text-break">Setup key: {{ decrypt($user->two_factor_secret) }}</p>
                        <form method="POST" action="{{ route('two-factor.confirm') }}">
                            @csrf
                            <x-form.input name="code" label="Authentication code" inputmode="numeric" required bag="confirmTwoFactorAuthentication" />
                            <button class="btn btn-primary w-100">Confirm &amp; enable</button>
                        </form>
                    @elseif($user->two_factor_confirmed_at)
                        <p class="fs-7">Your account is protected. Keep these recovery codes somewhere safe. Each can be used once.</p>
                        <div class="bg-soft rounded-10 p-3 font-monospace fs-7 mb-3">
                            @foreach($user->recoveryCodes() as $code)<div>{{ $code }}</div>@endforeach
                        </div>
                        <div class="d-flex gap-2">
                            <form method="POST" action="{{ route('two-factor.recovery-codes') }}">@csrf <button class="btn btn-white btn-sm">Regenerate codes</button></form>
                            <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE') <button class="btn btn-soft-danger btn-sm">Disable 2FA</button></form>
                        </div>
                    @else
                        <p class="fs-7">Add a second step at sign-in using an authenticator app on your phone.</p>
                        <form method="POST" action="{{ route('two-factor.enable') }}">@csrf <button class="btn btn-primary"><x-icon name="shield-check" /> Enable two-factor</button></form>
                    @endif
                </div>
            </div>

            <div class="card mb-3" id="passkeys">
                <div class="card-header"><h5 class="card-title">Passkeys</h5></div>
                <div class="card-body">
                    <p class="fs-7">Sign in with your fingerprint, face or phone screen lock instead of typing a password.</p>
                    @forelse($passkeys as $passkey)
                        <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom" data-passkey="{{ $passkey->id }}">
                            <div class="d-flex align-items-center gap-2">
                                <x-icon name="key-round" />
                                <div>
                                    <div class="fw-600 fs-7">{{ $passkey->name }}</div>
                                    <div class="fs-8 text-muted">{{ $passkey->authenticator ? $passkey->authenticator.' · ' : '' }}Added {{ $passkey->created_at->diffForHumans() }} · {{ $passkey->last_used_at ? 'last used '.$passkey->last_used_at->diffForHumans() : 'not used yet' }}</div>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('passkey.destroy', $passkey) }}" onsubmit="return confirm('Remove this passkey? You will not be able to sign in with it any more.')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-link text-danger p-0">Remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="fs-8 text-muted mb-0">No passkeys yet.</p>
                    @endforelse

                    <div class="mt-3" x-data="passkeyRegister(@js(route('passkey.registration-options')), @js(route('passkey.store')), @js(route('profile.passkeys.confirm')))">
                        <template x-if="supported">
                            <form class="d-flex gap-2" @submit.prevent="add()">
                                <input type="text" class="form-control form-control-sm" x-model="name" maxlength="255" placeholder="Name, e.g. My laptop" aria-label="Passkey name">
                                <button class="btn btn-sm btn-primary text-nowrap" :disabled="busy"><x-icon name="plus" /> Add passkey</button>
                            </form>
                        </template>
                        <template x-if="! supported">
                            <p class="fs-8 text-muted mb-0">This browser cannot make passkeys here. Passkeys need a secure (https) connection.</p>
                        </template>
                        <div class="text-danger fs-8 mt-1" x-show="error" x-text="error"></div>
                    </div>
                </div>
            </div>

            @if($ssoProviders || $socialAccounts->isNotEmpty())
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Linked accounts</h5></div>
                    <div class="card-body">
                        @foreach(\App\Support\SingleSignOn::PROVIDERS as $provider => $label)
                            @php($account = $socialAccounts->get($provider))
                            @continue(! $account && ! isset($ssoProviders[$provider]))
                            <div class="d-flex align-items-center justify-content-between gap-2 py-2 border-bottom" data-linked="{{ $provider }}">
                                <div>
                                    <div class="fw-600 fs-7">{{ $label }}</div>
                                    <div class="fs-8 text-muted">{{ $account ? 'Linked'.($account->email ? ' as '.$account->email : '') : 'Not linked' }}</div>
                                </div>
                                @if($account)
                                    <form method="POST" action="{{ route('sso.destroy', $provider) }}" onsubmit="return confirm('Unlink {{ $label }}? You will need your password or a passkey to sign in.')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-white">Unlink</button>
                                    </form>
                                @else
                                    <a href="{{ route('sso.redirect', $provider) }}" class="btn btn-sm btn-white">Link</a>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header"><h5 class="card-title">My workspaces</h5></div>
                <div class="card-body z-kpi-list">
                    @foreach($memberships as $ws)
                        <div class="z-kpi">
                            <div class="d-flex align-items-center gap-2">
                                <span class="z-avatar z-avatar-sm z-avatar-soft rounded-2">{{ $ws->initials }}</span>
                                <div><div class="fw-600 fs-7">{{ $ws->name }}</div><div class="fs-8 text-muted text-capitalize">{{ $ws->pivot->role }}</div></div>
                            </div>
                            @if($ws->id !== $user->current_workspace_id)
                                <form method="POST" action="{{ route('workspaces.switch', $ws) }}">@csrf <button class="btn btn-sm btn-white">Switch</button></form>
                            @else
                                <span class="z-pill z-pill-primary">Current</span>
                            @endif
                        </div>
                    @endforeach
                    <button type="button" class="btn btn-soft-primary btn-sm mt-3 w-100" data-bs-toggle="modal" data-bs-target="#newWorkspaceModal"><x-icon name="plus" /> New workspace</button>
                </div>
            </div>
        </div>
    </div>
@endsection
