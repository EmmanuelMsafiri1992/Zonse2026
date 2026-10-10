<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Live chat & chatbot: bot replies are matched by keyword, and an active keyword belongs to one reply only.
 * A new conversation whose message contains a keyword gets that reply. Replies that don't hand over
 * answer the visitor and leave the chat waiting on them. Replies that hand over keep it open for a person.
 * Conversations move between open and waiting, and resolve. Chats left waiting for seven days resolve
 * each night.
 */
class LiveChatLogic extends AppLogic
{
    public const IDLE_DAYS = 7;

    /**
     * A reply's keywords, lower-cased and trimmed.
     *
     * @return list<string>
     */
    public static function keywords(?string $keywords): array
    {
        return array_values(array_filter(array_map(fn (string $word) => mb_strtolower(trim($word)), explode(',', (string) $keywords))));
    }

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'replies' || $payload['status'] !== 'active') {
            return [];
        }
        $others = $this->records('replies')->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get();
        foreach (self::keywords($payload['data']['keywords'] ?? null) as $word) {
            if ($twin = $others->first(fn (Record $reply) => in_array($word, self::keywords($reply->value('keywords')), true))) {
                return ['data.keywords' => '"'.$word.'" is already a keyword of the reply "'.$twin->title.'".'];
            }
        }

        return [];
    }

    /**
     * The active bot reply whose keyword appears in a message.
     */
    protected function botReply(string $message): ?Record
    {
        $message = mb_strtolower($message);

        return $message === '' ? null : $this->records('replies')->where('status', 'active')->get()
            ->first(fn (Record $reply) => collect(self::keywords($reply->value('keywords')))->contains(fn (string $word) => preg_match('/\b'.preg_quote($word, '/').'\b/u', $message)));
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'conversations') {
            return;
        }
        $record->occurs_on ??= today();
        if (! $record->exists && ($reply = $this->botReply($record->title.' '.$record->value('transcript')))) {
            $this->put($record, ['_bot_reply' => $reply->id, 'transcript' => trim($record->value('transcript')."\nBot: ".$reply->value('answer'))]);
            if (! $reply->value('hand_over') && $record->status === 'open') {
                $record->status = 'waiting';
            }
        }
        $this->put($record, ['_resolved_on' => $record->status === 'resolved' ? ($record->value('_resolved_on') ?? today()->toDateString()) : null]);
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('conversations')->where('status', 'waiting')->where('updated_at', '<', now()->subDays(self::IDLE_DAYS))->get()
            ->each(fn (Record $chat) => $chat->update(['status' => 'resolved']))->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'conversations') {
            return [];
        }
        $resolve = ['resolve' => ['label' => 'Resolve', 'icon' => 'check']];

        return match ($record->status) {
            'open' => ['wait' => ['label' => 'Waiting on customer', 'icon' => 'hourglass'], ...$resolve],
            'waiting' => ['reopen' => ['label' => 'Reopen', 'icon' => 'message-circle'], ...$resolve],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'message-circle']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $status = ['wait' => 'waiting', 'reopen' => 'open', 'resolve' => 'resolved'][$action];
        $record->update(['status' => $status]);

        return match ($status) {
            'waiting' => $record->title.' is waiting on the customer; it resolves by itself after '.self::IDLE_DAYS.' quiet days.',
            'open' => $record->title.' reopened.',
            default => $record->title.' resolved.',
        };
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'conversations' || ! ($reply = $this->records('replies')->find($record->value('_bot_reply')))) {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Answered by the bot', 'icon' => 'bot', 'empty' => '',
            'rows' => [['label' => $reply->title, 'sub' => $reply->value('answer'), 'value' => $reply->value('hand_over') ? 'handed over' : 'answered', 'href' => $reply->url()]],
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('conversations')->where('status', 'open')->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Chats', 'icon' => 'message-circle', 'stats' => [
                ['label' => 'Open', 'value' => $open->count()],
                ['label' => 'Waiting on customers', 'value' => $this->records('conversations')->where('status', 'waiting')->count()],
                ['label' => 'Resolved this week', 'value' => $this->records('conversations')->where('status', 'resolved')->get()->filter(fn (Record $chat) => $chat->value('_resolved_on') >= today()->subDays(6)->toDateString())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open with nobody on them', 'icon' => 'user-x', 'empty' => 'Every open chat has someone on it.',
                'rows' => $open->whereNull('assignee_id')->map(fn (Record $chat) => ['label' => $chat->title, 'sub' => ucfirst((string) $chat->value('channel')).($chat->value('visitor_name') ? ' · '.$chat->value('visitor_name') : ''), 'value' => $chat->occurs_on?->format('d M'), 'href' => $chat->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $replies = $this->records('replies')->get()->keyBy('id');

        return [['title' => 'Chats by channel', 'columns' => ['Channel', 'Chats', 'Resolved', 'Answered by the bot', 'Handed to a person'], 'rows' => $this->dated('conversations', $from, $to)->get()
            ->groupBy(fn (Record $chat) => ucfirst((string) $chat->value('channel')))->sortKeys()
            ->map(function ($group, string $channel) use ($replies) {
                $bot = $group->filter(fn (Record $chat) => $replies->has($chat->value('_bot_reply')));

                return [$channel, $group->count(), $group->where('status', 'resolved')->count(), $bot->reject(fn (Record $chat) => $replies[$chat->value('_bot_reply')]->value('hand_over'))->count(), $bot->filter(fn (Record $chat) => $replies[$chat->value('_bot_reply')]->value('hand_over'))->count()];
            })->values()->all()]];
    }
}
