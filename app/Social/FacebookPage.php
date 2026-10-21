<?php

namespace App\Social;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Publishing to a Facebook Page through the Graph API with a Page access token
 * (pages_manage_posts + pages_read_engagement). Separate from the Inbox drivers, which
 * read messages for workspaces; this is Zonseob talking about itself.
 */
class FacebookPage
{
    public const API = 'https://graph.facebook.com/v21.0';

    public function __construct(protected string $pageId, protected string $token) {}

    /** Publishes a photo with a caption. Returns the Graph id of the resulting post. */
    public function publishPhoto(string $path, string $message): string
    {
        $response = Http::withToken($this->token)
            ->attach('source', file_get_contents($path), basename($path))
            ->post(self::API."/{$this->pageId}/photos", ['message' => $message, 'published' => 'true']);

        $data = $this->body($response);

        return (string) ($data['post_id'] ?? $data['id']);
    }

    /** Publishes a text post, optionally with a link preview. Returns the Graph id. */
    public function publishText(string $message, ?string $link = null): string
    {
        $response = Http::withToken($this->token)
            ->post(self::API."/{$this->pageId}/feed", array_filter(['message' => $message, 'link' => $link]));

        return (string) $this->body($response)['id'];
    }

    /** The Page's name, which proves the token works for this Page. */
    public function name(): string
    {
        $response = Http::withToken($this->token)->get(self::API."/{$this->pageId}", ['fields' => 'name']);

        return (string) ($this->body($response)['name'] ?? '');
    }

    /**
     * Turns a short-lived user token from Graph API Explorer into a long-lived Page token:
     * exchange the user token, then read the Page's own token with it.
     */
    public static function exchangeForPageToken(string $appId, string $appSecret, string $userToken, string $pageId): string
    {
        $longLived = Http::get(self::API.'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $userToken,
        ]);
        $userToken = (string) (self::decode($longLived)['access_token'] ?? '');
        if ($userToken === '') {
            throw new SocialException('Facebook did not return a long-lived token.');
        }

        return self::pageTokenFor($userToken, $pageId);
    }

    /**
     * The Page's own token, read with whatever token was pasted. Explorer hands out a user token
     * by default, and posting with one fails with "(#200) If posting to a group..."; asking the
     * Page for its token turns a user token into a Page token and returns a Page token as it is.
     */
    public static function pageTokenFor(string $token, string $pageId): string
    {
        $page = Http::withToken($token)->get(self::API."/{$pageId}", ['fields' => 'access_token,name']);
        $pageToken = (string) (self::decode($page)['access_token'] ?? '');
        if ($pageToken === '') {
            throw new SocialException('That account cannot manage this Page: no Page token was returned. Make sure you are an admin of the Page and granted pages_manage_posts.');
        }

        return $pageToken;
    }

    /** @return array<string, mixed> */
    protected function body(Response $response): array
    {
        return self::decode($response);
    }

    /** @return array<string, mixed> */
    protected static function decode(Response $response): array
    {
        $data = $response->json() ?? [];
        if ($response->failed() || isset($data['error'])) {
            $error = $data['error'] ?? [];
            $message = $error['message'] ?? ('HTTP '.$response->status());
            $code = isset($error['code']) ? " (code {$error['code']})" : '';

            throw new SocialException('Facebook: '.$message.$code);
        }

        return $data;
    }
}
