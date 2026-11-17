<?php

namespace App\Social;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Publishing to a LinkedIn Page (organization) through the Posts API with a member token that
 * has rw_organization_admin + w_organization_social and belongs to an admin of the Page.
 */
class LinkedInPage
{
    public const API = 'https://api.linkedin.com/rest/';

    /** LinkedIn versions the REST API by month and supports each version for about a year. */
    public const VERSION = '202609';

    public function __construct(protected string $organizationId, protected string $token)
    {
    }

    public function organizationUrn(): string
    {
        return 'urn:li:organization:' . $this->organizationId;
    }

    /**
     * The Pages the token's member is an admin of, as [id => name], which proves the token works.
     *
     * @return array<string, string>
     */
    public function adminPages(): array
    {
        $acls = self::decode($this->api()->timeout(30)->get(self::API . 'organizationAcls', [
            'q' => 'roleAssignee',
            'state' => 'APPROVED',
        ]));

        $pages = [];
        foreach ($acls['elements'] ?? [] as $acl) {
            if (in_array($acl['role'] ?? '', ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR'], true)) {
                $id = Str::afterLast((string)($acl['organization'] ?? ''), ':');
                $pages[$id] = (string)($this->api()->timeout(30)->get(self::API . "organizations/{$id}")->json('localizedName') ?? $id);
            }
        }

        return $pages;
    }

    /** The Page's name, or an error when the token is not an admin of this Page. */
    public function name(): string
    {
        $pages = $this->adminPages();
        if (!isset($pages[$this->organizationId])) {
            $others = $pages ? ' It manages: ' .collect($pages)->map(fn ($name, $id) => "{$name} ({$id})")->implode(', ') : '';
            throw new SocialException('LinkedIn: the token\'s account is not an admin of Page ' . $this->organizationId . '.' . $others, 403);
        }

        return $pages[$this->organizationId];
    }

    /** Uploads an image (JPEG, PNG or GIF bytes) and returns its urn:li:image id. */
    public function uploadImage(string $bytes, string $mime): string
    {
        $init = self::decode($this->api()->timeout(30)->post(self::API . 'images?action=initializeUpload', [
            'initializeUploadRequest' => ['owner' => $this->organizationUrn()],
        ]));
        $uploadUrl = $init['value']['uploadUrl'] ?? null;
        if (!$uploadUrl) {
            throw new SocialException('LinkedIn: no image upload URL was returned.');
        }

        self::decode(Http::withToken($this->token)->timeout(120)->withBody($bytes, $mime)->put($uploadUrl));

        return (string)$init['value']['image'];
    }

    /**
     * Publishes a post on the Page, with an uploaded image or else a link card. $text is in
     * LinkedIn's "little text" format, see LinkedInPoster::commentary(). Returns the post URN.
     */
    public function publish(string $text, string $title, ?string $imageUrn, string $link): string
    {
        $response = $this->api()->timeout(60)->post(self::API . 'posts', [
            'author' => $this->organizationUrn(),
            'commentary' => $text,
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'content' => $imageUrn
                ? ['media' => ['id' => $imageUrn, 'title' => Str::limit($title, 200)]]
                : ['article' => ['source' => $link, 'title' => Str::limit($title, 200)]],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ]);
        self::decode($response);

        $urn = $response->header('x-restli-id');
        if (!$urn) {
            throw new SocialException('LinkedIn: the post was not created (no post id returned).');
        }

        return $urn;
    }

    protected function api(): PendingRequest
    {
        return Http::withToken($this->token)->withHeaders([
            'LinkedIn-Version' => self::VERSION,
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
    }

    /** @return array<string, mixed> */
    protected static function decode(Response $response): array
    {
        if ($response->failed()) {
            $message = $response->json('message') ?? Str::limit($response->body(), 300) ?: 'HTTP ' . $response->status();

            throw new SocialException('LinkedIn: ' . $message . ' (HTTP ' . $response->status() . ')', $response->status());
        }

        return $response->json() ?? [];
    }
}
