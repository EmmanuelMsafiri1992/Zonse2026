<?php

namespace App\Social;

use App\Models\Setting;
use App\Models\SocialPost;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Runs the same "how Zonseob works" series as SocialPoster, on the Zonseob LinkedIn Page: its own
 * Page, token and posting times (platform settings linkedin.*), its own progress through the
 * planner (SocialPost rows with channel "linkedin"), and the same branded cards.
 *
 * LinkedIn Development tier tokens last 60 days with no refresh token, so the admin page shows
 * when the saved one runs out and a new one is pasted there.
 */
class LinkedInPoster
{
    public const CHANNEL = 'linkedin';

    public const TOKEN_DAYS = 60;

    public function __construct(protected PostPlanner $planner, protected PostCard $card, protected SocialPoster $facebook) {}

    /** @return array{organization_id: string, enabled: bool, times: string, timezone: string, has_token: bool, token_expires_at: ?string} */
    public function settings(): array
    {
        $savedAt = Setting::platform('linkedin.token_saved_at');

        return [
            'organization_id' => (string) Setting::platform('linkedin.organization_id', ''),
            'enabled' => (bool) Setting::platform('linkedin.enabled', false),
            'times' => (string) (Setting::platform('linkedin.times') ?: '10:30,16:30'),
            // One timezone for both channels, set on the Facebook page
            'timezone' => $this->facebook->settings()['timezone'],
            'has_token' => $this->token() !== null,
            'token_expires_at' => $savedAt ? Carbon::parse($savedAt)->addDays(self::TOKEN_DAYS)->toDateString() : null,
        ];
    }

    public function isConfigured(): bool
    {
        $s = $this->settings();

        return $s['organization_id'] !== '' && $s['has_token'];
    }

    public function isEnabled(): bool
    {
        return $this->isConfigured() && $this->settings()['enabled'];
    }

    /** @param  array{organization_id?: string, enabled?: bool, times?: string}  $values */
    public function saveSettings(array $values): void
    {
        foreach (['organization_id', 'times'] as $key) {
            if (array_key_exists($key, $values)) {
                $this->put('linkedin.'.$key, trim((string) $values[$key]));
            }
        }
        if (array_key_exists('enabled', $values)) {
            $this->put('linkedin.enabled', (bool) $values['enabled']);
        }
    }

    /** Stores the token encrypted with APP_KEY; an empty string removes it. */
    public function saveToken(string $token): void
    {
        $this->put('linkedin.token', $token === '' ? null : Crypt::encryptString($token));
        $this->put('linkedin.token_saved_at', $token === '' ? null : now()->toDateTimeString());
    }

    /**
     * Checks a pasted token can post as the Page and stores it. With no Page ID saved yet, the
     * token's only Page (or the one called Zonseob) is used. Returns the Page's name; nothing is
     * stored when LinkedIn refuses.
     */
    public function connect(string $token): string
    {
        $organizationId = $this->settings()['organization_id'];
        if ($organizationId === '') {
            $pages = collect((new LinkedInPage('', $token))->adminPages());
            $organizationId = (string) ($pages->count() === 1 ? $pages->keys()->first()
                : $pages->filter(fn ($name) => stripos($name, 'zonseo') !== false)->keys()->first());
            if ($organizationId === '') {
                throw new SocialException('Enter the LinkedIn Page ID. The token manages: '
                    .($pages->map(fn ($name, $id) => "{$name} ({$id})")->implode(', ') ?: 'no Pages'));
            }
        }

        $name = (new LinkedInPage($organizationId, $token))->name();
        $this->saveSettings(['organization_id' => $organizationId]);
        $this->saveToken($token);

        return $name;
    }

    public function token(): ?string
    {
        $stored = Setting::platform('linkedin.token');
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function page(): LinkedInPage
    {
        $s = $this->settings();
        if ($s['organization_id'] === '' || ($token = $this->token()) === null) {
            throw new SocialException('LinkedIn is not connected: paste an access token first.');
        }

        return new LinkedInPage($s['organization_id'], $token);
    }

    /** @return list<string> "HH:MM" posting times, validated and sorted */
    public function times(): array
    {
        $times = collect(explode(',', $this->settings()['times']))
            ->map(fn ($t) => trim($t))
            ->filter(fn ($t) => preg_match('/^\d{2}:\d{2}$/', $t) === 1)
            ->unique()->sort()->values()->all();

        return $times ?: ['10:30'];
    }

    /** The latest posting time today that has passed and has no LinkedIn attempt yet, as in SocialPoster::dueSlot(). */
    public function dueSlot(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($this->settings()['timezone']);

        $latest = null;
        foreach ($this->times() as $time) {
            [$h, $m] = array_map('intval', explode(':', $time));
            $slot = $now->setTime($h, $m);
            if ($slot->lessThanOrEqualTo($now) && (! $latest || $slot->greaterThan($latest))) {
                $latest = $slot;
            }
        }
        if (! $latest) {
            return null;
        }

        $attempted = SocialPost::query()->where('channel', self::CHANNEL)
            ->where('scheduled_for', '>=', $latest->utc())->exists();

        return $attempted ? null : $latest;
    }

    /** @return Collection<int, PostDraft> */
    public function remaining(): Collection
    {
        return $this->planner->remaining(self::CHANNEL);
    }

    public function postNext(?CarbonInterface $slot = null): ?SocialPost
    {
        $draft = $this->planner->next(self::CHANNEL);

        return $draft ? $this->publish($draft, $slot) : null;
    }

    public function publish(PostDraft $draft, ?CarbonInterface $slot = null): SocialPost
    {
        $post = new SocialPost([
            'channel' => self::CHANNEL,
            'kind' => $draft->kind,
            'key' => $draft->key,
            'title' => $draft->title,
            'message' => $draft->message,
            'scheduled_for' => $slot ? CarbonImmutable::instance($slot)->utc() : now(),
        ]);

        try {
            $page = $this->page();
            $imagePath = $this->card->render($draft, storage_path('app/social'));
            $post->image_path = $imagePath ? 'social/'.basename($imagePath) : null;
            $imageUrn = $imagePath ? $page->uploadImage((string) file_get_contents($imagePath), 'image/png') : null;
            $post->external_id = $page->publish(self::commentary($draft->message), $draft->title, $imageUrn, PostWriter::SITE);
            $post->status = SocialPost::STATUS_POSTED;
            $post->posted_at = now();
        } catch (Throwable $e) {
            $post->status = SocialPost::STATUS_FAILED;
            $post->error = mb_substr($e->getMessage(), 0, 1000);
        }

        $post->save();

        return $post;
    }

    /**
     * Turns the post text into LinkedIn's "little text" format, where characters like ( ) # @ * _
     * are markup: escape them all, then turn the #tags back into real hashtags.
     */
    public static function commentary(string $message): string
    {
        $escaped = preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $message);

        return preg_replace('/\\\\#(\w+)/u', '{hashtag|\\\\#|$1}', $escaped);
    }

    /** Called after web requests and from the signed tick URL; at most every five minutes, and never throws. */
    public function tick(): ?SocialPost
    {
        try {
            if (! Cache::add('social:linkedin:tick', 1, 300)) {
                return null;
            }
            if (! $this->isEnabled()) {
                return null;
            }
            $slot = $this->dueSlot();

            return $slot ? $this->postNext($slot) : null;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    protected function put(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['workspace_id' => null, 'key' => $key], ['value' => ['v' => $value]]);
    }
}
