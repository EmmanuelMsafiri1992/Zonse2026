<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Land registry: every title deed has one title number. A transfer is lodged against a registered
 * title only by its current owner and only one transfer may be in progress per title. When the
 * transfer is registered the title moves to the buyer and records the transfer history, and an
 * encumbered title cannot be transferred until its bond is cancelled.
 */
class LandRegistryLogic extends AppLogic
{
    public const PENDING = ['lodged', 'examined'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'titles') {
            $number = strtoupper(trim((string) ($data['title_number'] ?? '')));
            if ($number !== '' && $this->records('titles')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $title) => strtoupper(trim((string) $title->value('title_number'))) === $number)) {
                $errors['data.title_number'] = 'Title '.$number.' is already registered.';
            }
            if (filled($data['extent'] ?? null) && (float) $data['extent'] <= 0) {
                $errors['data.extent'] = 'The extent must be more than zero.';
            }
            if ($payload['status'] === 'encumbered' && blank($data['bonds'] ?? null)) {
                $errors['data.bonds'] = 'Describe the bond or servitude.';
            }

            return $errors;
        }

        $title = ! empty($data['title']) ? $this->records('titles')->find($data['title']) : null;
        $joining = ! $existing || (int) $existing->value('title') !== $title?->id;
        if ($title && in_array($payload['status'], self::PENDING, true)) {
            if ($title->status !== 'registered' && $joining) {
                $errors['data.title'] = 'Title '.$title->value('title_number').' is '.$title->status.' and cannot be transferred.';
            } elseif (filled($data['from_owner'] ?? null) && strcasecmp(trim((string) $data['from_owner']), trim((string) $title->value('owner'))) !== 0) {
                $errors['data.from_owner'] = 'The seller must be the registered owner, '.$title->value('owner').'.';
            } elseif ($this->linked('transfers', 'title', $title)->whereIn('status', self::PENDING)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.title'] = 'Another transfer of this title is already in progress.';
            }
        }
        if (filled($data['from_owner'] ?? null) && filled($data['to_owner'] ?? null) && strcasecmp(trim((string) $data['from_owner']), trim((string) $data['to_owner'])) === 0) {
            $errors['data.to_owner'] = 'The buyer and the seller are the same.';
        }
        if ((float) ($data['consideration'] ?? 0) < 0) {
            $errors['data.consideration'] = 'The purchase price cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'transfers') {
            $this->put($record, ['_registered_on' => $record->status === 'registered' ? ($record->value('_registered_on') ?? today()->toDateString()) : null]);

            return;
        }

        $transfers = $record->exists ? $this->linked('transfers', 'title', $record)->get() : collect();
        $last = $transfers->where('status', 'registered')->sortByDesc(fn (Record $transfer) => (string) $transfer->value('_registered_on'))->first();
        $this->put($record, [
            'title_number' => strtoupper(trim((string) $record->value('title_number'))) ?: null,
            '_transfers' => $transfers->where('status', 'registered')->count(),
            '_pending_transfer' => $transfers->whereIn('status', self::PENDING)->isNotEmpty(),
            '_last_price' => $last ? (float) $last->value('consideration') : null,
            '_last_transfer_on' => $last?->value('_registered_on'),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'transfers') {
            $this->recalculate($this->parent($record, 'title'));
            $this->recalculate($this->previousParent($record, 'title'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'transfers') {
            $this->recalculate($this->parent($record, 'title'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'transfers') {
            return match ($record->status) {
                'lodged' => ['examine' => ['label' => 'Examined', 'icon' => 'search-check'], 'reject' => ['label' => 'Reject', 'icon' => 'x', 'confirm' => 'Reject this transfer?']],
                'examined' => ['register' => ['label' => 'Register', 'icon' => 'stamp'], 'reject' => ['label' => 'Reject', 'icon' => 'x', 'confirm' => 'Reject this transfer?']],
                default => [],
            };
        }

        return match ($record->status) {
            'registered' => ['encumber' => ['label' => 'Register bond', 'icon' => 'landmark', 'fields' => [['name' => 'bonds', 'label' => 'Bond / servitude', 'type' => 'textarea', 'value' => $record->value('bonds')]]]],
            'encumbered' => ['cancel_bond' => ['label' => 'Cancel bond', 'icon' => 'unlock']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'examine':
                $record->update(['status' => 'examined']);

                return $record->title.' examined.';
            case 'reject':
                $record->update(['status' => 'rejected']);

                return $record->title.' rejected.';
            case 'register':
                $title = $this->parent($record, 'title');
                if (! $title || $title->status !== 'registered') {
                    throw ValidationException::withMessages(['status' => 'The title is not free to transfer.']);
                }
                $record->update(['status' => 'registered']);
                $title->update(['status' => 'registered', 'data' => [...$title->fresh()->data, 'owner' => $record->value('to_owner')]]);

                return 'Title '.$title->value('title_number').' registered to '.$record->value('to_owner').'.';
            case 'encumber':
                $bonds = $request->validate(['bonds' => ['required', 'string']])['bonds'];
                if ($record->value('_pending_transfer')) {
                    throw ValidationException::withMessages(['bonds' => 'A transfer of this title is in progress.']);
                }
                $record->update(['status' => 'encumbered', 'data' => [...$record->data, 'bonds' => $bonds]]);

                return 'Bond registered over '.$record->value('title_number').'.';
        }

        $record->update(['status' => 'registered', 'data' => [...$record->data, 'bonds' => null]]);

        return 'Bond over '.$record->value('title_number').' cancelled.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'titles') {
            return [];
        }

        $transfers = $this->linked('transfers', 'title', $record)->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Title deed', 'icon' => 'file-badge', 'stats' => [
                ['label' => 'Title number', 'value' => $record->value('title_number') ?: '—'],
                ['label' => 'Owner', 'value' => (string) ($record->value('owner') ?: '—')],
                ['label' => 'Extent', 'value' => filled($record->value('extent')) ? number_format((float) $record->value('extent')).' m²' : '—'],
                ['label' => 'Bonds', 'value' => $record->status === 'encumbered' ? 'Encumbered' : 'None', 'tone' => $record->status === 'encumbered' ? 'warning' : null],
                ['label' => 'Transfers', 'value' => (string) (int) $record->value('_transfers').($record->value('_pending_transfer') ? ' · one in progress' : '')],
                ['label' => 'Last price', 'value' => $record->value('_last_price') === null ? '—' : $this->money((float) $record->value('_last_price'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Transfer history', 'icon' => 'arrow-right-left', 'empty' => 'No transfers lodged.',
                'rows' => $transfers->take(15)->map(fn (Record $transfer) => [
                    'label' => $transfer->value('from_owner').' to '.$transfer->value('to_owner'), 'sub' => $transfer->occurs_on?->format('d M Y').($transfer->value('conveyancer') ? ' · '.$transfer->value('conveyancer') : ''), 'value' => ucfirst($transfer->status), 'href' => $transfer->url(), 'tone' => $transfer->status === 'registered' ? 'success' : ($transfer->status === 'rejected' ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $titles = $this->records('titles')->get();
        $transfers = $this->records('transfers')->get();
        $pending = $transfers->whereIn('status', self::PENDING)->sortBy('occurs_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Registry', 'icon' => 'file-badge', 'stats' => [
                ['label' => 'Titles', 'value' => (string) $titles->count()],
                ['label' => 'Encumbered', 'value' => (string) $titles->where('status', 'encumbered')->count()],
                ['label' => 'Transfers lodged', 'value' => (string) $transfers->where('status', 'lodged')->count()],
                ['label' => 'Awaiting registration', 'value' => (string) $transfers->where('status', 'examined')->count()],
                ['label' => 'Registered this month', 'value' => (string) $transfers->filter(fn (Record $transfer) => filled($transfer->value('_registered_on')) && Carbon::parse($transfer->value('_registered_on'))->isCurrentMonth())->count()],
                ['label' => 'Fees this month', 'value' => $this->money($transfers->filter(fn (Record $transfer) => $transfer->occurs_on?->isCurrentMonth())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Transfers in progress', 'icon' => 'hourglass', 'empty' => 'No transfers in progress.',
                'rows' => $pending->take(10)->map(fn (Record $transfer) => [
                    'label' => $transfer->title, 'sub' => $transfer->value('from_owner').' to '.$transfer->value('to_owner'), 'value' => (int) $transfer->occurs_on->diffInDays(today()).' days', 'href' => $transfer->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $transfers = $this->dated('transfers', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($transfers) {
            $group = $transfers->filter(fn (Record $transfer) => $transfer->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'registered')->count(), $group->where('status', 'rejected')->count(), $this->money($group->where('status', 'registered')->sum(fn (Record $transfer) => (float) $transfer->value('consideration'))), $this->money($group->sum('amount'))];
        })->values()->all();

        $byConveyancer = $transfers->groupBy(fn (Record $transfer) => $transfer->value('conveyancer') ?: 'Unknown')->sortKeys()->map(fn ($group, $name) => [$name, $group->count(), $group->where('status', 'registered')->count(), $group->where('status', 'rejected')->count()])->values()->all();

        $titles = $this->records('titles')->get();
        $byLocation = $titles->groupBy(fn (Record $title) => $title->value('location') ?: 'Unknown')->sortKeys()->map(fn ($group, $location) => [$location, $group->count(), $group->where('status', 'encumbered')->count(), number_format($group->sum(fn (Record $title) => (float) $title->value('extent')))])->values()->all();

        return [
            ['title' => 'Transfers by month', 'columns' => ['Month', 'Lodged', 'Registered', 'Rejected', 'Value', 'Fees'], 'rows' => $byMonth],
            ['title' => 'Transfers by conveyancer', 'columns' => ['Conveyancer', 'Lodged', 'Registered', 'Rejected'], 'rows' => $byConveyancer],
            ['title' => 'Titles by location', 'columns' => ['Location', 'Titles', 'Encumbered', 'Extent (m²)'], 'rows' => $byLocation],
        ];
    }
}
