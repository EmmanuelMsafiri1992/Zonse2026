<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sign-in with Google or Microsoft over plain OpenID Connect: the authorisation code flow
 * with PKCE (S256), then the provider's userinfo endpoint for the stable user id and email.
 */
class SingleSignOn
{
    /** @var array<string, string> */
    public const PROVIDERS = ['google' => 'Google', 'microsoft' => 'Microsoft'];

    /** @return array<string, string> provider key => label, only those with credentials set */
    public function configured(): array
    {
        return array_filter(self::PROVIDERS, fn (string $label, string $provider) => $this->isConfigured($provider), ARRAY_FILTER_USE_BOTH);
    }

    public function isConfigured(string $provider): bool
    {
        return isset(self::PROVIDERS[$provider])
            && filled(config("services.{$provider}.client_id"))
            && filled(config("services.{$provider}.client_secret"));
    }

    public function label(string $provider): string
    {
        return self::PROVIDERS[$provider] ?? ucfirst($provider);
    }

    public function authorizeUrl(string $provider, string $state, string $verifier): string
    {
        $query = http_build_query([
            'client_id' => config("services.{$provider}.client_id"),
            'redirect_uri' => $this->redirectUri($provider),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $this->challenge($verifier),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->endpoints($provider)['authorize'].'?'.$query;
    }

    /**
     * Swaps the code for an access token and reads who signed in.
     *
     * @return array{id: string, email: ?string, name: ?string, email_verified: bool}
     *
     * @throws RuntimeException when the provider refuses the code or answers without a user id
     */
    public function user(string $provider, string $code, string $verifier): array
    {
        $endpoints = $this->endpoints($provider);

        try {
            $token = Http::asForm()->acceptJson()->timeout(15)->post($endpoints['token'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri($provider),
                'client_id' => config("services.{$provider}.client_id"),
                'client_secret' => config("services.{$provider}.client_secret"),
                'code_verifier' => $verifier,
            ])->throw()->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('No access token was returned.');
            }

            $profile = Http::withToken($token)->acceptJson()->timeout(15)->get($endpoints['userinfo'])->throw()->json();
        } catch (RequestException|ConnectionException $e) {
            throw new RuntimeException($this->label($provider).' did not accept the sign-in.', previous: $e);
        }

        if (! is_array($profile) || blank($profile['sub'] ?? null)) {
            throw new RuntimeException($this->label($provider).' did not say who signed in.');
        }

        $email = filled($profile['email'] ?? null) ? strtolower(trim((string) $profile['email'])) : null;

        return [
            'id' => (string) $profile['sub'],
            'email' => $email,
            'name' => filled($profile['name'] ?? null) ? (string) $profile['name'] : null,
            // Only Google vouches for the address; Microsoft lets tenants set any email, so it is never trusted for matching.
            'email_verified' => $provider === 'google' && $email !== null && filter_var($profile['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    public function redirectUri(string $provider): string
    {
        return route('sso.callback', $provider);
    }

    /** @return array{authorize: string, token: string, userinfo: string} */
    protected function endpoints(string $provider): array
    {
        if ($provider === 'microsoft') {
            $base = 'https://login.microsoftonline.com/'.rawurlencode((string) config('services.microsoft.tenant', 'common')).'/oauth2/v2.0';

            return ['authorize' => $base.'/authorize', 'token' => $base.'/token', 'userinfo' => 'https://graph.microsoft.com/oidc/userinfo'];
        }

        return [
            'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token' => 'https://oauth2.googleapis.com/token',
            'userinfo' => 'https://openidconnect.googleapis.com/v1/userinfo',
        ];
    }

    protected function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
