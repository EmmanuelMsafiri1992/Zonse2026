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
 * Sales commissions & targets: a commission is the sale value times the rate, which defaults to 5%.
 * Commissions move earned → approved → paid. A target covers a period, and its achieved figure is the
 * rep's sales in that period. It is achieved once sales reach the target, and missed if the period ends
 * short; targets whose period has ended are settled each night.
 */
class SalesCommissionLogic extends AppLogic
{
    public const DEFAULT_RATE = 5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'targets') {
            if (blank($payload['occurs_on'] ?? null) || blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'Give the period start and end.';
            } elseif (Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The period must end after it starts.';
            }
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give the target.';
            }

            return $errors;
        }
        if (filled($data['rate'] ?? null) && ((float) $data['rate'] < 0 || (float) $data['rate'] > 100)) {
            $errors['data.rate'] = 'The rate must be between 0 and 100%.';
        }
        if ((float) ($data['sale_value'] ?? 0) <= 0) {
            $errors['data.sale_value'] = 'Give the sale value.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'commissions') {
            $record->occurs_on ??= today();
            if (blank($record->value('rate'))) {
                $this->put($record, ['rate' => self::DEFAULT_RATE]);
            }
            $record->amount = round($this->number($record, 'sale_value') * $this->number($record, 'rate') / 100, 2);

            return;
        }
        if (! $record->occurs_on || ! $record->due_on) {
            return;
        }
        $achieved = $this->sales((int) $record->value('rep'), $record->occurs_on, $record->due_on);
        $this->put($record, ['achieved' => $achieved]);
        $record->status = match (true) {
            $achieved >= (float) $record->amount => 'achieved',
            $record->due_on->lt(today()) => 'missed',
            default => 'active',
        };
    }

    /**
     * A rep's commissioned sales between two dates.
     */
    protected function sales(int $rep, Carbon $from, Carbon $to): float
    {
        return (float) $this->records('commissions')->whereDate('occurs_on', '>=', $from->toDateString())->whereDate('occurs_on', '<=', $to->toDateString())->get()
            ->filter(fn (Record $commission) => (int) $commission->value('rep') === $rep)
            ->sum(fn (Record $commission) => $this->number($commission, 'sale_value'));
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'commissions') {
            return;
        }
        $reps = array_unique(array_filter([(int) $record->value('rep'), (int) (((array) $record->getOriginal('data'))['rep'] ?? 0)]));
        $this->targetsCovering($record, $reps)->each(fn (Record $target) => $target->save());
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'commissions') {
            $this->targetsCovering($record, [(int) $record->value('rep')])->each(fn (Record $target) => $target->save());
        }
    }

    /**
     * The targets of the given reps whose period covers a commission's date.
     *
     * @param  list<int>  $reps
     * @return Collection<int, Record>
     */
    protected function targetsCovering(Record $commission, array $reps)
    {
        $on = ($commission->occurs_on ?? today())->toDateString();

        return $this->records('targets')->whereDate('occurs_on', '<=', $on)->whereDate('due_on', '>=', $on)->get()
            ->filter(fn (Record $target) => in_array((int) $target->value('rep'), $reps, true));
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('targets')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $target) => $target->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'commissions' && $record->status === 'earned' => ['approve' => ['label' => 'Approve', 'icon' => 'check']],
            $record->entity === 'commissions' && $record->status === 'approved' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $record->update(['status' => $action === 'approve' ? 'approved' : 'paid']);

        return $this->money($record->amount).' commission on '.$record->title.' '.$record->status.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'targets') {
            return [];
        }
        $target = (float) $record->amount;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Progress', 'icon' => 'target', 'stats' => [
            ['label' => 'Achieved', 'value' => $this->money($this->number($record, 'achieved'))],
            ['label' => 'Of target', 'value' => $target > 0 ? round($this->number($record, 'achieved') / $target * 100).'%' : '—'],
            ['label' => 'Still to go', 'value' => $this->money(max(0, $target - $this->number($record, 'achieved')))],
        ]]]];
    }

    public function homeCards(): array
    {
        $targets = $this->records('targets')->where('status', '!=', 'missed')->whereDate('occurs_on', '<=', today()->toDateString())->whereDate('due_on', '>=', today()->toDateString())->get();
        $names = User::query()->whereIn('id', $targets->map(fn (Record $target) => $target->value('rep'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Targets this period', 'icon' => 'target', 'empty' => 'No targets running now.',
                'rows' => $targets->map(fn (Record $target) => ['label' => $names[$target->value('rep')] ?? 'Unassigned', 'sub' => $target->title, 'value' => (float) $target->amount > 0 ? round($this->number($target, 'achieved') / (float) $target->amount * 100).'%' : '—', 'href' => $target->url(), 'tone' => $target->status === 'achieved' ? 'success' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Commissions', 'icon' => 'hand-coins', 'stats' => [
                ['label' => 'To approve', 'value' => $this->money($this->records('commissions')->where('status', 'earned')->sum('amount'))],
                ['label' => 'Approved, not paid', 'value' => $this->money($this->records('commissions')->where('status', 'approved')->sum('amount'))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $commissions = $this->dated('commissions', $from, $to)->get();
        $names = User::query()->whereIn('id', $commissions->map(fn (Record $commission) => $commission->value('rep'))->filter()->unique())->pluck('name', 'id');

        return [['title' => 'Commissions by rep', 'columns' => ['Rep', 'Sales', 'Sale value', 'Commission', 'Paid'], 'rows' => $commissions
            ->groupBy(fn (Record $commission) => $names[$commission->value('rep')] ?? 'Unassigned')->sortKeys()
            ->map(fn ($group, string $rep) => [$rep, $group->count(), $this->money($group->sum(fn (Record $commission) => $this->number($commission, 'sale_value'))), $this->money($group->sum('amount')), $this->money($group->where('status', 'paid')->sum('amount'))])
            ->values()->all()]];
    }
}
