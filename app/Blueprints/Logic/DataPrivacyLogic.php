<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Data privacy (GDPR / POPIA): every active processing activity states how long the data is kept.
 * A data-subject request must be answered within 30 days, extended once by up to 60 more, and is
 * worked on only after the requester's identity is verified. A breach must be reported to the
 * regulator within 72 hours of being detected, and closes only once the actions taken are recorded.
 */
class DataPrivacyLogic extends AppLogic
{
    public const RESPONSE_DAYS = 30;

    public const NOTIFY_HOURS = 72;

    public const OPEN = ['received', 'verifying_id', 'in_progress'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'processing') {
            if ($payload['status'] === 'active' && blank($data['retention'] ?? null)) {
                $errors['data.retention'] = 'Say how long this data is kept.';
            }
            if (($data['lawful_basis'] ?? null) === 'legitimate_interest' && blank($data['purpose'] ?? null)) {
                $errors['data.purpose'] = 'Describe the legitimate interest.';
            }

            return $errors;
        }

        if ($entity->key === 'requests') {
            if (in_array($payload['status'], ['in_progress', 'completed'], true) && blank($existing?->value('_verified_on'))) {
                $errors['status'] = 'Verify the requester\'s identity first.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
                $errors['occurs_on'] = 'A request cannot be received in the future.';
            }

            return $errors;
        }

        if ((int) ($data['records_affected'] ?? 0) < 0) {
            $errors['data.records_affected'] = 'Records affected cannot be negative.';
        }
        if ($payload['status'] === 'notified' && empty($data['regulator_notified'])) {
            $errors['data.regulator_notified'] = 'Tick that the regulator was notified.';
        }
        if ($payload['status'] === 'closed' && blank($data['actions'] ?? null)) {
            $errors['data.actions'] = 'Record what was done about the breach.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'A breach cannot be detected in the future.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'requests') {
            $record->due_on ??= $record->occurs_on->copy()->addDays(self::RESPONSE_DAYS);
            $completed = in_array($record->status, ['completed', 'refused'], true) ? ($record->value('_completed_on') ?? today()->toDateString()) : null;
            $this->put($record, [
                '_completed_on' => $completed,
                '_days_to_respond' => $completed ? (int) $record->occurs_on->diffInDays(Carbon::parse($completed)) : null,
                '_on_time' => $completed ? Carbon::parse($completed)->lte($record->due_on) : null,
                '_overdue' => in_array($record->status, self::OPEN, true) && $record->due_on->lt(today()),
            ]);

            return;
        }

        if ($record->entity === 'breaches') {
            $notifyBy = $record->occurs_on->copy()->addHours(self::NOTIFY_HOURS);
            $notified = $record->value('regulator_notified') ? ($record->value('_notified_on') ?? today()->toDateString()) : null;
            $this->put($record, [
                'regulator_notified' => (bool) $record->value('regulator_notified'),
                'subjects_notified' => (bool) $record->value('subjects_notified'),
                '_notify_by' => $notifyBy->toDateString(),
                '_notified_on' => $notified,
                '_notified_late' => $notified !== null && Carbon::parse($notified)->gt($notifyBy),
                '_closed_on' => $record->status === 'closed' ? ($record->value('_closed_on') ?? today()->toDateString()) : null,
            ]);

            return;
        }

