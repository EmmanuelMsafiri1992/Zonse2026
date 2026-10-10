<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Rent collection: a charge totals its rent and utility recharges, and one unit is charged once per rent
 * period. Its paid amount is added up from payments received, and it turns part paid, paid or, once past
 * its due date, in arrears. A payment can't be more than what is still owed on the charge; a bounced or
 * reversed payment stops counting. Each night overdue charges move into arrears.
 */
class RentCollectionLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'charges') {
            if ((float) ($data['rent'] ?? 0) <= 0) {
                $errors['data.rent'] = 'Set the rent.';
            }
            $twin = filled($data['unit'] ?? null) && filled($data['period'] ?? null) ? $this->records('charges')->where('status', '!=', 'written_off')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $charge) => $this->same($charge->value('unit'), $data['unit']) && $this->same($charge->value('period'), $data['period'])) : null;
            if ($twin) {
                $errors['data.period'] = $data['unit'].' is already charged for '.$data['period'].'.';
            }
        }
        if ($entity->key === 'payments' && filled($data['charge'] ?? null)) {
            $amount = (float) ($payload['amount'] ?? 0);
            $charge = $this->records('charges')->find($data['charge']);
            $owed = $charge ? round((float) $charge->amount - $this->received($charge, $existing?->id), 2) : 0;
            if ($amount <= 0) {
                $errors['amount'] = 'Give an amount above zero.';
            } elseif ($charge && $payload['status'] === 'received' && $amount > $owed) {
                $errors['amount'] = $owed > 0 ? 'Only '.$this->money($owed).' is owed on '.$charge->title.'.' : $charge->title.' is already paid.';
            }
        }

        return $errors;
    }

    protected function same(mixed $first, mixed $second): bool
    {
        return mb_strtolower(trim((string) $first)) === mb_strtolower(trim((string) $second));
    }

    /**
     * Money received against a charge, leaving out one payment when it is being changed.
     */
    protected function received(Record $charge, ?int $except = null): float
    {
        return (float) $this->linked('payments', 'charge', $charge)->where('status', 'received')->when($except, fn ($query) => $query->whereKeyNot($except))->sum('amount');
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'charges') {
            return;
        }
        $record->amount = round($this->number($record, 'rent') + $this->number($record, 'utilities'), 2);
        if ($record->exists) {
            $this->put($record, ['paid' => round($this->received($record), 2)]);
        }
        if ($record->status === 'written_off') {
            return;
        }
        $paid = $this->number($record, 'paid');
        $record->status = match (true) {
            $paid >= (float) $record->amount => 'paid',
            $record->due_on && $record->due_on->lt(today()) => 'in_arrears',
            $paid > 0 => 'part_paid',
            default => 'due',
        };
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'charge'));
            $this->recalculate($this->previousParent($record, 'charge'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'payments') {
            $this->recalculate($this->parent($record, 'charge'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('charges')->whereIn('status', ['due', 'part_paid'])->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $charge) => $charge->save())->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'charges') {
            return [];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Collection', 'icon' => 'coins', 'stats' => [
            ['label' => 'Total due', 'value' => $this->money($record->amount)],
            ['label' => 'Paid', 'value' => $this->money($this->number($record, 'paid'))],
            ['label' => 'Owed', 'value' => $this->money(max(0, (float) $record->amount - $this->number($record, 'paid'))), 'tone' => $record->status === 'in_arrears' ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $arrears = $this->records('charges')->where('status', 'in_arrears')->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'In arrears ('.$this->money($arrears->sum(fn (Record $charge) => (float) $charge->amount - $this->number($charge, 'paid'))).')', 'icon' => 'alert-triangle', 'empty' => 'Nobody is in arrears.',
            'rows' => $arrears->map(fn (Record $charge) => ['label' => $charge->contact?->name ?? $charge->title, 'sub' => $charge->value('unit').' · '.$charge->value('period'), 'value' => $this->money((float) $charge->amount - $this->number($charge, 'paid')), 'href' => $charge->url(), 'tone' => 'danger'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $charges = $this->dated('charges', $from, $to)->where('status', '!=', 'written_off')->get();

        return [['title' => 'Collection by unit', 'columns' => ['Unit', 'Charged', 'Collected', 'Owed', 'Collection rate'], 'rows' => $charges
            ->groupBy(fn (Record $charge) => (string) $charge->value('unit'))->sortKeys()
            ->map(function ($group, string $unit) {
                $charged = (float) $group->sum(fn (Record $charge) => (float) $charge->amount);
                $paid = (float) $group->sum(fn (Record $charge) => $this->number($charge, 'paid'));

                return [$unit, $this->money($charged), $this->money($paid), $this->money($charged - $paid), $charged > 0 ? round($paid / $charged * 100).'%' : '—'];
            })->values()->all()]];
    }
}
