<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Social media scheduling & inbox: a post's networks are read however they are typed ("FB, IG and
 * Twitter"), and Instagram and TikTok posts need an image or video. A scheduled post needs a publish
 * date that hasn't passed and goes out on it each morning; once published it records its reach and
 * engagement. Inbox messages are answered with a reply, which times the response.
 */
class SocialMediaLogic extends AppLogic
{
    /**
     * Network names, by the ways people type them.
     */
    protected const NETWORKS = [
        'facebook' => 'Facebook', 'fb' => 'Facebook', 'instagram' => 'Instagram', 'ig' => 'Instagram', 'insta' => 'Instagram',
        'x' => 'X', 'twitter' => 'X', 'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok',
    ];

    /**
     * Networks that only take posts with an image or video.
     */
    protected const NEEDS_MEDIA = ['Instagram', 'TikTok'];

    /**
     * Most hashtags Instagram allows on a post.
     */
    protected const INSTAGRAM_HASHTAGS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'posts' ? $this->validatePost($payload, $existing) : $this->validateMessage($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validatePost(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing?->status === 'published' && $status !== 'published') {
            return ['status' => 'This post has been published.'];
        }
        if ($status === 'published' && $existing?->status !== 'published') {
            return ['status' => 'Use "Publish now" so the time is recorded.'];
        }
        $networks = $this->networks((string) ($data['networks'] ?? ''));
        if (is_string($networks)) {
            $errors['data.networks'] = $networks;
        } elseif ($missing = array_values(array_intersect($networks, self::NEEDS_MEDIA))) {
            if (blank($data['media_url'] ?? null) && $status !== 'idea') {
                $errors['data.media_url'] = implode(' and ', $missing).' '.(count($missing) === 1 ? 'needs' : 'need').' an image or video.';
            }
        }
        if (is_array($networks) && in_array('Instagram', $networks, true) && preg_match_all('/#\w+/u', (string) $payload['title']) > self::INSTAGRAM_HASHTAGS) {
            $errors['title'] = 'Instagram allows at most '.self::INSTAGRAM_HASHTAGS.' hashtags.';
        }
        if ($status === 'scheduled') {
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Choose when to publish it.';
            } elseif (Carbon::parse($payload['occurs_on'])->lt(today()) && $existing?->status !== 'scheduled') {
                $errors['occurs_on'] = 'The publish date has passed.';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateMessage(array $payload, ?Record $existing): array
    {
        if ($payload['status'] === 'replied' && blank($payload['data']['reply'] ?? null)) {
            return ['data.reply' => 'Write the reply.'];
        }
        if ($existing?->status === 'closed' && $payload['status'] === 'new') {
            return ['status' => 'This conversation is closed.'];
        }

        return [];
    }

    /**
     * A post's networks by their proper names, or the problem with them.
     *
     * @return list<string>|string
     */
    public function networks(string $text): array|string
    {
        $names = [];
        foreach (preg_split('/\s*(?:,|\/|;|&|\band\b)\s*/i', trim($text)) ?: [] as $word) {
            $key = strtolower(preg_replace('/[^A-Za-z]/', '', $word));
            if ($key === '') {
                continue;
            }
            if (! isset(self::NETWORKS[$key])) {
                return 'We don\'t post to '.trim($word).'; use Facebook, Instagram, X, LinkedIn or TikTok.';
            }
            $names[] = self::NETWORKS[$key];
        }

        return array_values(array_unique($names));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'posts') {
            $networks = $this->networks((string) $record->value('networks'));
            if (is_array($networks) && $networks) {
                $this->put($record, ['networks' => implode(', ', $networks)]);
            }

            return;
        }
        $record->occurs_on ??= today();
        if ($record->isDirty('status') && $record->status === 'replied' && ! $record->value('_replied_at')) {
            $this->put($record, ['_replied_at' => now()->toDateTimeString(), '_response_minutes' => $record->created_at ? (int) $record->created_at->diffInMinutes(now()) : 0]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'messages') {
            return match ($record->status) {
                'new' => [
                    'reply' => ['label' => 'Reply', 'icon' => 'reply', 'fields' => [['name' => 'reply', 'label' => 'Reply', 'type' => 'textarea']]],
                    'close' => ['label' => 'Close without reply', 'icon' => 'archive'],
                ],
                'replied' => ['close' => ['label' => 'Close', 'icon' => 'archive']],
                default => [],
            };
        }

        return match ($record->status) {
            'idea', 'draft' => [
                'publish' => ['label' => 'Publish now', 'icon' => 'send'],
                'schedule' => ['label' => 'Schedule', 'icon' => 'calendar-clock', 'fields' => [['name' => 'publish_on', 'label' => 'Publish on', 'type' => 'date', 'value' => today()->addDay()->toDateString()]]],
            ],
            'scheduled' => ['publish' => ['label' => 'Publish now', 'icon' => 'send'], 'unschedule' => ['label' => 'Back to draft', 'icon' => 'undo-2']],
            'published' => ['results' => ['label' => 'Record results', 'icon' => 'heart', 'fields' => [
                ['name' => 'reach', 'label' => 'Reach', 'type' => 'number', 'value' => $record->value('reach') ?? 0],
                ['name' => 'engagement', 'label' => 'Engagements', 'type' => 'number', 'value' => $record->value('engagement') ?? 0],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'reply':
                $reply = trim($request->validate(['reply' => ['required', 'string', 'max:2000']])['reply']);
                $record->update(['status' => 'replied', 'data' => [...$record->data, 'reply' => $reply]]);

                return 'Replied to '.($record->value('from_handle') ?: $record->title).' after '.$this->minutes((int) $record->value('_response_minutes')).'.';
            case 'close':
                $record->update(['status' => 'closed']);

                return $record->title.' closed.';
            case 'publish':
                $this->ensurePublishable($record);
                $this->publish($record);

                return $record->title.' published to '.$record->value('networks').'.';
            case 'schedule':
                $date = $request->validate(['publish_on' => ['required', 'date', 'after_or_equal:today']])['publish_on'];
                $this->ensurePublishable($record);
                $record->update(['status' => 'scheduled', 'occurs_on' => $date]);

                return $record->title.' scheduled for '.Carbon::parse($date)->format('d M Y').'.';
            case 'unschedule':
                $record->update(['status' => 'draft']);

                return $record->title.' is back in draft.';
            default:
                $values = $request->validate(['reach' => ['required', 'integer', 'min:0'], 'engagement' => ['required', 'integer', 'min:0']]);
                $record->update(['data' => [...$record->data, 'reach' => (int) $values['reach'], 'engagement' => (int) $values['engagement']]]);

                return $record->title.': '.$this->rate($values['engagement'], $values['reach']).' engagement.';
        }
    }

    /**
     * Refuse to publish a post its networks won't take.
     */
    protected function ensurePublishable(Record $post): void
    {
        $networks = $this->networks((string) $post->value('networks'));
        if (is_string($networks) || ! $networks) {
            throw ValidationException::withMessages(['networks' => is_string($networks) ? $networks : 'Choose where to post it.']);
        }
        $missing = array_values(array_intersect($networks, self::NEEDS_MEDIA));
        if ($missing && blank($post->value('media_url'))) {
            throw ValidationException::withMessages(['media_url' => implode(' and ', $missing).' '.(count($missing) === 1 ? 'needs' : 'need').' an image or video.']);
        }
    }

    /**
     * Mark a post published now.
     */
    protected function publish(Record $post): void
    {
        $post->update(['status' => 'published', 'occurs_on' => today(), 'data' => [...$post->data, '_published_at' => now()->toDateTimeString()]]);
    }

    public function daily(Workspace $workspace): int
    {
        $due = $this->records('posts')->where('status', 'scheduled')->whereDate('occurs_on', '<=', today()->toDateString())->get();
        $due->each(fn (Record $post) => $this->publish($post));

        return $due->count();
    }

    /**
     * A share as a percentage, or a dash when there is nothing to divide by.
     */
    protected function rate(float|int $part, float|int $whole): string
    {
        return $whole > 0 ? number_format($part / $whole * 100, 1).'%' : '—';
    }

    /**
     * Minutes in words: "45 min" or "3 h 5 min".
     */
    protected function minutes(int $minutes): string
    {
        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    }

    public function homeCards(): array
    {
        $waiting = $this->records('messages')->where('status', 'new')->orderBy('created_at')->get();
        $upcoming = $this->records('posts')->where('status', 'scheduled')->whereDate('occurs_on', '<=', today()->addDays(7)->toDateString())->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for a reply', 'icon' => 'inbox', 'empty' => 'Inbox clear.',
                'rows' => $waiting->map(fn (Record $message) => [
                    'label' => $message->value('from_handle') ?: $message->title, 'sub' => ucfirst((string) $message->value('network')).' · '.str_replace('_', ' ', (string) $message->value('type')),
                    'value' => $this->minutes((int) $message->created_at?->diffInMinutes(now())), 'href' => $message->url(),
                    'tone' => $message->created_at?->lt(now()->subDay()) ? 'danger' : null,
                ])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Going out this week', 'icon' => 'calendar-days', 'empty' => 'Nothing scheduled.',
                'rows' => $upcoming->map(fn (Record $post) => ['label' => str($post->title)->limit(60)->toString(), 'sub' => $post->value('networks'), 'value' => $post->occurs_on?->format('D d M'), 'href' => $post->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $posts = $this->dated('posts', $from, $to)->where('status', 'published')->orderBy('occurs_on')->get();
        $messages = $this->dated('messages', $from, $to)->get();

        $published = $posts->map(fn (Record $post) => [
            str($post->title)->limit(60)->toString(), (string) $post->value('networks'), $post->occurs_on?->format('d M Y') ?? '',
            number_format($this->number($post, 'reach')), number_format($this->number($post, 'engagement')), $this->rate($this->number($post, 'engagement'), $this->number($post, 'reach')),
        ])->values()->all();

        $inbox = $messages->groupBy(fn (Record $message) => (string) $message->value('network'))->sortKeys()
            ->map(function (Collection $group, string $network) {
                $answered = $group->filter(fn (Record $message) => $message->value('_response_minutes') !== null);

                return [ucfirst($network), $group->count(), $answered->count(), $answered->isNotEmpty() ? $this->minutes((int) round($answered->avg(fn (Record $message) => (int) $message->value('_response_minutes')))) : '—', $group->where('status', 'new')->count()];
            })->values()->all();

        return [
            ['title' => 'Published posts', 'columns' => ['Post', 'Networks', 'Published', 'Reach', 'Engagements', 'Engagement rate'], 'rows' => $published],
            ['title' => 'Inbox by network', 'columns' => ['Network', 'Messages', 'Replied', 'Average response', 'Waiting'], 'rows' => $inbox],
        ];
    }
}
