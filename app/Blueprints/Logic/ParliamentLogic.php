<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Parliament & council business: a bill moves through its readings and committee one stage at a
 * time and is voted on only at its second reading, while motions, questions, petitions and reports
 * are voted on as soon as they are tabled. A vote passes on a simple majority, a sitting is held
 * only once its day has come, and an adjourned sitting carries its undecided business forward.
 */
class ParliamentLogic extends AppLogic
{
    /** @var array<string, string> */
    public const NEXT_STAGE = ['tabled' => 'first_reading', 'first_reading' => 'committee', 'committee' => 'second_reading'];

    public const DECIDED = ['passed', 'rejected', 'withdrawn'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'sittings') {
            if ($payload['status'] === 'held' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['status'] = 'A sitting cannot be held before its date.';
            }

            return $errors;
        }

        $sitting = ! empty($data['sitting']) ? $this->records('sittings')->find($data['sitting']) : null;
        if ($sitting && $sitting->status === 'cancelled' && (! $existing || (int) $existing->value('sitting') !== $sitting->id)) {
            $errors['data.sitting'] = 'That sitting was cancelled.';
        }
        foreach (['votes_for', 'votes_against'] as $field) {
            if ((int) ($data[$field] ?? 0) < 0) {
                $errors['data.'.$field] = 'Votes cannot be negative.';
            }
        }
        if (in_array($payload['status'], ['passed', 'rejected'], true)) {
            $for = (int) ($data['votes_for'] ?? 0);
            $against = (int) ($data['votes_against'] ?? 0);
            if ($for + $against === 0) {
                $errors['data.votes_for'] = 'Record the vote.';
            } elseif (($payload['status'] === 'passed') !== ($for > $against)) {
                $errors['status'] = 'The vote says '.($for > $against ? 'passed' : 'rejected').'.';
            } elseif (($data['type'] ?? null) === 'bill' && $existing && ! in_array($existing->status, ['second_reading', 'passed', 'rejected'], true)) {
                $errors['status'] = 'A bill is voted on at its second reading.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'items') {
            $this->put($record, [
                '_decided_on' => in_array($record->status, self::DECIDED, true) ? ($record->value('_decided_on') ?? today()->toDateString()) : null,
                '_majority' => in_array($record->status, ['passed', 'rejected'], true) ? (int) $record->value('votes_for') - (int) $record->value('votes_against') : null,
            ]);

            return;
        }

        $items = $record->exists ? $this->linked('items', 'sitting', $record)->get() : collect();
        $this->put($record, [
            '_items' => $items->count(),
            '_passed' => $items->where('status', 'passed')->count(),
            '_rejected' => $items->where('status', 'rejected')->count(),
            '_pending' => $items->whereNotIn('status', self::DECIDED)->count(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'items') {
            $this->recalculate($this->parent($record, 'sitting'));
            $this->recalculate($this->previousParent($record, 'sitting'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'items') {
            $this->recalculate($this->parent($record, 'sitting'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'sittings') {
            return $record->status === 'scheduled'
                ? [
                    'hold' => ['label' => 'Held', 'icon' => 'check', 'fields' => [['name' => 'minutes_url', 'label' => 'Minutes / Hansard', 'type' => 'url']]],
                    'adjourn' => ['label' => 'Adjourn', 'icon' => 'calendar-arrow-up', 'fields' => [['name' => 'occurs_on', 'label' => 'Adjourned to', 'type' => 'date', 'value' => today()->addWeek()->toDateString()]]],
                    'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this sitting?'],
                ]
                : [];
        }

        if (in_array($record->status, self::DECIDED, true)) {
            return [];
        }

        $vote = ['label' => 'Record vote', 'icon' => 'vote', 'fields' => [
            ['name' => 'votes_for', 'label' => 'For', 'type' => 'number'],
            ['name' => 'votes_against', 'label' => 'Against', 'type' => 'number'],
            ['name' => 'resolution', 'label' => 'Resolution', 'type' => 'textarea'],
        ]];
        $withdraw = ['label' => 'Withdraw', 'icon' => 'undo', 'confirm' => 'Withdraw '.$record->title.'?'];

        if ($record->value('type') !== 'bill') {
            return ['vote' => $vote, 'withdraw' => $withdraw];
        }

        return $record->status === 'second_reading'
            ? ['vote' => $vote, 'withdraw' => $withdraw]
            : ['advance' => ['label' => 'Next stage', 'icon' => 'arrow-right'], 'withdraw' => $withdraw];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'sittings') {
            switch ($action) {
                case 'hold':
                    if ($record->occurs_on->gt(today())) {
                        throw ValidationException::withMessages(['status' => 'A sitting cannot be held before its date.']);
                    }
                    $minutes = $request->validate(['minutes_url' => ['nullable', 'url']])['minutes_url'] ?? null;
                    $record->update(['status' => 'held', 'data' => [...$record->data, 'minutes_url' => $minutes ?: $record->value('minutes_url')]]);

                    return $record->title.' held.';
                case 'adjourn':
                    $date = Carbon::parse($request->validate(['occurs_on' => ['required', 'date', 'after:today']])['occurs_on']);
                    $next = Record::create([
                        'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'sittings', 'title' => $record->title.' (resumed)', 'status' => 'scheduled',
                        'occurs_on' => $date, 'assignee_id' => $record->assignee_id, 'data' => [...$record->data, 'minutes_url' => null],
                    ]);
                    $moved = 0;
                    foreach ($this->linked('items', 'sitting', $record)->whereNotIn('status', self::DECIDED)->get() as $item) {
                        $item->update(['data' => [...$item->data, 'sitting' => $next->id]]);
                        $moved++;
                    }
                    $record->update(['status' => 'adjourned']);

                    return $record->title.' adjourned to '.$date->format('d M Y').' with '.$moved.' '.($moved === 1 ? 'item' : 'items').' carried forward.';
            }

            $record->update(['status' => 'cancelled']);

            return $record->title.' cancelled.';
        }

        switch ($action) {
            case 'advance':
                $next = self::NEXT_STAGE[$record->status] ?? null;
                if (! $next) {
                    throw ValidationException::withMessages(['status' => 'This bill is ready for its vote.']);
                }
                $record->update(['status' => $next]);

                return $record->title.' moves to '.str_replace('_', ' ', $next).'.';
            case 'vote':
                $input = $request->validate(['votes_for' => ['required', 'integer', 'min:0'], 'votes_against' => ['required', 'integer', 'min:0'], 'resolution' => ['nullable', 'string']]);
                $for = (int) $input['votes_for'];
                $against = (int) $input['votes_against'];
                if ($for + $against === 0) {
                    throw ValidationException::withMessages(['votes_for' => 'Record the vote.']);
                }
                $passed = $for > $against;
                $record->update(['status' => $passed ? 'passed' : 'rejected', 'data' => [...$record->data, 'votes_for' => $for, 'votes_against' => $against, 'resolution' => $input['resolution'] ?? $record->value('resolution')]]);

                return $record->title.($passed ? ' passed' : ' rejected').', '.$for.' votes to '.$against.'.';
        }

        $record->update(['status' => 'withdrawn']);

        return $record->title.' withdrawn.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'sittings') {
            return [];
        }

        $items = $this->linked('items', 'sitting', $record)->orderBy('id')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Order paper', 'icon' => 'scroll-text', 'empty' => 'Nothing on the order paper.',
                'rows' => $items->map(fn (Record $item) => [
                    'label' => $item->title, 'sub' => ucfirst((string) $item->value('type')).($item->value('sponsor') ? ' · '.$item->value('sponsor') : ''), 'value' => ucfirst(str_replace('_', ' ', $item->status)).(in_array($item->status, ['passed', 'rejected'], true) ? ' '.(int) $item->value('votes_for').'–'.(int) $item->value('votes_against') : ''), 'href' => $item->url(), 'tone' => $item->status === 'passed' ? 'success' : ($item->status === 'rejected' ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $sittings = $this->records('sittings')->get();
        $items = $this->records('items')->get();
        $next = $sittings->where('status', 'scheduled')->filter(fn (Record $sitting) => $sitting->occurs_on?->gte(today()))->sortBy('occurs_on')->first();
        $bills = $items->where('data.type', 'bill')->whereNotIn('status', self::DECIDED);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'House business', 'icon' => 'landmark', 'stats' => [
                ['label' => 'Next sitting', 'value' => $next ? $next->occurs_on->format('d M Y') : '—'],
                ['label' => 'On the next order paper', 'value' => $next ? (string) (int) $next->value('_pending') : '—'],
                ['label' => 'Bills before the house', 'value' => (string) $bills->count()],
                ['label' => 'Passed this year', 'value' => (string) $items->filter(fn (Record $item) => $item->status === 'passed' && filled($item->value('_decided_on')) && Carbon::parse($item->value('_decided_on'))->isCurrentYear())->count()],
                ['label' => 'Sittings this year', 'value' => (string) $sittings->filter(fn (Record $sitting) => $sitting->status === 'held' && $sitting->occurs_on?->isCurrentYear())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Bills in progress', 'icon' => 'scroll-text', 'empty' => 'No bills before the house.',
                'rows' => $bills->sortBy('occurs_on')->take(10)->map(fn (Record $bill) => [
                    'label' => $bill->title, 'sub' => (string) $bill->value('sponsor'), 'value' => ucfirst(str_replace('_', ' ', $bill->status)), 'href' => $bill->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $items = $this->dated('items', $from, $to)->get();
        $types = $this->app->entities['items']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [
            $label, $items->where('data.type', $type)->count(), $items->where('data.type', $type)->where('status', 'passed')->count(), $items->where('data.type', $type)->where('status', 'rejected')->count(), $items->where('data.type', $type)->where('status', 'withdrawn')->count(), $items->where('data.type', $type)->whereNotIn('status', self::DECIDED)->count(),
        ])->values()->all();

        $votes = $items->whereIn('status', ['passed', 'rejected'])->sortBy(fn (Record $item) => (string) $item->value('_decided_on'))->map(fn (Record $item) => [
            filled($item->value('_decided_on')) ? Carbon::parse($item->value('_decided_on'))->format('d M Y') : '—', $item->title, ucfirst($item->status), (int) $item->value('votes_for'), (int) $item->value('votes_against'),
        ])->values()->all();

        $bySponsor = $items->groupBy(fn (Record $item) => $item->value('sponsor') ?: 'Unknown')->sortKeys()->map(fn ($group, $sponsor) => [$sponsor, $group->count(), $group->where('status', 'passed')->count()])->values()->all();

        return [
            ['title' => 'Business by type', 'columns' => ['Type', 'Tabled', 'Passed', 'Rejected', 'Withdrawn', 'Pending'], 'rows' => $byType],
            ['title' => 'Votes', 'columns' => ['Date', 'Item', 'Result', 'For', 'Against'], 'rows' => $votes],
            ['title' => 'Business by sponsor', 'columns' => ['Sponsor', 'Tabled', 'Passed'], 'rows' => $bySponsor],
        ];
    }
}
