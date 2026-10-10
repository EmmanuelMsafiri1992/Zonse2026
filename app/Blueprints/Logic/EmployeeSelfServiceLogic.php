<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Employee self-service portal: a staff request is picked up by whoever starts it, and can only be
 * marked done or declined with a response to the employee; how long it took is kept for reports.
 * Publishing an announcement dates it today, and archived announcements leave the home page.
 */
class EmployeeSelfServiceLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'requests' && in_array($payload['status'], ['done', 'declined'], true) && blank($payload['data']['response'] ?? null)) {
            return ['data.response' => 'Write a response to the employee before closing the request.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'announcements') {
            if ($record->status === 'published') {
                $record->occurs_on ??= today();
            }

            return;
        }
        $record->occurs_on ??= today();
        if ($record->isDirty('status') && in_array($record->status, ['done', 'declined'], true)) {
            $this->put($record, ['_closed_on' => today()->toDateString(), '_days_open' => (int) $record->occurs_on->diffInDays(today())]);
        }
    }

    public function actions(Record $record): array
    {
        $close = fn (string $label, string $icon) => ['label' => $label, 'icon' => $icon, 'fields' => [['name' => 'response', 'label' => 'Response to the employee', 'type' => 'text', 'value' => $record->value('response')]]];

        return match (true) {
            $record->entity === 'announcements' && $record->status === 'draft' => ['publish' => ['label' => 'Publish', 'icon' => 'megaphone']],
            $record->entity === 'announcements' && $record->status === 'published' => ['archive' => ['label' => 'Archive', 'icon' => 'archive']],
            $record->entity === 'requests' && $record->status === 'submitted' => ['start' => ['label' => 'Pick up', 'icon' => 'hand'], 'done' => $close('Done', 'check'), 'decline' => $close('Decline', 'x')],
            $record->entity === 'requests' && $record->status === 'in_progress' => ['done' => $close('Done', 'check'), 'decline' => $close('Decline', 'x')],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'publish':
                $record->update(['status' => 'published', 'occurs_on' => today()]);

                return $record->title.' published.';
            case 'archive':
                $record->update(['status' => 'archived']);

                return $record->title.' archived.';
            case 'start':
                $record->update(['status' => 'in_progress', 'assignee_id' => $record->assignee_id ?? $request->user()->id]);

                return $record->title.' picked up by '.User::query()->whereKey($record->assignee_id)->value('name').'.';
            default:
                $response = trim((string) ($request->validate(['response' => ['nullable', 'string', 'max:2000']])['response'] ?? ''));
                if ($response === '') {
                    throw ValidationException::withMessages(['response' => 'Write a response to the employee before closing the request.']);
                }
                $record->update(['status' => $action === 'done' ? 'done' : 'declined', 'data' => [...$record->data, 'response' => $response]]);

                return $record->title.' '.($action === 'done' ? 'done' : 'declined').' after '.$record->value('_days_open').' '.str('day')->plural((int) $record->value('_days_open')).'.';
        }
    }

    public function homeCards(): array
    {
        $open = $this->records('requests')->whereIn('status', ['submitted', 'in_progress'])->orderBy('occurs_on')->get();
        $names = User::query()->whereIn('id', $open->map(fn (Record $request) => $request->value('employee'))->filter()->unique())->pluck('name', 'id');
        $news = $this->records('announcements')->where('status', 'published')->orderByDesc('occurs_on')->limit(5)->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open staff requests', 'icon' => 'inbox', 'empty' => 'No requests waiting.',
                'rows' => $open->map(function (Record $request) use ($names) {
                    $days = (int) $request->occurs_on->diffInDays(today());

                    return ['label' => $request->title, 'sub' => ($names[$request->value('employee')] ?? '—').' · '.str_replace('_', ' ', (string) $request->value('type')),
                        'value' => $days.' '.str('day')->plural($days), 'href' => $request->url(), 'tone' => $days > 7 ? 'danger' : ($request->status === 'submitted' ? 'warning' : null)];
                })->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Announcements', 'icon' => 'megaphone', 'empty' => 'Nothing published.',
                'rows' => $news->map(fn (Record $announcement) => ['label' => $announcement->title, 'sub' => $announcement->value('audience'), 'value' => $announcement->occurs_on?->format('d M'), 'href' => $announcement->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();

        return [['title' => 'Requests by type', 'columns' => ['Type', 'Requests', 'Done', 'Declined', 'Still open', 'Average days to close'], 'rows' => $requests
            ->groupBy(fn (Record $request) => ucfirst(str_replace('_', ' ', (string) $request->value('type'))))->sortKeys()
            ->map(function ($group, string $type) {
                $closed = $group->whereIn('status', ['done', 'declined']);

                return [$type, $group->count(), $group->where('status', 'done')->count(), $group->where('status', 'declined')->count(), $group->count() - $closed->count(),
                    $closed->isNotEmpty() ? number_format($closed->avg(fn (Record $request) => (int) $request->value('_days_open')), 1) : '—'];
            })->values()->all()]];
    }
}
