<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tenant portal: an account goes live only with an email or phone number, and an email belongs to one
 * account. Disabled accounts can't send new requests. A request is done only once it has a reply, and
 * it records how many days the answer took. The home page lists requests waiting for a first answer,
 * and the report shows requests and answer times by type.
 */
class TenantPortalLogic extends AppLogic
{
    public const ANSWER_WITHIN_DAYS = 2;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'accounts') {
            if ($payload['status'] === 'active' && blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
                $errors['data.email'] = 'Give an email or phone number before the account goes live.';
            }
            $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
            if ($email !== '' && ($other = $this->records('accounts')->where('status', '!=', 'disabled')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $account) => mb_strtolower(trim((string) $account->value('email'))) === $email))) {
                $errors['data.email'] = $other->title.' already uses this email.';
            }
        }
        if ($entity->key === 'requests') {
            if (! $existing && filled($data['account'] ?? null) && ($account = $this->records('accounts')->find($data['account'])) && $account->status === 'disabled') {
                $errors['data.account'] = $account->title.'\'s account is disabled.';
            }
            if ($payload['status'] === 'done' && blank($data['reply'] ?? null)) {
                $errors['data.reply'] = 'Write the reply before closing the request.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'requests') {
            return;
        }
        $record->occurs_on ??= today();
        $answered = $record->status === 'done' ? ($record->value('_answered_on') ?? today()->toDateString()) : null;
        $this->put($record, ['_answered_on' => $answered, '_days_to_answer' => $answered ? (int) $record->occurs_on->diffInDays(Carbon::parse($answered)) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'accounts') {
            return $record->status === 'active' ? ['disable' => ['label' => 'Disable', 'icon' => 'ban']] : ['activate' => ['label' => 'Activate', 'icon' => 'check']];
        }
        $reply = ['reply' => ['label' => 'Reply & close', 'icon' => 'reply', 'fields' => [['name' => 'reply', 'label' => 'Reply', 'type' => 'textarea', 'value' => $record->value('reply')]]]];

        return match ($record->status) {
            'new' => ['start' => ['label' => 'Working on it', 'icon' => 'play'], ...$reply],
            'in_progress' => $reply,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'activate':
                if (blank($record->value('email')) && blank($record->value('phone'))) {
                    throw ValidationException::withMessages(['email' => 'Give an email or phone number before the account goes live.']);
                }
                $record->update(['status' => 'active']);

                return $record->title.'\'s portal account is live.';
            case 'disable':
                $record->update(['status' => 'disabled']);

                return $record->title.'\'s portal account is disabled.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return 'Working on '.$record->title.'.';
            default:
                $reply = trim((string) ($request->validate(['reply' => ['nullable', 'string', 'max:5000']])['reply'] ?? ''));
                if ($reply === '') {
                    throw ValidationException::withMessages(['reply' => 'Write the reply before closing the request.']);
                }
                $record->update(['status' => 'done', 'data' => [...$record->data, 'reply' => $reply]]);
                $days = (int) $record->value('_days_to_answer');

                return $record->title.' answered '.($days === 0 ? 'the same day' : 'after '.$days.' '.str('day')->plural($days)).'.';
        }
    }

    public function homeCards(): array
    {
        $waiting = $this->records('requests')->where('status', 'new')->orderBy('occurs_on')->get();
        $accounts = $this->records('accounts')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Waiting for an answer', 'icon' => 'inbox', 'empty' => 'Every request has been picked up.',
            'rows' => $waiting->map(fn (Record $request) => ['label' => $request->title, 'sub' => $accounts[(int) $request->value('account')] ?? null,
                'value' => ($days = (int) $request->occurs_on->diffInDays(today())) === 0 ? 'today' : $days.' '.str('day')->plural($days),
                'href' => $request->url(), 'tone' => $days > self::ANSWER_WITHIN_DAYS ? 'danger' : null])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();

        return [['title' => 'Requests by type', 'columns' => ['Type', 'Received', 'Answered', 'Still open', 'Average days to answer'], 'rows' => $requests
            ->groupBy(fn (Record $request) => ucfirst(str_replace('_', ' ', (string) $request->value('type'))))->sortKeys()
            ->map(function ($group, string $type) {
                $done = $group->where('status', 'done');

                return [$type, $group->count(), $done->count(), $group->where('status', '!=', 'done')->count(), $done->isNotEmpty() ? number_format($done->avg(fn (Record $request) => (int) $request->value('_days_to_answer')), 1) : '—'];
            })->values()->all()]];
    }
}
