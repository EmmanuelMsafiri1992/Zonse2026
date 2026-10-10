<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Call centre: an answered call needs its outcome, and a call marked for a callback needs the time to call
 * back. Missed calls and voicemails take no talk time. A campaign can't reach more numbers than are on its
 * list. Planned campaigns start on their start date, and running ones finish once the whole list is reached
 * or the end date passes; both are checked each night.
 */
class CallCentreLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'campaigns') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The campaign must end after it starts.';
            }
            if (filled($data['list_size'] ?? null) && (int) ($data['reached'] ?? 0) > (int) $data['list_size']) {
                $errors['data.reached'] = 'Reached can\'t be more than the '.(int) $data['list_size'].' numbers on the list.';
            }

            return $errors;
        }
        if ($payload['status'] === 'answered' && blank($data['disposition'] ?? null)) {
            $errors['data.disposition'] = 'Give the outcome of the call.';
        }
        if ($payload['status'] === 'callback' && blank($data['callback_at'] ?? null)) {
            $errors['data.callback_at'] = 'Give the time to call back.';
        }
        if ((float) ($data['duration'] ?? 0) < 0) {
            $errors['data.duration'] = 'The duration can\'t be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'calls') {
            if (in_array($record->status, ['missed', 'voicemail'], true)) {
                $this->put($record, ['duration' => 0]);
            }
            if ($record->status !== 'callback') {
                $this->put($record, ['callback_at' => null]);
            }

            return;
        }
        $listSize = (int) $record->value('list_size');
        $record->status = match (true) {
            $record->status === 'finished' => 'finished',
            $listSize > 0 && (int) $record->value('reached') >= $listSize => 'finished',
            $record->due_on && $record->due_on->lt(today()) && $record->status === 'running' => 'finished',
            $record->status === 'planned' && $record->occurs_on->lte(today()) => 'running',
            default => $record->status,
        };
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('campaigns')->whereIn('status', ['planned', 'running'])->get()
            ->filter(fn (Record $campaign) => $campaign->status === 'planned' ? $campaign->occurs_on?->lte(today()) : $campaign->due_on?->lt(today()))
            ->each(fn (Record $campaign) => $campaign->save())->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'campaigns') {
            return match ($record->status) {
                'planned' => ['start' => ['label' => 'Start now', 'icon' => 'play']],
                'running' => [
                    'reached' => ['label' => 'Log numbers reached', 'icon' => 'phone-outgoing', 'fields' => [['name' => 'reached', 'label' => 'Numbers reached so far', 'type' => 'number', 'value' => $record->value('reached')]]],
                    'finish' => ['label' => 'Finish', 'icon' => 'flag'],
                ],
                default => [],
            };
        }

        return in_array($record->status, ['missed', 'voicemail', 'callback'], true) ? ['called_back' => ['label' => 'Called back', 'icon' => 'phone-call', 'fields' => [
            ['name' => 'disposition', 'label' => 'Outcome', 'type' => 'select', 'options' => ['sale' => 'Sale', 'interested' => 'Interested', 'not_interested' => 'Not interested', 'complaint' => 'Complaint', 'query' => 'Query', 'wrong_number' => 'Wrong number'], 'value' => 'query'],
            ['name' => 'duration', 'label' => 'Duration (minutes)', 'type' => 'number', 'value' => ''],
        ]]] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'called_back':
                $input = $request->validate(['disposition' => ['required', 'string'], 'duration' => ['nullable', 'numeric', 'min:0']], ['disposition.required' => 'Give the outcome of the call.']);
                $record->update(['status' => 'answered', 'data' => [...$record->data, 'disposition' => $input['disposition'], 'duration' => (float) ($input['duration'] ?? 0)]]);

                return $record->title.' called back: '.str_replace('_', ' ', $input['disposition']).'.';
            case 'start':
                $record->update(['status' => 'running', 'occurs_on' => $record->occurs_on?->lte(today()) ? $record->occurs_on : today()]);

                return $record->title.' is running.';
            case 'reached':
                $reached = (int) $request->validate(['reached' => ['required', 'integer', 'min:0']])['reached'];
                $listSize = (int) $record->value('list_size');
                if ($listSize > 0 && $reached > $listSize) {
                    throw ValidationException::withMessages(['reached' => 'Reached can\'t be more than the '.$listSize.' numbers on the list.']);
                }
                $record->update(['data' => [...$record->data, 'reached' => $reached]]);

                return $record->status === 'finished' ? $record->title.' reached every number and is finished.' : $record->title.': '.$reached.($listSize ? ' of '.$listSize : '').' reached.';
            default:
                $record->update(['status' => 'finished']);

                return $record->title.' finished.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'campaigns') {
            return [];
        }
        $listSize = (int) $record->value('list_size');
        $reached = (int) $record->value('reached');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'list-ordered', 'stats' => [
            ['label' => 'Reached', 'value' => $reached],
            ['label' => 'Still to call', 'value' => max(0, $listSize - $reached)],
            ['label' => 'Done', 'value' => $listSize > 0 ? round($reached / $listSize * 100).'%' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $today = $this->records('calls')->whereDate('occurs_on', today()->toDateString())->get();
        $callbacks = $this->records('calls')->where('status', 'callback')->get()->sortBy(fn (Record $call) => (string) $call->value('callback_at'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Calls today', 'icon' => 'phone', 'stats' => [
                ['label' => 'Calls', 'value' => $today->count()],
                ['label' => 'Answered', 'value' => $today->isNotEmpty() ? round($today->where('status', 'answered')->count() / $today->count() * 100).'%' : '—'],
                ['label' => 'Talk time', 'value' => round($today->sum(fn (Record $call) => $this->number($call, 'duration'))).' min'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Callbacks due', 'icon' => 'phone-call', 'empty' => 'No callbacks waiting.',
                'rows' => $callbacks->map(fn (Record $call) => ['label' => $call->title, 'sub' => (string) $call->value('phone'), 'value' => filled($call->value('callback_at')) ? Carbon::parse($call->value('callback_at'))->format('d M H:i') : '—', 'href' => $call->url(), 'tone' => filled($call->value('callback_at')) && Carbon::parse($call->value('callback_at'))->isPast() ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $calls = $this->dated('calls', $from, $to)->get();
        $names = User::query()->whereIn('id', $calls->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [['title' => 'Calls by agent', 'columns' => ['Agent', 'Calls', 'Answered', 'Answer rate', 'Talk time (min)', 'Sales'], 'rows' => $calls
            ->groupBy(fn (Record $call) => $names[$call->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn ($group, string $agent) => [$agent, $group->count(), $group->where('status', 'answered')->count(), round($group->where('status', 'answered')->count() / $group->count() * 100).'%', round($group->sum(fn (Record $call) => $this->number($call, 'duration'))), $group->filter(fn (Record $call) => $call->value('disposition') === 'sale')->count()])
            ->values()->all()]];
    }
}
