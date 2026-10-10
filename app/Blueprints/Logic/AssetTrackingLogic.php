<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Asset tracking: every asset carries one tag, however it was typed. Only an available asset can be
 * checked out, and the asset follows its check-out: out while it is held, back to available when it
 * is returned, or into repair if it came back damaged. Check-outs past their due date turn overdue
 * each morning, and assets that are lost or disposed of stay out of circulation.
 */
class AssetTrackingLogic extends AppLogic
{
    /**
     * Conditions an asset can come back in.
     */
    protected const CONDITIONS = ['good' => 'Good', 'worn' => 'Worn', 'damaged' => 'Damaged'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'assets') {
            $tag = $this->tag((string) ($data['tag'] ?? ''));
            $taken = $this->records('assets')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $asset) => $this->tag((string) $asset->value('tag')) === $tag);
            if ($tag !== '' && $taken) {
                $errors['data.tag'] = 'Tag '.$tag.' is already on '.$taken->title.'.';
            }
            $held = $existing ? $this->openCheckout($existing) : null;
            if ($status === 'checked_out' && ! $held) {
                $errors['status'] = 'Record a check-out so we know who has it.';
            }
            if ($held && $status !== 'checked_out' && $status !== 'lost') {
                $errors['status'] = $existing->title.' is checked out on '.$held->number.'; return it first.';
            }
            if ($existing?->status === 'disposed' && $status !== 'disposed') {
                $errors['status'] = 'This asset has been disposed of.';
            }

            return $errors;
        }

        $asset = filled($data['asset'] ?? null) ? $this->records('assets')->find($data['asset']) : null;
        if ($asset && in_array($status, ['out', 'overdue'], true)) {
            $held = $this->openCheckout($asset);
            if ($held && $held->id !== $existing?->id) {
                $errors['data.asset'] = $asset->title.' is already checked out on '.$held->number.'.';
            } elseif (! $held && $asset->status !== 'available') {
                $errors['data.asset'] = $asset->title.' is '.str_replace('_', ' ', $asset->status).' and cannot be checked out.';
            }
        }
        if ($existing && $asset && (int) $existing->value('asset') !== $asset->id && $existing->status !== 'returned') {
            $errors['data.asset'] = 'Return the asset before changing it.';
        }
        if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on'])->startOfDay())) {
            $errors['due_on'] = 'The due date cannot be before the check-out.';
        }
        if ($status === 'returned' && $existing?->status !== 'returned' && blank($data['condition_on_return'] ?? null)) {
            $errors['data.condition_on_return'] = 'Record the condition it came back in.';
        }
        if ($existing?->status === 'returned' && $status !== 'returned') {
            $errors['status'] = 'This check-out is closed; start a new one.';
        }

        return $errors;
    }

    /**
     * A tag in one form however it was typed.
     */
    protected function tag(string $tag): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tag));
    }

    /**
     * The check-out currently holding the asset, if any.
     */
    protected function openCheckout(Record $asset): ?Record
    {
        return $this->linked('checkouts', 'asset', $asset)->whereIn('status', ['out', 'overdue'])->latest('id')->first();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'assets') {
            $this->put($record, ['tag' => $this->tag((string) $record->value('tag'))]);

            return;
        }
        $record->occurs_on ??= today();
        if ($record->isDirty('status') && $record->status === 'returned') {
            $late = $record->due_on && today()->gt($record->due_on) ? (int) $record->due_on->copy()->startOfDay()->diffInDays(today()) : 0;
            $this->put($record, ['_returned_on' => today()->toDateString(), '_late_days' => $late]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'checkouts' || ! ($asset = $this->parent($record, 'asset'))) {
            return;
        }
        if (in_array($record->status, ['out', 'overdue'], true)) {
            if ($asset->status !== 'checked_out') {
                $asset->update(['status' => 'checked_out']);
            }

            return;
        }
        if ($record->wasChanged('status') && $asset->status === 'checked_out' && ! $this->openCheckout($asset)) {
            $damaged = str_starts_with(strtolower((string) $record->value('condition_on_return')), 'damaged');
            $asset->update(['status' => $damaged ? 'in_repair' : 'available']);
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'checkouts' && ($asset = $this->parent($record, 'asset')) && $asset->status === 'checked_out' && ! $this->openCheckout($asset)) {
            $asset->update(['status' => 'available']);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'checkouts') {
            return in_array($record->status, ['out', 'overdue'], true) ? ['return' => ['label' => 'Return', 'icon' => 'log-in', 'fields' => [
                ['name' => 'condition', 'label' => 'Condition', 'type' => 'select', 'options' => self::CONDITIONS, 'value' => 'good'],
                ['name' => 'notes', 'label' => 'Notes', 'type' => 'text'],
            ]]] : [];
        }

        return match ($record->status) {
            'in_repair' => ['repaired' => ['label' => 'Back in service', 'icon' => 'wrench']],
            'lost' => ['found' => ['label' => 'Found', 'icon' => 'search-check']],
            'available' => ['dispose' => ['label' => 'Dispose of', 'icon' => 'trash-2', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]],
            'checked_out' => ['lost' => ['label' => 'Report lost', 'icon' => 'circle-help']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'return':
                $values = $request->validate(['condition' => ['required', 'in:'.implode(',', array_keys(self::CONDITIONS))], 'notes' => ['nullable', 'string', 'max:255']]);
                $condition = self::CONDITIONS[$values['condition']].(filled($values['notes'] ?? null) ? ': '.trim($values['notes']) : '');
                $record->update(['status' => 'returned', 'data' => [...$record->data, 'condition_on_return' => $condition]]);
                $asset = $this->parent($record, 'asset');

                return ($asset?->title ?? $record->title).' returned'.($values['condition'] === 'damaged' ? ' damaged and sent for repair.' : '.').((int) $record->value('_late_days') > 0 ? ' '.$record->value('_late_days').' '.str('day')->plural((int) $record->value('_late_days')).' late.' : '');
            case 'repaired':
            case 'found':
                $record->update(['status' => 'available']);

                return $record->title.' is available again.';
            case 'dispose':
                $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
                $record->update(['status' => 'disposed', 'data' => [...$record->data, '_disposed_on' => today()->toDateString(), '_disposed_reason' => $reason]]);

                return $record->title.' disposed of: '.$reason.'.';
            default:
                if ($checkout = $this->openCheckout($record)) {
                    $checkout->update(['status' => 'returned', 'data' => [...$checkout->data, 'condition_on_return' => 'Lost']]);
                }
                $record->update(['status' => 'lost', 'data' => [...$record->data, '_lost_on' => today()->toDateString()]]);

                return $record->title.' reported lost.';
        }
    }

    public function daily(Workspace $workspace): int
    {
        $late = $this->records('checkouts')->where('status', 'out')->whereNotNull('due_on')->where('due_on', '<', today()->startOfDay())->get();
        $late->each(fn (Record $checkout) => $checkout->update(['status' => 'overdue']));

        return $late->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'assets') {
            return [];
        }
        $history = $this->linked('checkouts', 'asset', $record)->latest('occurs_on')->latest('id')->limit(10)->get();
        $names = $this->holders($history);

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Check-out history', 'icon' => 'history', 'empty' => 'Never checked out.',
            'rows' => $history->map(fn (Record $checkout) => [
                'label' => $names[$checkout->value('holder')] ?? 'Unknown', 'sub' => $checkout->occurs_on?->format('d M Y').' · '.$checkout->title,
                'value' => $checkout->status === 'returned' ? (string) $checkout->value('condition_on_return') : ucfirst($checkout->status),
                'href' => $checkout->url(), 'tone' => $checkout->status === 'overdue' ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    /**
     * Names of the people holding the check-outs, by user id.
     *
     * @return Collection<int, string>
     */
    protected function holders(Collection $checkouts): Collection
    {
        return User::query()->whereIn('id', $checkouts->map(fn (Record $checkout) => $checkout->value('holder'))->filter()->unique())->pluck('name', 'id');
    }

    public function homeCards(): array
    {
        $assets = $this->records('assets')->get();
        $overdue = $this->records('checkouts')->where('status', 'overdue')->orderBy('due_on')->get();
        $names = $this->holders($overdue);
        $titles = $assets->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Assets', 'icon' => 'scan-qr-code', 'stats' => [
                ['label' => 'Available', 'value' => $assets->where('status', 'available')->count(), 'tone' => 'success'],
                ['label' => 'Checked out', 'value' => $assets->where('status', 'checked_out')->count()],
                ['label' => 'In repair', 'value' => $assets->where('status', 'in_repair')->count(), 'tone' => $assets->where('status', 'in_repair')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Lost', 'value' => $assets->where('status', 'lost')->count(), 'tone' => $assets->where('status', 'lost')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue returns', 'icon' => 'alarm-clock', 'empty' => 'Nothing overdue.',
                'rows' => $overdue->map(fn (Record $checkout) => ['label' => $titles[$checkout->value('asset')] ?? $checkout->title, 'sub' => $names[$checkout->value('holder')] ?? null, 'value' => 'Due '.$checkout->due_on?->format('d M'), 'href' => $checkout->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $checkouts = $this->dated('checkouts', $from, $to)->get();
        $names = $this->holders($checkouts);

        $byHolder = $checkouts->groupBy(fn (Record $checkout) => $names[$checkout->value('holder')] ?? 'Unknown')->sortKeys()
            ->map(fn (Collection $group, string $holder) => [
                $holder, $group->count(), $group->whereIn('status', ['out', 'overdue'])->count(), $group->where('status', 'overdue')->count(),
                $group->filter(fn (Record $checkout) => (int) $checkout->value('_late_days') > 0)->count(),
                $group->filter(fn (Record $checkout) => str_starts_with(strtolower((string) $checkout->value('condition_on_return')), 'damaged'))->count(),
            ])->values()->all();

        $assets = $this->records('assets')->get();
        $byStatus = collect(['available', 'checked_out', 'in_repair', 'lost', 'disposed'])
            ->map(fn (string $status) => [ucfirst(str_replace('_', ' ', $status)), $assets->where('status', $status)->count(), $this->money($assets->where('status', $status)->sum('amount'))])->all();

        return [
            ['title' => 'Check-outs by holder', 'columns' => ['Holder', 'Check-outs', 'Still out', 'Overdue', 'Returned late', 'Returned damaged'], 'rows' => $byHolder],
            ['title' => 'Assets by status', 'columns' => ['Status', 'Assets', 'Value'], 'rows' => $byStatus],
        ];
    }
}
