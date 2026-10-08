{{-- "Continue with Google / Microsoft" (when configured) and, on the sign-in page, "Sign in with a passkey". --}}
@inject('sso', 'App\Support\SingleSignOn')
@php($providers = $sso->configured())
@php($withPasskey = ($passkey ?? false) && Route::has('passkey.login'))

@if($providers || $withPasskey)
    <div class="d-grid gap-2 mb-3">
        @foreach($providers as $provider => $label)
            <a href="{{ route('sso.redirect', $provider) }}" class="btn btn-white btn-lg d-flex align-items-center justify-content-center gap-2" data-sso="{{ $provider }}">
                @if($provider === 'google')
                    <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
                @else
                    <svg width="18" height="18" viewBox="0 0 21 21" aria-hidden="true"><path fill="#F25022" d="M1 1h9v9H1z"/><path fill="#7FBA00" d="M11 1h9v9h-9z"/><path fill="#00A4EF" d="M1 11h9v9H1z"/><path fill="#FFB900" d="M11 11h9v9h-9z"/></svg>
                @endif
                <span>Continue with {{ $label }}</span>
            </a>
        @endforeach

        @if($withPasskey)
            <div x-data="passkeyLogin(@js(route('passkey.login-options')), @js(route('passkey.login')))" x-show="supported" x-cloak>
                <button type="button" class="btn btn-white btn-lg w-100 d-flex align-items-center justify-content-center gap-2" @click="signIn()" :disabled="busy" data-passkey-login>
                    <x-icon name="key-round" /> <span x-text="busy ? 'Waiting for your passkey…' : 'Sign in with a passkey'">Sign in with a passkey</span>
                </button>
                <div class="text-danger fs-7 mt-1" x-show="error" x-text="error"></div>
            </div>
        @endif
    </div>

    <div class="d-flex align-items-center gap-3 text-muted fs-8 mb-3" @if(! $providers) x-data="{ supported: window.PublicKeyCredential !== undefined && window.isSecureContext }" x-show="supported" x-cloak @endif>
        <hr class="flex-grow-1 my-0"><span>or use your email</span><hr class="flex-grow-1 my-0">
    </div>
@endif
