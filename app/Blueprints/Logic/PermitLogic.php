<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Permit to work: hot work and confined-space entry need a gas test, confined spaces and electrical work
 * need their isolations listed, and a permit's validity must end after it starts. Nobody issues a permit
 * to themselves, and a location has one live permit at a time. Permits move requested → approved →
 * active, can be suspended and resumed, and end closed or cancelled. Permits left active from an earlier
 * day are suspended overnight so they are re-checked before work restarts.
 */
class PermitLogic extends AppLogic
{
    /**
     * Allowed status moves.
     */
    protected const MOVES = [
        'requested' => ['approved', 'cancelled'],
        'approved' => ['active', 'cancelled'],
        'active' => ['suspended', 'closed'],
        'suspended' => ['active', 'closed', 'cancelled'],
        'closed' => [],
        'cancelled' => [],
    ];

    /**
     * Permit types that need a gas test before work starts.
     */
    protected const GAS_TEST = ['hot_work', 'confined_space'];

    /**
     * Permit types that need isolations listed.
     */
    protected const ISOLATION = ['confined_space', 'electrical_isolation'];

    /**
     * Statuses during which a permit holds its location.
     */
    protected const LIVE = ['approved', 'active', 'suspended'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing && $status !== $existing->status && ! in_array($status, self::MOVES[$existing->status] ?? [], true)) {
            $errors['status'] = 'A '.str_replace('_', ' ', $existing->status).' permit cannot become '.str_replace('_', ' ', $status).'.';
        }
        if (in_array($data['type'] ?? null, self::GAS_TEST, true) && in_array($status, ['active'], true) && blank($data['gas_test'] ?? null)) {
            $errors['data.gas_test'] = 'Record the gas test before work starts.';
        }
        if (in_array($data['type'] ?? null, self::ISOLATION, true) && blank($data['isolations'] ?? null)) {
            $errors['data.isolations'] = 'List the isolations and lock-outs.';
        }
        if (filled($data['start_time'] ?? null) && filled($data['end_time'] ?? null) && $data['end_time'] <= $data['start_time']) {
            $errors['data.end_time'] = 'The permit must end after it starts.';
        }
        if (filled($data['issuer'] ?? null) && filled($payload['assignee_id'] ?? null) && (int) $data['issuer'] === (int) $payload['assignee_id']) {
            $errors['data.issuer'] = 'The person issuing the permit cannot be the one doing the work.';
        }
        if (in_array($status, ['approved', 'active'], true) && blank($data['issuer'] ?? null)) {
            $errors['data.issuer'] = 'Say who is issuing the permit.';
        }
        if (in_array($status, self::LIVE, true) && filled($data['location'] ?? null) && ($clash = $this->liveAt((string) $data['location'], $existing?->id))) {
            $errors['data.location'] = $clash->number.' ('.$clash->title.') is already '.$clash->status.' at '.$clash->value('location').'.';
        }

