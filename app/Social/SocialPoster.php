<?php

namespace App\Social;

use App\Models\Setting;
use App\Models\SocialPost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Runs the Facebook series: knows the Page and token (platform settings, with .env fallbacks),
 * works out when a post is due from the posting times, and publishes the next post in the
 * planner's order with its card. Every attempt is recorded as a SocialPost.
 */
class SocialPoster
{
    public const CHANNEL = 'facebook';

    public function __construct(protected PostPlanner $planner, protected PostCard $card) {}

    /** @return array{page_id: string, enabled: bool, times: string, timezone: string, has_token: bool, token_source: string, long_lived: bool, app_id: string, has_app_secret: bool} */
    public function settings(): array
    {
        $stored = Setting::platform('facebook.page_token');

        return [
            'page_id' => (string) (Setting::platform('facebook.page_id') ?: config('zonseo.facebook.page_id', '')),
            'enabled' => (bool) Setting::platform('facebook.enabled', false),
            'times' => (string) (Setting::platform('facebook.times') ?: config('zonseo.facebook.times', '09:00,15:00')),
            'timezone' => (string) (Setting::platform('facebook.timezone') ?: config('zonseo.facebook.timezone', 'Africa/Johannesburg')),
            'has_token' => $this->token() !== null,
            'token_source' => $stored ? 'settings' : (config('zonseo.facebook.page_token') ? 'env' : 'none'),
            // Tokens saved before this flag existed, or from .env, are assumed fine.
            'long_lived' => (bool) Setting::platform('facebook.long_lived', true),
            'app_id' => (string) Setting::platform('facebook.app_id', ''),
            'has_app_secret' => $this->appSecret() !== null,
        ];
    }

    public function isConfigured(): bool
    {
        $s = $this->settings();

        return $s['page_id'] !== '' && $s['has_token'];
    }

    public function isEnabled(): bool
    {
        return $this->isConfigured() && $this->settings()['enabled'];
    }

    /**
     * @param  array{page_id?: string, enabled?: bool, times?: string, timezone?: string}  $values
     */
    public function saveSettings(array $values): void
    {
        foreach (['page_id', 'times', 'timezone'] as $key) {
            if (array_key_exists($key, $values)) {
                $this->put('facebook.'.$key, trim((string) $values[$key]));
            }
        }
        if (array_key_exists('enabled', $values)) {
            $this->put('facebook.enabled', (bool) $values['enabled']);
        }
    }

    /**
     * Stores the Page token encrypted with APP_KEY; an empty string removes it. $longLived is
     * false for a Page token made without the app secret, which Facebook expires within hours.
     */
    public function saveToken(string $token, bool $longLived = true): void
    {
        $this->put('facebook.page_token', $token === '' ? null : Crypt::encryptString($token));
        $this->put('facebook.long_lived', $longLived);
    }

    /**
     * Turns whatever token was pasted into a Page token for the saved Page and stores it: through
     * the long-lived exchange when the app secret is known, otherwise a Page token that expires
     * within hours. Returns the Page's name. Nothing is stored when Facebook refuses.
     */
    public function connect(string $token): string
    {
        $pageId = $this->settings()['page_id'];
        if ($pageId === '') {
            throw new SocialException('Save the Page ID first.');
        }

        $appId = $this->settings()['app_id'];
        $secret = $this->appSecret();
        $longLived = $appId !== '' && $secret !== null;
        $pageToken = $longLived
            ? FacebookPage::exchangeForPageToken($appId, $secret, $token, $pageId)
            : FacebookPage::pageTokenFor($token, $pageId);
        $name = (new FacebookPage($pageId, $pageToken))->name();
        $this->saveToken($pageToken, $longLived);

        return $name;
    }

    /** Remembers the Meta app so a fresh token only needs pasting; a blank secret keeps the stored one. */
    public function saveApp(string $appId, string $appSecret = ''): void
    {
        $this->put('facebook.app_id', trim($appId));
        if (trim($appSecret) !== '') {
            $this->put('facebook.app_secret', Crypt::encryptString(trim($appSecret)));
        }
    }

    public function appSecret(): ?string
    {
        $stored = Setting::platform('facebook.app_secret');
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function token(): ?string
    {
        $stored = Setting::platform('facebook.page_token');
        if (is_string($stored) && $stored !== '') {
            try {
                return Crypt::decryptString($stored);
            } catch (DecryptException) {
                return null;
            }
        }

        $env = config('zonseo.facebook.page_token');

        return is_string($env) && $env !== '' ? $env : null;
    }

    public function page(): FacebookPage
    {
        $s = $this->settings();
        if ($s['page_id'] === '' || ($token = $this->token()) === null) {
            throw new SocialException('Facebook is not connected: set the Page ID and a Page access token first.');
        }

        return new FacebookPage($s['page_id'], $token);
    }

    /** @return list<string> "HH:MM" posting times, validated and sorted */
    public function times(): array
    {
        $times = collect(explode(',', $this->settings()['times']))
            ->map(fn ($t) => trim($t))
            ->filter(fn ($t) => preg_match('/^\d{2}:\d{2}$/', $t) === 1)
            ->unique()->sort()->values()->all();

        return $times ?: ['09:00'];
    }

    /**
     * The earliest posting time today (in the posting timezone) that has passed and has not
     * been filled with a post yet, or null when nothing is due.
     */
    public function dueSlot(?CarbonInterface $now = null): ?CarbonImmutable
    {
        $tz = $this->settings()['timezone'];
        $now = CarbonImmutable::instance($now ?? now())->setTimezone($tz);

        // Only the latest slot that has passed counts: earlier missed slots are skipped, not caught up in a burst.
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

        // A slot counts as filled once anything was attempted for it, or anything (e.g. "Post next now") went out since; a failure waits for the next slot.
        $attempted = SocialPost::query()->where('channel', self::CHANNEL)
            ->where('scheduled_for', '>=', $latest->utc())->exists();

        return $attempted ? null : $latest;
    }

    /** Publishes the next post in the series, recording success or failure. Null when the series is finished. */
    public function postNext(?CarbonInterface $slot = null): ?SocialPost
    {
        $draft = $this->planner->next(self::CHANNEL);
        if (! $draft) {
            return null;
        }

        return $this->publish($draft, $slot);
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
            $post->external_id = $imagePath
                ? $page->publishPhoto($imagePath, $draft->message)
                : $page->publishText($draft->message, PostWriter::SITE);
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
     * Runs the scheduled check without cron: called when a web request finishes and from the
     * signed tick URL. Throttled to once every five minutes, and never throws, so a Facebook
     * problem cannot break a page. Returns the post it published or failed, if any.
     */
    public function tick(): ?SocialPost
    {
        try {
            if (! Cache::add('social:facebook:tick', 1, 300)) {
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

    /** The secret in the public tick URL, derived from APP_KEY so it needs no extra setting. */
    public function tickSignature(): string
    {
        return hash_hmac('sha256', 'social-tick', (string) config('app.key'));
    }

    public function tickUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/facebook/tick/'.$this->tickSignature();
    }

    protected function put(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['workspace_id' => null, 'key' => $key], ['value' => ['v' => $value]]);
    }
}
