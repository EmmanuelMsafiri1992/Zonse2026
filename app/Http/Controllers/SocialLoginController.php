<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Audit;
use App\Support\SingleSignOn;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Continue with Google / Microsoft". Guests sign in (or get an account made for them);
 * signed-in users link the provider from their profile. Accounts are matched on the
 * provider's own user id, never on email alone, except a Google-verified email
 * which may join an existing account the first time.
 */
class SocialLoginController extends Controller
{
    public function __construct(protected SingleSignOn $sso) {}

    public function redirect(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->sso->isConfigured($provider), 404);

        $state = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('sso', [
            'provider' => $provider,
            'state' => $state,
            'verifier' => $verifier,
            'link' => Auth::check(),
            'remember' => $request->boolean('remember'),
        ]);

        return redirect()->away($this->sso->authorizeUrl($provider, $state, $verifier));
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless($this->sso->isConfigured($provider), 404);
        $label = $this->sso->label($provider);
        $pending = $request->session()->pull('sso');

        if (! is_array($pending) || $pending['provider'] !== $provider || ! hash_equals((string) $pending['state'], (string) $request->query('state'))) {
            return $this->fail('That sign-in link has expired. Please try again.');
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            return $this->fail("{$label} sign-in was cancelled.");
        }

        try {
            $identity = $this->sso->user($provider, (string) $request->query('code'), (string) $pending['verifier']);
        } catch (RuntimeException $e) {
            report($e);

            return $this->fail($e->getMessage().' Please try again.');
        }

        if ($pending['link'] && Auth::check()) {
            return $this->link($request->user(), $provider, $identity);
        }

        return $this->signIn($request, $provider, $identity, (bool) $pending['remember']);
    }

    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $request->user()->socialAccounts()->where('provider', $provider)->firstOrFail()->delete();
        Audit::log('access', 'sso-unlinked', 'Unlinked their '.$this->sso->label($provider).' sign-in', $request->user());

        return back()->with('flash', ['type' => 'success', 'message' => $this->sso->label($provider).' is no longer linked to your account.']);
    }

    /** @param  array{id: string, email: ?string, name: ?string, email_verified: bool}  $identity */
    protected function link(User $user, string $provider, array $identity): RedirectResponse
    {
        $label = $this->sso->label($provider);
        $taken = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $identity['id'])->first();
        if ($taken && $taken->user_id !== $user->id) {
            return $this->fail("That {$label} account is already linked to another user.");
        }

        $user->socialAccounts()->updateOrCreate(['provider' => $provider], [
            'provider_user_id' => $identity['id'],
            'email' => $identity['email'],
            'name' => $identity['name'],
        ]);
        Audit::log('access', 'sso-linked', "Linked their {$label} sign-in", $user, ['provider_email' => $identity['email']], $user->currentWorkspace);

        return redirect()->route('profile.edit')->with('flash', ['type' => 'success', 'message' => "You can now sign in with {$label}."]);
    }

    /** @param  array{id: string, email: ?string, name: ?string, email_verified: bool}  $identity */
    protected function signIn(Request $request, string $provider, array $identity, bool $remember): RedirectResponse
    {
        $label = $this->sso->label($provider);
        $account = SocialAccount::query()->with('user')->where('provider', $provider)->where('provider_user_id', $identity['id'])->first();

        if (! $account) {
            $existing = $identity['email'] ? User::query()->where('email', $identity['email'])->first() : null;

            if ($existing && ! $identity['email_verified']) {
                return $this->fail("An account with {$identity['email']} already exists. Sign in with your password, then link {$label} from your profile.");
            }
            if (! $existing && ! $identity['email']) {
                return $this->fail("{$label} did not share an email address, so an account could not be made.");
            }

            $account = DB::transaction(function () use ($existing, $identity, $provider) {
                $user = $existing ?? $this->createUser($identity);

                return $user->socialAccounts()->updateOrCreate(['provider' => $provider], [
                    'provider_user_id' => $identity['id'],
                    'email' => $identity['email'],
                    'name' => $identity['name'],
                ]);
            });
        }

        $account->forceFill(['last_used_at' => now(), 'email' => $identity['email'] ?? $account->email])->save();
        $user = $account->user;

        // Same as a password sign-in: a confirmed second factor still has to be passed.
        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => $remember]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    /** @param  array{id: string, email: ?string, name: ?string, email_verified: bool}  $identity */
    protected function createUser(array $identity): User
    {
        $user = User::create([
            'name' => $identity['name'] ?: Str::before((string) $identity['email'], '@'),
            'email' => $identity['email'],
            // Nobody knows this password; "Forgot password" sets a real one if they ever want it.
            'password' => Hash::make(Str::random(64)),
        ]);
        if ($identity['email_verified']) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        event(new Registered($user));

        return $user;
    }

    protected function fail(string $message): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('profile.edit')->with('flash', ['type' => 'danger', 'message' => $message]);
        }

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