        return $errors;
    }

    /**
     * The live permit at a location, matched however it was typed.
     */
    protected function liveAt(string $location, ?int $except = null): ?Record
    {
        $place = mb_strtolower(trim($location));

        return $this->records('permits')->whereIn('status', self::LIVE)->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $permit) => mb_strtolower(trim((string) $permit->value('location'))) === $place);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->isDirty('status')) {
            $this->put($record, ['_'.$record->status.'_at' => now()->toDateTimeString()]);
        }
    }

    public function actions(Record $record): array
    {
        $reason = [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']];

        return match ($record->status) {
            'requested' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => $reason]],
            'approved' => [
                'activate' => ['label' => 'Start work', 'icon' => 'play', 'fields' => in_array($record->value('type'), self::GAS_TEST, true) ? [['name' => 'gas_test', 'label' => 'Gas test result', 'type' => 'text', 'value' => $record->value('gas_test')]] : []],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => $reason],
            ],
            'active' => ['suspend' => ['label' => 'Suspend', 'icon' => 'pause', 'fields' => $reason], 'close' => ['label' => 'Close out', 'icon' => 'lock']],
            'suspended' => [
                'activate' => ['label' => 'Resume work', 'icon' => 'play', 'fields' => in_array($record->value('type'), self::GAS_TEST, true) ? [['name' => 'gas_test', 'label' => 'Fresh gas test result', 'type' => 'text']] : []],
                'close' => ['label' => 'Close out', 'icon' => 'lock'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $data = $record->data;
        $status = match ($action) {
            'approve' => 'approved',
            'activate' => 'active',
            'suspend' => 'suspended',
            'close' => 'closed',
            default => 'cancelled',
        };

        if (in_array($action, ['suspend', 'cancel'], true)) {
            $data['_'.$status.'_reason'] = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
        }
        if ($action === 'approve' && blank($data['issuer'] ?? null)) {
            $data['issuer'] = $request->user()->id;
        }
        if ($action === 'activate' && in_array($record->value('type'), self::GAS_TEST, true)) {
            $data['gas_test'] = trim($request->validate(['gas_test' => ['required', 'string', 'max:255']])['gas_test']);
        }

        $problems = $this->validate($this->app()->entity('permits'), ['status' => $status, 'data' => $data, 'assignee_id' => $record->assignee_id, 'title' => $record->title], $record);
        if ($problems) {
            throw ValidationException::withMessages(collect($problems)->mapWithKeys(fn (string $message, string $key) => [str_replace('data.', '', $key) => $message])->all());
        }
        $record->update(['status' => $status, 'data' => $data]);

        return $record->number.' '.$status.'.';
    }

    public function daily(Workspace $workspace): int
    {
        $stale = $this->records('permits')->where('status', 'active')->where('occurs_on', '<', today()->startOfDay())->get();
        $stale->each(fn (Record $permit) => $permit->update(['status' => 'suspended', 'data' => [...$permit->data, '_suspended_reason' => 'Not closed out by the end of the day']]));

        return $stale->count();
    }

    public function homeCards(): array
    {
        $live = $this->records('permits')->whereIn('status', ['active', 'suspended', 'approved'])->orderBy('occurs_on')->get()
            ->sortBy(fn (Record $permit) => array_search($permit->status, ['active', 'suspended', 'approved'], true));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Live permits', 'icon' => 'file-lock', 'empty' => 'No live permits.',
            'rows' => $live->map(fn (Record $permit) => [
                'label' => $permit->value('location').' · '.str_replace('_', ' ', (string) $permit->value('type')),
                'sub' => $permit->title.($permit->value('contractor') ? ' — '.$permit->value('contractor') : ''),
                'value' => ucfirst($permit->status), 'href' => $permit->url(),
                'tone' => match ($permit->status) {
                    'active' => 'success', 'suspended' => 'warning', default => null
                },
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $permits = $this->dated('permits', $from, $to)->get();

        $byType = $permits->groupBy(fn (Record $permit) => (string) $permit->value('type'))->sortKeys()
            ->map(fn (Collection $group, string $type) => [ucfirst(str_replace('_', ' ', $type)) ?: '—', $group->count(), $group->where('status', 'closed')->count(), $group->filter(fn (Record $permit) => $permit->value('_suspended_reason'))->count(), $group->where('status', 'cancelled')->count()])
            ->values()->all();

        $suspensions = $permits->filter(fn (Record $permit) => $permit->value('_suspended_reason'))
            ->map(fn (Record $permit) => [$permit->occurs_on?->format('d M Y'), $permit->number, $permit->value('location'), $permit->value('_suspended_reason')])->values()->all();

        return [
            ['title' => 'Permits by type', 'columns' => ['Type', 'Permits', 'Closed', 'Suspended', 'Cancelled'], 'rows' => $byType],
            ['title' => 'Suspensions', 'columns' => ['Date', 'Permit', 'Location', 'Reason'], 'rows' => $suspensions],
        ];
    }
}
