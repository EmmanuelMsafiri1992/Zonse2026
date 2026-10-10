<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Warranty & returns: each serial number has one warranty, which runs 12 months from the sale unless
 * another term is given, and lapses each night once its end date passes. A fault or damage return on a
 * warranty needs it to be in force on the day the return is requested. Change-of-mind returns are only
 * taken within 14 days of the sale. A refund needs its amount, and every outcome needs a resolution note.
 */
class WarrantyLogic extends AppLogic
{
    public const DEFAULT_MONTHS = 12;

    public const CHANGE_OF_MIND_DAYS = 14;

    /**
     * Return statuses that close the return with an outcome.
     *
     * @var list<string>
     */
    public const OUTCOMES = ['repaired', 'replaced', 'refunded'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'warranties') {
            $serial = mb_strtoupper(trim((string) ($data['serial_number'] ?? '')));
            $taken = $serial === '' ? null : $this->records('warranties')->where('status', '!=', 'void')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $warranty) => mb_strtoupper(trim((string) $warranty->value('serial_number'))) === $serial);
            if ($taken && $payload['status'] !== 'void') {
                $errors['data.serial_number'] = 'Serial '.$serial.' is already under warranty '.$taken->number.'.';
            }

            return $errors;
        }
        $warranty = filled($data['warranty'] ?? null) ? $this->records('warranties')->find($data['warranty']) : null;
        $on = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : ($existing?->occurs_on ?? today());
        if (! $existing && $warranty && in_array($data['reason'] ?? null, ['faulty', 'damaged'], true)) {
            if ($warranty->status === 'void') {
                $errors['data.warranty'] = 'Warranty '.$warranty->number.' is void.';
            } elseif ($warranty->due_on && $warranty->due_on->lt($on)) {
                $errors['data.warranty'] = 'The warranty on '.$warranty->title.' ended on '.$warranty->due_on->format('d M Y').'.';
            }
        }
        if (! $existing && ($data['reason'] ?? null) === 'changed_mind' && $warranty?->occurs_on && $warranty->occurs_on->copy()->addDays(self::CHANGE_OF_MIND_DAYS)->lt($on)) {
            $errors['data.reason'] = 'Change-of-mind returns are only taken within '.self::CHANGE_OF_MIND_DAYS.' days of the sale.';
        }
        if ($payload['status'] === 'refunded' && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Give the refund amount.';
        }
        if (in_array($payload['status'], [...self::OUTCOMES, 'rejected'], true) && blank($data['resolution'] ?? null)) {
            $errors['data.resolution'] = 'Say what was done.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'warranties') {
            if ($record->status !== 'refunded') {
                $record->amount = 0;
            }

            return;
        }
        $this->put($record, ['serial_number' => mb_strtoupper(trim((string) $record->value('serial_number')))]);
        if (blank($record->value('months'))) {
            $this->put($record, ['months' => self::DEFAULT_MONTHS]);
        }
        if (! $record->due_on || $record->isDirty('occurs_on') || $record->isDirty('data')) {
            $record->due_on = $record->occurs_on->copy()->addMonthsNoOverflow((int) $record->value('months'));
        }
        if ($record->status !== 'void') {
            $record->status = $record->due_on->lt(today()) ? 'expired' : 'active';
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('warranties')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $warranty) => $warranty->save())->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'warranties') {
            return $record->status === 'active' ? ['void' => ['label' => 'Void', 'icon' => 'ban']] : [];
        }
        $note = ['name' => 'resolution', 'label' => 'What was done', 'type' => 'textarea', 'value' => $record->value('resolution')];
        $close = [
            'repaired' => ['label' => 'Repaired', 'icon' => 'wrench', 'fields' => [$note]],
            'replaced' => ['label' => 'Replaced', 'icon' => 'repeat', 'fields' => [$note]],
            'refunded' => ['label' => 'Refunded', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Refund amount', 'type' => 'number', 'value' => ''], $note]],
        ];

        return match ($record->status) {
            'requested' => ['approve' => ['label' => 'Approve', 'icon' => 'check'], 'rejected' => ['label' => 'Reject', 'icon' => 'x', 'fields' => [['name' => 'resolution', 'label' => 'Why', 'type' => 'textarea', 'value' => '']]]],
            'approved' => ['receive' => ['label' => 'Item received', 'icon' => 'package-check']],
            'received' => $close,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'void':
                $record->update(['status' => 'void']);

                return 'Warranty '.$record->number.' voided.';
            case 'approve':
                $record->update(['status' => 'approved']);

                return $record->title.' approved; waiting for the item.';
            case 'receive':
                $record->update(['status' => 'received']);

                return $record->title.' received.';
        }
        $input = $request->validate(
            ['resolution' => ['required', 'string'], 'amount' => [$action === 'refunded' ? 'required' : 'nullable', 'numeric', 'gt:0']],
            ['resolution.required' => 'Say what was done.', 'amount.required' => 'Give the refund amount.'],
        );
        $record->update(['status' => $action, 'amount' => (float) ($input['amount'] ?? 0), 'data' => [...$record->data, 'resolution' => $input['resolution']]]);

        return $record->title.' '.$action.($action === 'refunded' ? ' '.$this->money($record->amount) : '').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'warranties') {
            return [];
        }
        $days = (int) today()->diffInDays($record->due_on, false);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Cover', 'icon' => 'shield-check', 'stats' => [
                ['label' => 'Ends', 'value' => $record->due_on->format('d M Y')],
                ['label' => 'Days left', 'value' => $record->status === 'active' ? max(0, $days) : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Claims', 'icon' => 'undo-2', 'empty' => 'No returns on this warranty.',
                'rows' => $this->linked('returns', 'warranty', $record)->orderByDesc('occurs_on')->get()
                    ->map(fn (Record $return) => ['label' => $return->title, 'sub' => $return->occurs_on->format('d M Y'), 'value' => ucfirst($return->status), 'href' => $return->url()])->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $open = $this->records('returns')->whereIn('status', ['requested', 'approved', 'received'])->orderBy('occurs_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Returns waiting', 'icon' => 'undo-2', 'empty' => 'No returns waiting.',
                'rows' => $open->map(fn (Record $return) => ['label' => $return->title, 'sub' => ucfirst($return->status).' · '.$return->occurs_on->format('d M'), 'value' => (int) $return->occurs_on->diffInDays(today()).' days', 'href' => $return->url(), 'tone' => $return->status === 'requested' ? 'warning' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Warranties', 'icon' => 'shield-check', 'stats' => [
                ['label' => 'In force', 'value' => $this->records('warranties')->where('status', 'active')->count()],
                ['label' => 'Ending in 30 days', 'value' => $this->records('warranties')->where('status', 'active')->whereDate('due_on', '<=', today()->addDays(30)->toDateString())->count()],
                ['label' => 'Refunded this year', 'value' => $this->money($this->records('returns')->where('status', 'refunded')->whereYear('occurs_on', today()->year)->sum('amount'))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Returns by reason', 'columns' => ['Reason', 'Returns', 'Repaired', 'Replaced', 'Refunded', 'Rejected', 'Refunds'], 'rows' => $this->dated('returns', $from, $to)->get()
            ->groupBy(fn (Record $return) => ucfirst(str_replace('_', ' ', (string) $return->value('reason'))))->sortKeys()
            ->map(fn ($group, string $reason) => [$reason, $group->count(), $group->where('status', 'repaired')->count(), $group->where('status', 'replaced')->count(), $group->where('status', 'refunded')->count(), $group->where('status', 'rejected')->count(), $this->money($group->where('status', 'refunded')->sum('amount'))])
            ->values()->all()]];
    }
}