        $this->put($record, ['_retired_on' => $record->status === 'retired' ? ($record->value('_retired_on') ?? today()->toDateString()) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'processing') {
            return $record->status === 'active'
                ? ['retire' => ['label' => 'Retire', 'icon' => 'archive', 'confirm' => 'Retire this processing activity?']]
                : ['reactivate' => ['label' => 'Reactivate', 'icon' => 'rotate-ccw']];
        }

        if ($record->entity === 'breaches') {
            $close = ['label' => 'Close', 'icon' => 'check', 'fields' => [['name' => 'actions', 'label' => 'Actions taken', 'type' => 'textarea', 'value' => $record->value('actions')]]];
            $notify = ['label' => 'Notify regulator', 'icon' => 'send', 'fields' => [['name' => 'subjects_notified', 'label' => 'Data subjects told too', 'type' => 'checkbox']]];

            return match ($record->status) {
                'detected' => ['contain' => ['label' => 'Contained', 'icon' => 'shield'], 'notify' => $notify],
                'contained' => ['notify' => $notify, 'close' => $close],
                'notified' => ['close' => $close],
                default => [],
            };
        }

        $refuse = ['label' => 'Refuse', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Grounds', 'type' => 'textarea']]];

        return match ($record->status) {
            'received' => ['verify' => ['label' => 'Ask for ID', 'icon' => 'id-card'], 'verified' => ['label' => 'Identity verified', 'icon' => 'user-check'], 'refuse' => $refuse],
            'verifying_id' => ['verified' => ['label' => 'Identity verified', 'icon' => 'user-check'], 'refuse' => $refuse],
            'in_progress' => array_filter([
                'complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [['name' => 'response', 'label' => 'Response sent', 'type' => 'textarea']]],
                'extend' => $record->value('_extended') ? null : ['label' => 'Extend deadline', 'icon' => 'calendar-plus', 'fields' => [['name' => 'days', 'label' => 'Extra days', 'type' => 'number', 'value' => 30], ['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
                'refuse' => $refuse,
            ]),
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'processing') {
            $record->update(['status' => $action === 'retire' ? 'retired' : 'active']);

            return $record->title.($action === 'retire' ? ' retired.' : ' is active again.');
        }

        if ($record->entity === 'breaches') {
            switch ($action) {
                case 'contain':
                    $record->update(['status' => 'contained']);

                    return $record->title.' contained.';
                case 'notify':
                    $subjects = $request->boolean('subjects_notified');
                    $record->update(['status' => 'notified', 'data' => [...$record->data, 'regulator_notified' => true, 'subjects_notified' => $subjects || $record->value('subjects_notified'), '_notified_on' => today()->toDateString()]]);
                    $late = $record->fresh()->value('_notified_late');

                    return 'Regulator notified of '.$record->title.($late ? ', after the 72-hour deadline.' : ' within 72 hours.');
            }

            $actions = $request->validate(['actions' => ['required', 'string']])['actions'];
            $record->update(['status' => 'closed', 'data' => [...$record->data, 'actions' => $actions]]);

            return $record->title.' closed.';
        }

        switch ($action) {
            case 'verify':
                $record->update(['status' => 'verifying_id']);

                return 'Asked '.$record->title.' for proof of identity.';
            case 'verified':
                $record->update(['status' => 'in_progress', 'data' => [...$record->data, '_verified_on' => today()->toDateString()]]);

                return $record->title.'\'s identity verified.';
            case 'complete':
                $response = $request->validate(['response' => ['required', 'string']])['response'];
                $record->update(['status' => 'completed', 'data' => [...$record->data, '_response' => $response]]);

                return $record->fresh()->value('_on_time') ? 'Request from '.$record->title.' completed on time.' : 'Request from '.$record->title.' completed late.';
            case 'extend':
                $input = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:60'], 'reason' => ['required', 'string']]);
                if ($record->value('_extended')) {
                    throw ValidationException::withMessages(['days' => 'This request has already been extended.']);
                }
                $due = $record->due_on->copy()->addDays((int) $input['days']);
                $record->update(['due_on' => $due, 'data' => [...$record->data, '_extended' => true, '_extension_reason' => $input['reason']]]);

                return 'Deadline for '.$record->title.' extended to '.$due->format('d M Y').'.';
        }

        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
        $record->update(['status' => 'refused', 'data' => [...$record->data, '_response' => $reason]]);

        return 'Request from '.$record->title.' refused.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'requests') {
            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Request', 'icon' => 'user-search', 'stats' => [
                    ['label' => 'Received', 'value' => $record->occurs_on?->format('d M Y') ?? '—'],
                    ['label' => 'Respond by', 'value' => $record->due_on?->format('d M Y').($record->value('_extended') ? ' (extended)' : ''), 'tone' => $record->value('_overdue') ? 'danger' : null],
                    ['label' => 'Identity', 'value' => filled($record->value('_verified_on')) ? 'Verified '.Carbon::parse($record->value('_verified_on'))->format('d M Y') : 'Not verified', 'tone' => filled($record->value('_verified_on')) ? 'success' : 'warning'],
                    ['label' => 'Answered in', 'value' => $record->value('_days_to_respond') === null ? '—' : (int) $record->value('_days_to_respond').' days', 'tone' => $record->value('_on_time') === false ? 'danger' : null],
                ]]],
            ];
        }

