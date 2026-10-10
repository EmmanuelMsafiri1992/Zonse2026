<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Staffing / outsourcing agency: a worker holds one active placement at a time, and a placement's bill
 * rate must cover the worker's pay rate. Placing a worker marks them placed, and ending their last
 * placement makes them available again. A weekly timesheet (one per placement per week) bills the
 * client hours × bill rate and pays the worker hours × pay rate, and keeps the margin between the two.
 */
class StaffingLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'workers' && (float) ($data['pay_rate'] ?? 0) < 0) {
            $errors['data.pay_rate'] = 'The pay rate cannot be negative.';
        }
        if ($entity->key === 'placements') {
            $worker = filled($data['worker'] ?? null) ? $this->records('workers')->find($data['worker']) : null;
            if (! $worker) {
                return $errors;
            }
            if ($worker->status === 'inactive' && $payload['status'] === 'active') {
                $errors['data.worker'] = $worker->title.' is inactive.';
            }
            if ((float) ($data['bill_rate'] ?? 0) <= $this->number($worker, 'pay_rate')) {
                $errors['data.bill_rate'] = 'The bill rate must be more than '.$worker->title.'\'s pay rate of '.$this->money($this->number($worker, 'pay_rate')).'.';
            }
            $other = $payload['status'] === 'active' ? $this->linked('placements', 'worker', $worker)->where('status', 'active')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first() : null;
            if ($other) {
                $errors['data.worker'] = $worker->title.' is already placed'.($other->contact ? ' at '.$other->contact->name : ' as '.$other->title).'.';
            }
        }
        if ($entity->key === 'timesheets') {
            if ((float) ($data['hours'] ?? 0) <= 0 || (float) ($data['hours'] ?? 0) > 168) {
                $errors['data.hours'] = 'Give between 0 and 168 hours.';
            }
            $week = Carbon::parse($payload['occurs_on'] ?? today())->startOfWeek();
            if (filled($data['placement'] ?? null) && $this->linked('timesheets', 'placement', (int) $data['placement'])->whereDate('occurs_on', $week->toDateString())->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.placement'] = 'This placement already has a timesheet for the week of '.$week->format('d M Y').'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'placements') {
            $record->occurs_on ??= today();

            return;
        }
        if ($record->entity !== 'timesheets') {
            return;
        }
        $record->occurs_on = ($record->occurs_on ?? today())->copy()->startOfWeek();
        $placement = $this->parent($record, 'placement');
        $worker = $placement ? $this->parent($placement, 'worker') : null;
        $hours = $this->number($record, 'hours');
        if ($placement) {
            $record->amount = round($hours * $this->number($placement, 'bill_rate'), 2);
        }
        if ($worker) {
            $this->put($record, ['payout' => round($hours * $this->number($worker, 'pay_rate'), 2)]);
        }
        $this->put($record, ['_margin' => round((float) $record->amount - $this->number($record, 'payout'), 2)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'placements' || ! ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            return;
        }
        $worker = $this->parent($record, 'worker');
        if (! $worker || $worker->status === 'inactive') {
            return;
        }
        $placed = $this->linked('placements', 'worker', $worker)->where('status', 'active')->exists();
        if ($worker->status !== ($placed ? 'placed' : 'available')) {
            $worker->update(['status' => $placed ? 'placed' : 'available']);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'placements' && $record->status === 'active' => ['end' => ['label' => 'End placement', 'icon' => 'square']],
            $record->entity === 'timesheets' && $record->status === 'submitted' => ['bill' => ['label' => 'Billed to client', 'icon' => 'receipt']],
            $record->entity === 'timesheets' && $record->status === 'billed' => ['pay' => ['label' => 'Worker paid', 'icon' => 'banknote']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'end') {
            $record->update(['status' => 'ended', 'due_on' => $record->due_on && $record->due_on->lt(today()) ? $record->due_on : today()]);

            return $this->parent($record, 'worker')?->title.'\'s placement ended. They are available again.';
        }
        $worker = ($placement = $this->parent($record, 'placement')) ? $this->parent($placement, 'worker') : null;
        if ($action === 'bill') {
            $record->update(['status' => 'billed']);

            return 'Billed '.$this->money($record->amount).' for '.$this->number($record, 'hours').' hours.';
        }
        $record->update(['status' => 'paid']);

        return 'Paid '.($worker?->title ?? 'the worker').' '.$this->money($this->number($record, 'payout')).'.';
    }

    /**
     * Hours, billing, payouts and margin for a set of timesheets.
     *
     * @param  Collection<int, Record>  $timesheets
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    protected function totals(Collection $timesheets): array
    {
        return [
            round($timesheets->sum(fn (Record $timesheet) => $this->number($timesheet, 'hours')), 2),
            round($timesheets->sum(fn (Record $timesheet) => (float) $timesheet->amount), 2),
            round($timesheets->sum(fn (Record $timesheet) => $this->number($timesheet, 'payout')), 2),
            round($timesheets->sum(fn (Record $timesheet) => $this->number($timesheet, '_margin')), 2),
        ];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'placements') {
            return [];
        }
        [$hours, $billed, $paid, $margin] = $this->totals($this->linked('timesheets', 'placement', $record)->get());

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Placement', 'icon' => 'briefcase', 'stats' => [
            ['label' => 'Hours', 'value' => $hours],
            ['label' => 'Billed', 'value' => $this->money($billed)],
            ['label' => 'Paid out', 'value' => $this->money($paid)],
            ['label' => 'Margin', 'value' => $this->money($margin).($billed > 0 ? ' ('.round($margin / $billed * 100).'%)' : ''), 'tone' => $margin > 0 ? 'success' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $workers = $this->records('workers')->get();
        [$hours, $billed, , $margin] = $this->totals($this->records('timesheets')->whereBetween('occurs_on', [today()->startOfMonth()->startOfWeek(), today()->endOfDay()])->get());
        $toBill = $this->records('timesheets')->where('status', 'submitted')->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'user-plus', 'stats' => [
            ['label' => 'Placed', 'value' => $workers->where('status', 'placed')->count().' of '.$workers->where('status', '!=', 'inactive')->count()],
            ['label' => 'Hours worked', 'value' => $hours],
            ['label' => 'Billed', 'value' => $this->money($billed)],
            ['label' => 'Margin', 'value' => $this->money($margin)],
            ['label' => 'Waiting to bill', 'value' => $this->money($toBill->sum(fn (Record $timesheet) => (float) $timesheet->amount)), 'tone' => $toBill->isNotEmpty() ? 'warning' : null],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $timesheets = $this->dated('timesheets', $from, $to)->get();
        $placements = $this->records('placements')->with('contact')->get()->keyBy('id');
        $workers = $this->records('workers')->pluck('title', 'id');

        return [['title' => 'Margin by placement', 'columns' => ['Placement', 'Client', 'Worker', 'Hours', 'Billed', 'Paid out', 'Margin'], 'rows' => $timesheets
            ->groupBy(fn (Record $timesheet) => (int) $timesheet->value('placement'))
            ->map(function (Collection $group, int $id) use ($placements, $workers) {
                $placement = $placements[$id] ?? null;

                return [$placement?->title ?? '—', $placement?->contact?->name ?? '—', $workers[$placement?->value('worker')] ?? '—',
                    ...collect($this->totals($group))->map(fn (float $value, int $index) => $index === 0 ? $value : $this->money($value))->all()];
            })->values()->all()]];
    }
}
