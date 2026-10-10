<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Customer portal: each email has one portal account, kept in lower case. Disabled accounts can't send
 * requests. A request starts as new, and moves to in progress when someone takes it, which assigns it to
 * them. Requests close as done, and the time they took is kept for the report.
 */
class CustomerPortalLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'accounts') {
            $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
            $taken = $email === '' ? null : $this->records('accounts')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $account) => mb_strtolower(trim((string) $account->value('email'))) === $email);
            if ($taken) {
                $errors['data.email'] = $taken->title.' already has a portal account with '.$email.'.';
            }

            return $errors;
        }
        if (! $existing && filled($data['account'] ?? null) && ($account = $this->records('accounts')->find($data['account'])) && $account->status === 'disabled') {
            $errors['data.account'] = $account->title.'\'s portal account is disabled.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'accounts') {
            $this->put($record, ['email' => mb_strtolower(trim((string) $record->value('email')))]);

            return;
        }
        $record->occurs_on ??= today();
        $this->put($record, ['_done_on' => $record->status === 'done' ? ($record->value('_done_on') ?? today()->toDateString()) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'accounts') {
            return match ($record->status) {
                'invited' => ['activate' => ['label' => 'Mark as active', 'icon' => 'user-check'], 'disable' => ['label' => 'Disable', 'icon' => 'user-x']],
                'active' => ['disable' => ['label' => 'Disable', 'icon' => 'user-x']],
                default => ['activate' => ['label' => 'Enable again', 'icon' => 'user-check']],
            };
        }

        return match ($record->status) {
            'new' => ['take' => ['label' => 'Take it', 'icon' => 'hand'], 'done' => ['label' => 'Done', 'icon' => 'check']],
            'in_progress' => ['done' => ['label' => 'Done', 'icon' => 'check']],
            default => ['reopen' => ['label' => 'Reopen', 'icon' => 'rotate-ccw']],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'activate':
                $record->update(['status' => 'active']);

                return $record->title.'\'s portal account is active.';
            case 'disable':
                $record->update(['status' => 'disabled']);

                return $record->title.'\'s portal account is disabled.';
            case 'take':
                $record->update(['status' => 'in_progress', 'assignee_id' => $request->user()->id]);

                return $record->title.' is yours.';
            case 'done':
                $record->update(['status' => 'done', 'assignee_id' => $record->assignee_id ?? $request->user()->id]);

                return $record->title.' done.';
            default:
                $record->update(['status' => 'in_progress']);

                return $record->title.' reopened.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'accounts') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Requests', 'icon' => 'inbox', 'empty' => 'No requests from this customer yet.',
            'rows' => $this->linked('requests', 'account', $record)->orderByDesc('occurs_on')->get()
                ->map(fn (Record $request) => ['label' => $request->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $request->value('type'))), 'value' => ucfirst(str_replace('_', ' ', $request->status)), 'href' => $request->url(), 'tone' => $request->status === 'new' ? 'warning' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('requests')->whereIn('status', ['new', 'in_progress'])->orderBy('occurs_on')->get();
        $accounts = $this->records('accounts')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Requests waiting', 'icon' => 'inbox', 'empty' => 'No open requests.',
                'rows' => $open->map(fn (Record $request) => ['label' => $request->title, 'sub' => ucfirst(str_replace('_', ' ', $request->status)), 'value' => $this->age($request), 'href' => $request->url(), 'tone' => $request->status === 'new' ? 'warning' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Portal accounts', 'icon' => 'globe', 'stats' => [
                ['label' => 'Active', 'value' => $accounts->where('status', 'active')->count()],
                ['label' => 'Invited, not active', 'value' => $accounts->where('status', 'invited')->count()],
                ['label' => 'Disabled', 'value' => $accounts->where('status', 'disabled')->count()],
            ]]],
        ];
    }

    /**
     * How long ago a request came in, in plain words.
     */
    protected function age(Record $request): string
    {
        $days = (int) $request->occurs_on->diffInDays(today());

        return match ($days) {
            0 => 'Today',
            1 => '1 day',
            default => $days.' days',
        };
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Requests by type', 'columns' => ['Type', 'Requests', 'Done', 'Average days to close'], 'rows' => $this->dated('requests', $from, $to)->get()
            ->groupBy(fn (Record $request) => ucfirst(str_replace('_', ' ', (string) $request->value('type'))))->sortKeys()
            ->map(function ($group, string $type) {
                $done = $group->where('status', 'done')->filter(fn (Record $request) => filled($request->value('_done_on')));

                return [$type, $group->count(), $done->count(), $done->isNotEmpty() ? round($done->avg(fn (Record $request) => $request->occurs_on->diffInDays(Carbon::parse($request->value('_done_on')))), 1) : '—'];
            })->values()->all()]];
    }
}
