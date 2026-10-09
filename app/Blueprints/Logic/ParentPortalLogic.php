<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Parent & student portal: an account is activated only with an email or phone to reach it, one
 * account per email, and only active accounts send messages. A message is replied to with a reply
 * and closed only after one, and the portal tracks how long replies take.
 */
class ParentPortalLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'accounts') {
            if ($payload['status'] === 'active' && blank($data['email'] ?? null) && blank($data['phone'] ?? null)) {
                $errors['data.email'] = 'An active account needs an email or a phone number.';
            }
            if (filled($data['email'] ?? null) && $this->records('accounts')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $account) => strtolower(trim((string) $account->value('email'))) === strtolower(trim($data['email'])))) {
                $errors['data.email'] = 'Another account already uses '.$data['email'].'.';
            }
            if ($existing && $payload['status'] === 'disabled' && $this->linked('messages', 'account', $existing)->where('status', 'new')->exists()) {
                $errors['status'] = 'Answer this account\'s open messages before disabling it.';
            }

            return $errors;
        }

        $account = ! empty($data['account']) ? $this->records('accounts')->find($data['account']) : null;
        if ($account && $account->status !== 'active' && (! $existing || (int) $existing->value('account') !== $account->id)) {
            $errors['data.account'] = $account->title.'\'s account is '.$account->status.'.';
        }
        if (in_array($payload['status'], ['replied', 'closed'], true) && blank($data['reply'] ?? null)) {
            $errors['data.reply'] = 'Write the reply before marking the message '.$payload['status'].'.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'accounts') {
            $messages = $record->exists ? $this->linked('messages', 'account', $record)->get() : collect();
            $this->put($record, ['_messages' => $messages->count(), '_open_messages' => $messages->where('status', 'new')->count()]);

            return;
        }

        $record->occurs_on ??= today();
        $replied = in_array($record->status, ['replied', 'closed'], true);
        $repliedOn = $replied ? ($record->value('_replied_on') ?? today()->toDateString()) : null;
        $this->put($record, [
            '_replied_on' => $repliedOn,
            '_response_days' => $repliedOn ? (int) $record->occurs_on->copy()->startOfDay()->diffInDays(Carbon::parse($repliedOn)->startOfDay()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'messages') {
            $this->recalculate($this->parent($record, 'account'));
            $this->recalculate($this->previousParent($record, 'account'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'messages') {
            $this->recalculate($this->parent($record, 'account'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'accounts') {
            return match ($record->status) {
                'invited' => ['activate' => ['label' => 'Activate', 'icon' => 'user-check']],
                'active' => ['disable' => ['label' => 'Disable', 'icon' => 'user-x', 'confirm' => 'Disable this account? They can no longer log in or message the school.']],
                default => ['reinvite' => ['label' => 'Invite again', 'icon' => 'mail']],
            };
        }

        return match ($record->status) {
            'new' => ['reply' => ['label' => 'Reply', 'icon' => 'reply', 'fields' => [
                ['name' => 'reply', 'label' => 'Reply', 'type' => 'textarea', 'value' => $record->value('reply')],
            ]]],
            'replied' => ['close' => ['label' => 'Close', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'activate':
                if (blank($record->value('email')) && blank($record->value('phone'))) {
                    throw ValidationException::withMessages(['data.email' => 'An active account needs an email or a phone number.']);
                }
                $record->update(['status' => 'active']);

                return $record->title.'\'s portal account is active.';
            case 'disable':
                if ($this->linked('messages', 'account', $record)->where('status', 'new')->exists()) {
                    throw ValidationException::withMessages(['status' => 'Answer this account\'s open messages before disabling it.']);
                }
                $record->update(['status' => 'disabled']);

                return 'Account disabled.';
            case 'reinvite':
                $record->update(['status' => 'invited']);

                return 'Invitation sent again to '.$record->title.'.';
            case 'reply':
                $reply = $request->validate(['reply' => ['required', 'string']])['reply'];
                $record->update(['status' => 'replied', 'data' => [...(array) $record->data, 'reply' => $reply]]);

                return 'Reply sent.';
        }
        $record->update(['status' => 'closed']);

        return 'Message closed.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'accounts') {
            return [];
        }

        $messages = $this->linked('messages', 'account', $record)->orderByDesc('occurs_on')->get();
        $types = $this->app->entities['messages']->field('type')?->options ?? [];

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Portal account', 'icon' => 'user-round', 'stats' => [
                ['label' => 'Role', 'value' => ucfirst((string) $record->value('role'))],
                ['label' => 'Children / student', 'value' => $record->value('children') ?: '—'],
                ['label' => 'Reach', 'value' => $record->value('email') ?: ($record->value('phone') ?: 'No contact details'), 'tone' => blank($record->value('email')) && blank($record->value('phone')) ? 'warning' : null],
                ['label' => 'Messages', 'value' => (string) (int) $record->value('_messages')],
                ['label' => 'Awaiting reply', 'value' => (string) (int) $record->value('_open_messages'), 'tone' => (int) $record->value('_open_messages') > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Messages', 'icon' => 'mail', 'empty' => 'No messages.',
                'rows' => $messages->take(10)->map(fn (Record $message) => [
                    'label' => $message->title, 'sub' => ($types[$message->value('type')] ?? $message->value('type')).' · '.$message->occurs_on?->format('d M Y'), 'value' => ucfirst($message->status), 'href' => $message->url(),
                    'tone' => $message->status === 'new' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $accounts = $this->records('accounts')->get();
        $messages = $this->records('messages')->get();
        $unanswered = $messages->where('status', 'new')->sortBy('occurs_on');
        $answered = $messages->filter(fn (Record $message) => $message->value('_response_days') !== null);
        $names = $accounts->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Portal', 'icon' => 'users-round', 'stats' => [
                ['label' => 'Active accounts', 'value' => (string) $accounts->where('status', 'active')->count()],
                ['label' => 'Invites pending', 'value' => (string) $accounts->where('status', 'invited')->count()],
                ['label' => 'Awaiting reply', 'value' => (string) $unanswered->count(), 'tone' => $unanswered->isNotEmpty() ? 'warning' : null],
                ['label' => 'Average reply time', 'value' => $answered->isEmpty() ? '—' : round($answered->avg(fn (Record $message) => (int) $message->value('_response_days')), 1).' days'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Unanswered messages', 'icon' => 'mail-question', 'empty' => 'Every message has a reply.',
                'rows' => $unanswered->take(10)->map(fn (Record $message) => [
                    'label' => $message->title, 'sub' => ($names[(int) $message->value('account')] ?? '').' · '.str_replace('_', ' ', (string) $message->value('type')), 'value' => (int) $message->occurs_on->diffInDays(today()).' days', 'href' => $message->url(),
                    'tone' => $message->occurs_on->diffInDays(today()) >= 3 ? 'danger' : 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $messages = $this->dated('messages', $from, $to)->get();
        $types = $this->app->entities['messages']->field('type')?->options ?? [];
        $byType = collect($types)->map(function (string $label, string $type) use ($messages) {
            $group = $messages->where('data.type', $type);
            $answered = $group->filter(fn (Record $message) => $message->value('_response_days') !== null);

            return [$label, $group->count(), $group->where('status', 'new')->count(), $group->where('status', 'closed')->count(), $answered->isEmpty() ? '—' : round($answered->avg(fn (Record $message) => (int) $message->value('_response_days')), 1)];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($messages) {
            $group = $messages->filter(fn (Record $message) => $message->occurs_on?->format('Y-m') === $month);
            $answered = $group->filter(fn (Record $message) => $message->value('_response_days') !== null);

            return [$label, $group->count(), $answered->count(), $answered->isEmpty() ? '—' : round($answered->avg(fn (Record $message) => (int) $message->value('_response_days')), 1)];
        })->values()->all();

        $accounts = $this->records('accounts')->get();
        $byRole = collect($this->app->entities['accounts']->field('role')?->options ?? [])->map(fn (string $label, string $role) => [
            $label, $accounts->where('data.role', $role)->where('status', 'active')->count(), $accounts->where('data.role', $role)->where('status', 'invited')->count(), $accounts->where('data.role', $role)->where('status', 'disabled')->count(),
        ])->values()->all();

        return [
            ['title' => 'Messages by type', 'columns' => ['Type', 'Messages', 'Unanswered', 'Closed', 'Avg reply days'], 'rows' => $byType],
            ['title' => 'Reply time by month', 'columns' => ['Month', 'Messages', 'Answered', 'Avg reply days'], 'rows' => $byMonth],
            ['title' => 'Accounts by role', 'columns' => ['Role', 'Active', 'Invited', 'Disabled'], 'rows' => $byRole],
        ];
    }
}
