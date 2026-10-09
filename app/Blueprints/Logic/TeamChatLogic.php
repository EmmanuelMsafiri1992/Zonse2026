<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Team chat: a private channel lists its members, archived channels take no new messages, and
 * each channel keeps at most ten pinned messages. Channels count their messages and remember
 * when they were last active, so the home screen shows where the conversation is.
 */
class TeamChatLogic extends AppLogic
{
    public const MAX_PINNED = 10;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'channels') {
            if (! empty($data['private']) && blank($data['members'] ?? null)) {
                $errors['data.members'] = 'List who can see this private channel.';
            }
            if ($this->records('channels')->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['title'] = 'There is already a channel called '.$payload['title'].'.';
            }

            return $errors;
        }

        $channel = ! empty($data['channel']) ? $this->records('channels')->find($data['channel']) : null;
        if (! $channel) {
            return $errors;
        }
        if ($channel->status === 'archived' && (! $existing || (int) $existing->value('channel') !== $channel->id)) {
            $errors['data.channel'] = '#'.$channel->title.' is archived.';
        }
        if ($payload['status'] === 'pinned' && $existing?->status !== 'pinned'
            && $this->linked('messages', 'channel', $channel)->where('status', 'pinned')->count() >= self::MAX_PINNED) {
            $errors['status'] = '#'.$channel->title.' already has '.self::MAX_PINNED.' pinned messages. Unpin one first.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'messages') {
            $record->occurs_on ??= today();

            return;
        }

        $messages = $record->exists ? $this->linked('messages', 'channel', $record)->get() : collect();
        $this->put($record, ['_messages' => $messages->count(), '_last_message_on' => $messages->max('occurs_on')?->toDateString()]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'messages') {
            $this->recalculate($this->parent($record, 'channel'));
            $this->recalculate($this->previousParent($record, 'channel'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'messages') {
            $this->recalculate($this->parent($record, 'channel'));
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'channels') {
            return [];
        }

        $messages = $this->linked('messages', 'channel', $record)->with('assignee')->latest('id')->take(10)->get();
        $pinned = $this->linked('messages', 'channel', $record)->where('status', 'pinned')->get();

        return array_values(array_filter([
            $pinned->isNotEmpty() ? ['view' => 'apps.logic.list-card', 'data' => ['title' => 'Pinned', 'icon' => 'pin', 'empty' => '',
                'rows' => $pinned->map(fn (Record $message) => ['label' => $message->title, 'sub' => str((string) $message->value('body'))->limit(80)->toString(), 'href' => $message->url()])->values()->all()]] : null,
            ['view' => 'apps.logic.list-card', 'data' => ['title' => 'Latest messages', 'icon' => 'messages-square', 'empty' => 'No messages yet.',
                'rows' => $messages->map(fn (Record $message) => [
                    'label' => $message->assignee?->name ?? $message->title, 'sub' => str((string) $message->value('body'))->limit(80)->toString(), 'value' => $message->occurs_on?->format('d M') ?? '', 'href' => $message->url(),
                ])->values()->all()]],
        ]));
    }

    public function homeCards(): array
    {
        $channels = $this->records('channels')->where('status', 'active')->get()->sortByDesc(fn (Record $channel) => (string) $channel->value('_last_message_on'));
        $today = $this->records('messages')->whereDate('occurs_on', today())->count();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Chat', 'icon' => 'messages-square', 'stats' => [
                ['label' => 'Active channels', 'value' => (string) $channels->count()],
                ['label' => 'Messages today', 'value' => (string) $today],
                ['label' => 'Pinned', 'value' => (string) $this->records('messages')->where('status', 'pinned')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Busiest channels', 'icon' => 'hash', 'empty' => 'No channels yet.',
                'rows' => $channels->take(8)->map(fn (Record $channel) => [
                    'label' => '#'.$channel->title, 'sub' => (int) $channel->value('_messages').' messages',
                    'value' => $channel->value('_last_message_on') ? Carbon::parse($channel->value('_last_message_on'))->format('d M') : '—', 'href' => $channel->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $messages = $this->dated('messages', $from, $to)->get();
        $channels = $this->records('channels')->pluck('title', 'id');
        $months = $this->months($from, $to);

        $byChannel = $messages->groupBy(fn (Record $message) => '#'.($channels[$message->value('channel')] ?? 'unknown'))->sortKeys()->map(function ($group, $channel) use ($months) {
            $row = [$channel];
            foreach (array_keys($months) as $month) {
                $row[] = $group->filter(fn (Record $message) => $message->occurs_on?->format('Y-m') === $month)->count();
            }

            return [...$row, $group->count()];
        })->values()->all();

        $names = User::query()->whereIn('id', $messages->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $people = $messages->groupBy(fn (Record $message) => $names[$message->assignee_id] ?? 'Unknown')->map(fn ($group, $person) => [$person, $group->count()])
            ->sortByDesc(fn ($row) => $row[1])->values()->all();

        return [
            ['title' => 'Messages by channel', 'columns' => ['Channel', ...array_values($months), 'Total'], 'rows' => $byChannel],
            ['title' => 'Most active people', 'columns' => ['Person', 'Messages'], 'rows' => $people],
        ];
    }
}
