<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Estates, HOAs & levies: participation quotas across all units can't add up to more than 100%. An
 * ordinary levy defaults to the owner's monthly levy, and an owner is billed for each type once per
 * period. An owner's balance is what is still unpaid on their levies, and an owner with an overdue levy
 * is in arrears. Each night levies past their due date become overdue. An estate matter is resolved only
 * with a written resolution.
 */
class EstateLevyLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'owners') {
            $others = $this->records('owners')->where('status', '!=', 'sold')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->sum(fn (Record $owner) => $this->number($owner, 'participation_quota'));
            if ((float) ($data['participation_quota'] ?? 0) + $others > 100) {
                $errors['data.participation_quota'] = 'Quotas would add up to '.round((float) $data['participation_quota'] + $others, 2).'%; only '.round(100 - $others, 2).'% is left.';
            }
        }
        if ($entity->key === 'levies' && filled($data['owner'] ?? null) && filled($payload['title'] ?? null)) {
            $twin = $this->linked('levies', 'owner', (int) $data['owner'])->where('data->type', $data['type'] ?? 'ordinary')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $levy) => mb_strtolower(trim((string) $levy->title)) === mb_strtolower(trim((string) $payload['title'])));
            if ($twin) {
                $errors['title'] = 'This owner already has the '.str_replace('_', ' ', (string) ($data['type'] ?? 'ordinary')).' levy for '.$twin->title.'.';
            }
        }
        if ($entity->key === 'matters' && $payload['status'] === 'resolved' && blank($data['resolution'] ?? null)) {
            $errors['data.resolution'] = 'Write down the resolution.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'levies') {
            if ((float) $record->amount <= 0 && $record->value('type') === 'ordinary' && ($owner = $this->parent($record, 'owner'))) {
                $record->amount = $this->number($owner, 'levy');
            }
            $record->due_on ??= $record->occurs_on->copy()->addDays(30);
            if ($record->status === 'billed' && $record->due_on->lt(today())) {
                $record->status = 'overdue';
            }
        }
        if ($record->entity === 'owners' && $record->exists) {
            $open = $this->linked('levies', 'owner', $record)->whereIn('status', ['billed', 'overdue'])->get();
            $this->put($record, ['balance' => round((float) $open->sum(fn (Record $levy) => (float) $levy->amount), 2)]);
            if ($record->status !== 'sold') {
                $record->status = $open->contains('status', 'overdue') ? 'in_arrears' : 'current';
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'levies') {
            $this->recalculate($this->parent($record, 'owner'));
            $this->recalculate($this->previousParent($record, 'owner'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'levies') {
            $this->recalculate($this->parent($record, 'owner'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('levies')->where('status', 'billed')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $levy) => $levy->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'levies' && $record->status !== 'paid' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote']],
            $record->entity === 'matters' && $record->status === 'open' => ['committee' => ['label' => 'To committee', 'icon' => 'users'], 'resolve' => $this->resolveAction($record)],
            $record->entity === 'matters' && $record->status === 'in_committee' => ['resolve' => $this->resolveAction($record)],
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveAction(Record $record): array
    {
        return ['label' => 'Resolve', 'icon' => 'check', 'fields' => [['name' => 'resolution', 'label' => 'Resolution', 'type' => 'textarea', 'value' => $record->value('resolution')]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'pay':
                $record->update(['status' => 'paid']);
                $owner = $this->parent($record, 'owner');

                return $record->title.' levy paid'.($owner ? '; '.$owner->title.' owes '.$this->money($this->number($owner->fresh(), 'balance')) : '').'.';
            case 'committee':
                $record->update(['status' => 'in_committee']);

                return $record->title.' goes to the committee.';
            default:
                $resolution = trim((string) ($request->validate(['resolution' => ['nullable', 'string', 'max:5000']])['resolution'] ?? $record->value('resolution')));
                if ($resolution === '') {
                    throw ValidationException::withMessages(['resolution' => 'Write down the resolution.']);
                }
                $record->update(['status' => 'resolved', 'data' => [...$record->data, 'resolution' => $resolution]]);

                return $record->title.' resolved.';
        }
    }

    public function homeCards(): array
    {
        $owners = $this->records('owners')->where('status', '!=', 'sold')->get();
        $arrears = $owners->where('status', 'in_arrears')->sortByDesc(fn (Record $owner) => $this->number($owner, 'balance'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Levies', 'icon' => 'receipt', 'stats' => [
                ['label' => 'Monthly levy roll', 'value' => $this->money($owners->sum(fn (Record $owner) => $this->number($owner, 'levy')))],
                ['label' => 'Owed by owners', 'value' => $this->money($owners->sum(fn (Record $owner) => $this->number($owner, 'balance')))],
                ['label' => 'Quotas allocated', 'value' => round($owners->sum(fn (Record $owner) => $this->number($owner, 'participation_quota')), 2).'%'],
                ['label' => 'Open matters', 'value' => $this->records('matters')->where('status', '!=', 'resolved')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Owners in arrears', 'icon' => 'alert-triangle', 'empty' => 'No owners are in arrears.',
                'rows' => $arrears->map(fn (Record $owner) => ['label' => $owner->title, 'sub' => $owner->value('unit'), 'value' => $this->money($this->number($owner, 'balance')), 'href' => $owner->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $levies = $this->dated('levies', $from, $to)->get();

        return [['title' => 'Levies by type', 'columns' => ['Type', 'Billed', 'Paid', 'Overdue', 'Collection rate'], 'rows' => $levies
            ->groupBy(fn (Record $levy) => ucfirst(str_replace('_', ' ', (string) $levy->value('type'))))->sortKeys()
            ->map(function ($group, string $type) {
                $billed = (float) $group->sum(fn (Record $levy) => (float) $levy->amount);
                $paid = (float) $group->where('status', 'paid')->sum(fn (Record $levy) => (float) $levy->amount);

                return [$type, $this->money($billed), $this->money($paid), $this->money($group->where('status', 'overdue')->sum(fn (Record $levy) => (float) $levy->amount)), $billed > 0 ? round($paid / $billed * 100).'%' : '—'];
            })->values()->all()]];
    }
}
