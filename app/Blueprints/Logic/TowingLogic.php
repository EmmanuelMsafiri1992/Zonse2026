<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Towing & roadside assistance: a call-out is dispatched to a driver who isn't on another job, goes
 * on scene, and, for tows and accidents, tows to a destination before it completes. Roadside fixes
 * skip the tow. Each step is time-stamped, so response times are measured, and the charge is the
 * call-out fee for the job type plus a rate per kilometre towed. Cancelling needs a reason.
 */
class TowingLogic extends AppLogic
{
    /**
     * Call-out steps in order.
     */
    protected const STEPS = ['received', 'dispatched', 'on_scene', 'towing', 'completed'];

    /**
     * Job types that tow the vehicle away.
     */
    protected const TOWS = ['tow', 'accident'];

    /**
     * Call-out fee by job type.
     */
    public const FEES = ['tow' => 60, 'accident' => 90, 'jump_start' => 30, 'flat_tyre' => 30, 'fuel' => 25, 'lockout' => 35];

    /**
     * Charge per kilometre towed.
     */
    public const PER_KM = 2.5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing && in_array($existing->status, ['completed', 'cancelled'], true) && $status !== $existing->status) {
            return ['status' => 'This call-out is '.$existing->status.'.'];
        }
        if (in_array($status, ['completed', 'cancelled'], true) && $status !== $existing?->status) {
            return ['status' => $status === 'completed' ? 'Use "Complete" so the charge is worked out.' : 'Use "Cancel" so the reason is recorded.'];
        }
        if ($existing && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
            $errors['status'] = 'A call-out cannot go back to '.str_replace('_', ' ', $status).'.';
        }
        if ($status === 'towing' && ! in_array($data['type'] ?? null, self::TOWS, true)) {
            $errors['status'] = 'Only tows and accidents are towed.';
        }
        if ($status === 'towing' && blank($data['destination'] ?? null)) {
            $errors['data.destination'] = 'Say where the vehicle is being towed.';
        }
        if (! in_array($status, ['received', 'completed', 'cancelled'], true)) {
            if (blank($payload['assignee_id'] ?? null)) {
                $errors['assignee_id'] = 'Assign a driver.';
            } elseif ($busy = $this->busy((int) $payload['assignee_id'], $existing)) {
                $errors['assignee_id'] = 'That driver is on '.$busy->title.'.';
            }
        }

        return $errors;
    }

    /**
     * The driver's other active call-out, if any.
     */
    protected function busy(int $driver, ?Record $except): ?Record
    {
        return $this->records('callouts')->where('assignee_id', $driver)->whereIn('status', ['dispatched', 'on_scene', 'towing'])
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->first();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if (filled($record->value('registration'))) {
            $this->put($record, ['registration' => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $record->value('registration')))]);
        }
        if (! $record->exists) {
            $this->put($record, ['_received_at' => now()->toDateTimeString()]);
        }
        if ($record->isDirty('status') && in_array($record->status, ['dispatched', 'on_scene', 'towing', 'completed'], true) && ! $record->value('_'.$record->status.'_at')) {
            $this->put($record, ['_'.$record->status.'_at' => now()->toDateTimeString()]);
        }
        if ($record->isDirty('status') && $record->status === 'on_scene' && $record->value('_received_at')) {
            $this->put($record, ['_response_minutes' => (int) Carbon::parse($record->value('_received_at'))->diffInMinutes(now())]);
        }
    }

    public function actions(Record $record): array
    {
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]];
        $complete = ['complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [['name' => 'distance', 'label' => 'Distance towed (km)', 'type' => 'number', 'value' => $record->value('distance') ?? 0]]]];

        return match ($record->status) {
            'received' => ['dispatch' => ['label' => 'Dispatch', 'icon' => 'siren'], ...$cancel],
            'dispatched' => ['arrive' => ['label' => 'On scene', 'icon' => 'map-pin'], ...$cancel],
            'on_scene' => in_array($record->value('type'), self::TOWS, true)
                ? ['tow' => ['label' => 'Towing', 'icon' => 'truck', 'fields' => [['name' => 'destination', 'label' => 'Tow to', 'type' => 'text', 'value' => $record->value('destination')]]], ...$cancel]
                : ['complete' => ['label' => 'Complete', 'icon' => 'check']],
            'towing' => $complete,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'dispatch':
                if (! $record->assignee_id) {
                    throw ValidationException::withMessages(['assignee_id' => 'Assign a driver before dispatching.']);
                }
                if ($busy = $this->busy($record->assignee_id, $record)) {
                    throw ValidationException::withMessages(['assignee_id' => 'That driver is on '.$busy->title.'.']);
                }
                $record->update(['status' => 'dispatched']);

                return $record->title.' dispatched to '.$record->assignee?->name.'.';
            case 'arrive':
                $record->update(['status' => 'on_scene']);

                return $record->title.': on scene after '.$record->value('_response_minutes').' minutes.';
            case 'tow':
                $destination = trim($request->validate(['destination' => ['required', 'string', 'max:255']])['destination']);
                $record->update(['status' => 'towing', 'data' => [...$record->data, 'destination' => $destination]]);

                return $record->title.' on tow to '.$destination.'.';
            case 'complete':
                $towed = in_array($record->value('type'), self::TOWS, true);
                $distance = $towed ? (float) $request->validate(['distance' => ['required', 'numeric', 'min:0']])['distance'] : 0;
                $fee = self::FEES[$record->value('type')] ?? 0;
                $charge = round($fee + $distance * self::PER_KM, 2);
                $record->update(['status' => 'completed', 'amount' => $charge, 'data' => [...$record->data, 'distance' => $distance, '_fee' => $fee, '_distance_charge' => round($distance * self::PER_KM, 2)]]);

                return $record->title.' completed: '.$this->money($charge).($record->value('insurer') ? ' to bill '.$record->value('insurer').'.' : '.');
            default:
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

                return $record->title.' cancelled: '.$reason.'.';
        }
    }

    public function homeCards(): array
    {
        $active = $this->records('callouts')->whereIn('status', ['received', 'dispatched', 'on_scene', 'towing'])->with('assignee')->orderBy('id')->get();
        $today = $this->records('callouts')->whereDate('occurs_on', today()->toDateString())->get();
        $responded = $today->filter(fn (Record $callout) => $callout->value('_response_minutes') !== null);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'siren', 'stats' => [
                ['label' => 'Call-outs', 'value' => $today->count()],
                ['label' => 'Waiting for a driver', 'value' => $active->where('status', 'received')->count(), 'tone' => $active->where('status', 'received')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Average response', 'value' => $responded->isNotEmpty() ? round($responded->avg(fn (Record $callout) => (int) $callout->value('_response_minutes'))).' min' : '—'],
                ['label' => 'Completed', 'value' => $today->where('status', 'completed')->count(), 'tone' => 'success'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Active call-outs', 'icon' => 'map-pin', 'empty' => 'No call-outs open.',
                'rows' => $active->map(fn (Record $callout) => [
                    'label' => $callout->title, 'sub' => str_replace('_', ' ', $callout->status).($callout->assignee ? ' · '.$callout->assignee->name : ''),
                    'value' => (int) Carbon::parse($callout->value('_received_at') ?? $callout->created_at)->diffInMinutes(now()).' min', 'href' => $callout->url(),
                    'tone' => $callout->status === 'received' ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $callouts = $this->dated('callouts', $from, $to)->get();
        $done = $callouts->where('status', 'completed');
        $average = fn (Collection $group) => $group->filter(fn (Record $callout) => $callout->value('_response_minutes') !== null)->avg(fn (Record $callout) => (int) $callout->value('_response_minutes'));
        $names = User::query()->whereIn('id', $callouts->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');

        return [
            ['title' => 'Call-outs by type', 'columns' => ['Type', 'Call-outs', 'Completed', 'Cancelled', 'Average response', 'Revenue'], 'rows' => $callouts->groupBy(fn (Record $callout) => (string) $callout->value('type'))->sortKeys()
                ->map(fn (Collection $group, string $type) => [ucfirst(str_replace('_', ' ', $type)), $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'cancelled')->count(), ($minutes = $average($group)) !== null ? round($minutes).' min' : '—', $this->money($group->where('status', 'completed')->sum('amount'))])->values()->all()],
            ['title' => 'Drivers', 'columns' => ['Driver', 'Jobs completed', 'Average response', 'Kilometres towed'], 'rows' => $done->groupBy(fn (Record $callout) => $names[$callout->assignee_id] ?? 'Unassigned')->sortKeys()
                ->map(fn (Collection $group, string $driver) => [$driver, $group->count(), ($minutes = $average($group)) !== null ? round($minutes).' min' : '—', number_format($group->sum(fn (Record $callout) => $this->number($callout, 'distance')), 1)])->values()->all()],
            ['title' => 'Billing by insurer', 'columns' => ['Insurer / membership', 'Jobs', 'Billed'], 'rows' => $done->groupBy(fn (Record $callout) => $callout->value('insurer') ?: 'Private')->sortKeys()
                ->map(fn (Collection $group, string $insurer) => [$insurer, $group->count(), $this->money($group->sum('amount'))])->values()->all()],
        ];
    }
}
