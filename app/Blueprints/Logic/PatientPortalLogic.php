<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Patient portal: each patient has one portal account per email address, and only active
 * accounts can send requests. Every request type has a reply target (an appointment within a
 * day, prescriptions, results and questions within two working days, a copy of records within
 * thirty days), so the inbox shows what is overdue. A request is closed with a reply, and the
 * time it took is kept for the response-time report.
 */
class PatientPortalLogic extends AppLogic
{
    /**
     * Hours allowed to answer each type of request.
     *
     * @var array<string, int>
     */
    public const REPLY_HOURS = ['appointment' => 24, 'repeat_prescription' => 48, 'results' => 48, 'question' => 48, 'records_copy' => 720];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'accounts') {
            $email = strtolower(trim((string) ($data['email'] ?? '')));
            if ($email !== '' && $this->records('accounts')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $account) => strtolower((string) $account->value('email')) === $email)) {
                $errors['data.email'] = 'There is already a portal account for '.$email.'.';
            }

            return $errors;
        }

        $account = filled($data['account'] ?? null) ? $this->records('accounts')->find($data['account']) : null;
        if ($account && $account->status !== 'active' && ! $existing) {
            $errors['data.account'] = $account->title.'\'s account is '.$account->status.'.';
        }
        if ($payload['status'] === 'done' && blank($data['reply'] ?? null)) {
            $errors['data.reply'] = 'Write the reply before closing the request.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'accounts') {
            $this->put($record, [
                'email' => strtolower(trim((string) $record->value('email'))),
                '_open_requests' => $record->exists ? $this->linked('requests', 'account', $record)->where('status', '!=', 'done')->count() : 0,
            ]);

            return;
        }

        $received = $record->value('_received_at') ? Carbon::parse($record->value('_received_at')) : ($record->occurs_on && ! $record->occurs_on->isToday() ? $record->occurs_on->copy()->startOfDay() : now());
        $record->occurs_on ??= $received->copy()->startOfDay();
        $replyBy = $received->copy()->addHours(self::REPLY_HOURS[$record->value('type')] ?? 48);
        $this->put($record, ['_received_at' => $received->toDateTimeString(), '_reply_by' => $replyBy->toDateTimeString()]);
        if ($record->status === 'done' && blank($record->value('_answered_at'))) {
            $this->put($record, ['_answered_at' => now()->toDateTimeString(), '_hours' => round($received->diffInMinutes(now()) / 60, 1), '_late' => now()->gt($replyBy)]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'requests') {
            $this->recalculate($this->parent($record, 'account'));
            $this->recalculate($this->previousParent($record, 'account'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'requests') {
            $this->recalculate($this->parent($record, 'account'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'accounts') {
            return match ($record->status) {
                'invited' => ['activate' => ['label' => 'Mark activated', 'icon' => 'user-check']],
                'active' => ['disable' => ['label' => 'Disable', 'icon' => 'user-x']],
                default => ['enable' => ['label' => 'Re-enable', 'icon' => 'user-check']],
            };
        }

        return match ($record->status) {
            'new' => ['take' => ['label' => 'Take it', 'icon' => 'hand'], 'reply' => $this->replyAction()],
            'in_progress' => ['reply' => $this->replyAction()],
            default => [],
        };
    }

    /**
     * The reply action with its message field.
     *
     * @return array{label: string, icon: string, fields: list<array<string, string>>}
     */
    protected function replyAction(): array
    {
        return ['label' => 'Reply & close', 'icon' => 'reply', 'fields' => [['name' => 'reply', 'label' => 'Reply to the patient', 'type' => 'textarea']]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'accounts') {
            if ($action === 'activate') {
                $record->update(['status' => 'active', 'data' => [...$record->data, 'last_login' => now()->toDateTimeString()]]);

                return $record->title.'\'s portal account is active.';
            }
            $record->update(['status' => $action === 'disable' ? 'disabled' : 'active']);

            return $record->title.'\'s portal account '.($action === 'disable' ? 'disabled' : 're-enabled').'.';
        }

        if ($action === 'take') {
            $record->update(['status' => 'in_progress', 'assignee_id' => $request->user()?->id]);

            return 'You are handling "'.$record->title.'".';
        }
        $reply = $request->validate(['reply' => ['required', 'string']])['reply'];
        $record->update(['status' => 'done', 'assignee_id' => $record->assignee_id ?? $request->user()?->id, 'data' => [...$record->data, 'reply' => $reply]]);
        $record = $record->fresh();

        return 'Reply sent to '.($this->parent($record, 'account')?->title ?? 'the patient').($record->value('_late') ? ' — later than the '.$this->target($record).' target.' : '.');
    }

    /**
     * A request's reply target in words.
     */
    protected function target(Record $request): string
    {
        $hours = self::REPLY_HOURS[$request->value('type')] ?? 48;

        return $hours < 72 ? $hours.'-hour' : intdiv($hours, 24).'-day';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'accounts') {
            $requests = $this->linked('requests', 'account', $record)->orderByDesc('id')->limit(10)->get();

            return [
                ['view' => 'apps.logic.list-card', 'data' => [
                    'title' => 'Requests', 'icon' => 'inbox', 'empty' => 'No requests yet.',
                    'rows' => $requests->map(fn (Record $request) => [
                        'label' => $request->title, 'sub' => str_replace('_', ' ', ucfirst((string) $request->value('type'))), 'value' => str_replace('_', ' ', ucfirst($request->status)), 'href' => $request->url(), 'tone' => $request->status !== 'done' ? 'warning' : null,
                    ])->values()->all(),
                ]],
            ];
        }
        $replyBy = Carbon::parse($record->value('_reply_by'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Response', 'icon' => 'clock', 'stats' => [
                ['label' => 'Received', 'value' => Carbon::parse($record->value('_received_at'))->format('d M H:i')],
                ['label' => 'Reply by', 'value' => $replyBy->format('d M H:i'), 'tone' => $record->status !== 'done' && $replyBy->isPast() ? 'danger' : null],
                ['label' => 'Answered in', 'value' => $record->value('_hours') === null ? '—' : $record->value('_hours').' h', 'tone' => $record->value('_late') ? 'warning' : null],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('requests')->where('status', '!=', 'done')->get();
        $overdue = $open->filter(fn (Record $request) => Carbon::parse($request->value('_reply_by'))->isPast())->sortBy(fn (Record $request) => $request->value('_reply_by'));
        $accounts = $this->records('accounts')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Portal inbox', 'icon' => 'inbox', 'stats' => [
                ['label' => 'New', 'value' => (string) $open->where('status', 'new')->count()],
                ['label' => 'In progress', 'value' => (string) $open->where('status', 'in_progress')->count()],
                ['label' => 'Overdue', 'value' => (string) $overdue->count(), 'tone' => $overdue->isNotEmpty() ? 'danger' : null],
                ['label' => 'Active accounts', 'value' => (string) $this->records('accounts')->where('status', 'active')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue replies', 'icon' => 'alarm-clock', 'empty' => 'Every request is within its reply target.',
                'rows' => $overdue->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => ($accounts[$request->value('account')] ?? '').' · '.str_replace('_', ' ', (string) $request->value('type')),
                    'value' => Carbon::parse($request->value('_reply_by'))->format('d M H:i'), 'href' => $request->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();
        $answered = fn (Collection $group) => $group->where('status', 'done')->filter(fn (Record $request) => $request->value('_hours') !== null);
        $average = fn (Collection $group) => $answered($group)->isEmpty() ? '—' : round($answered($group)->avg(fn (Record $request) => (float) $request->value('_hours')), 1).' h';
        $onTime = fn (Collection $group) => $answered($group)->isEmpty() ? '—' : (int) round($answered($group)->reject(fn (Record $request) => $request->value('_late'))->count() / $answered($group)->count() * 100).'%';

        $byType = collect(array_keys(self::REPLY_HOURS))->map(function (string $type) use ($requests, $average, $onTime) {
            $group = $requests->filter(fn (Record $request) => $request->value('type') === $type);

            return [str_replace('_', ' ', ucfirst($type)), $group->count(), $group->where('status', 'done')->count(), $average($group), $onTime($group)];
        })->all();

        $staff = User::query()->whereIn('id', $requests->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byStaff = $requests->where('status', 'done')->groupBy(fn (Record $request) => $staff[$request->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn (Collection $group, string $name) => [$name, $group->count(), $average($group), $onTime($group)])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($requests, $average) {
            $group = $requests->filter(fn (Record $request) => $request->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'done')->count(), $average($group)];
        })->values()->all();

        return [
            ['title' => 'Requests by type', 'columns' => ['Type', 'Requests', 'Answered', 'Average reply', 'On time'], 'rows' => $byType],
            ['title' => 'Replies by staff', 'columns' => ['Staff', 'Answered', 'Average reply', 'On time'], 'rows' => $byStaff],
            ['title' => 'Requests by month', 'columns' => ['Month', 'Requests', 'Answered', 'Average reply'], 'rows' => $byMonth],
        ];
    }
}