        if ($record->entity === 'breaches') {
            return [
                ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Breach', 'icon' => 'lock-open', 'stats' => [
                    ['label' => 'Records affected', 'value' => number_format((int) $record->value('records_affected'))],
                    ['label' => 'Notify regulator by', 'value' => Carbon::parse($record->value('_notify_by'))->format('d M Y')],
                    ['label' => 'Regulator', 'value' => filled($record->value('_notified_on')) ? 'Notified '.Carbon::parse($record->value('_notified_on'))->format('d M Y') : 'Not notified', 'tone' => $record->value('_notified_late') ? 'danger' : (filled($record->value('_notified_on')) ? 'success' : 'warning')],
                    ['label' => 'Data subjects', 'value' => $record->value('subjects_notified') ? 'Notified' : 'Not notified'],
                ]]],
            ];
        }

        return [];
    }

    public function homeCards(): array
    {
        $requests = $this->records('requests')->get();
        $breaches = $this->records('breaches')->get();
        $open = $requests->whereIn('status', self::OPEN)->sortBy('due_on');
        $unreported = $breaches->whereIn('status', ['detected', 'contained']);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Privacy', 'icon' => 'lock', 'stats' => [
                ['label' => 'Active processing activities', 'value' => (string) $this->records('processing')->where('status', 'active')->count()],
                ['label' => 'Open requests', 'value' => (string) $open->count()],
                ['label' => 'Requests overdue', 'value' => (string) $open->filter(fn (Record $request) => $request->due_on?->lt(today()))->count(), 'tone' => $open->contains(fn (Record $request) => $request->due_on?->lt(today())) ? 'danger' : null],
                ['label' => 'Breaches not yet reported', 'value' => (string) $unreported->count(), 'tone' => $unreported->isNotEmpty() ? 'danger' : null],
                ['label' => 'Reported late this year', 'value' => (string) $breaches->filter(fn (Record $breach) => $breach->value('_notified_late') && $breach->occurs_on?->isCurrentYear())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Requests due', 'icon' => 'calendar-clock', 'empty' => 'No open requests.',
                'rows' => $open->take(10)->map(fn (Record $request) => [
                    'label' => $request->title, 'sub' => ucfirst((string) $request->value('type')).' · '.ucfirst(str_replace('_', ' ', $request->status)), 'value' => $request->due_on?->format('d M Y'), 'href' => $request->url(), 'tone' => $request->due_on?->lt(today()) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $requests = $this->dated('requests', $from, $to)->get();
        $types = $this->app->entities['requests']->field('type')?->options ?? [];
        $byType = collect($types)->map(function (string $label, string $type) use ($requests) {
            $group = $requests->where('data.type', $type);
            $done = $group->filter(fn (Record $request) => $request->value('_days_to_respond') !== null);

            return [$label, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'refused')->count(), $done->filter(fn (Record $request) => $request->value('_on_time') === false)->count(), $done->isEmpty() ? '—' : round($done->avg(fn (Record $request) => (int) $request->value('_days_to_respond')), 1).' days'];
        })->values()->all();

        $breaches = $this->dated('breaches', $from, $to)->get()->map(fn (Record $breach) => [
            $breach->occurs_on?->format('d M Y'), $breach->title, number_format((int) $breach->value('records_affected')), ucfirst($breach->status), filled($breach->value('_notified_on')) ? Carbon::parse($breach->value('_notified_on'))->format('d M Y').($breach->value('_notified_late') ? ' (late)' : '') : 'Not notified',
        ])->values()->all();

        $processing = $this->records('processing')->where('status', 'active')->get();
        $bases = $this->app->entities['processing']->field('lawful_basis')?->options ?? [];
        $byBasis = collect($bases)->map(fn (string $label, string $basis) => [$label, $processing->where('data.lawful_basis', $basis)->count()])->values()->all();

        return [
            ['title' => 'Requests by type', 'columns' => ['Type', 'Received', 'Completed', 'Refused', 'Answered late', 'Average time'], 'rows' => $byType],
            ['title' => 'Breaches', 'columns' => ['Detected', 'Breach', 'Records', 'Status', 'Regulator'], 'rows' => $breaches],
            ['title' => 'Processing by lawful basis', 'columns' => ['Lawful basis', 'Activities'], 'rows' => $byBasis],
        ];
    }
}
