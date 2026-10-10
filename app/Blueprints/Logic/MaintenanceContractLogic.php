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
use Illuminate\Validation\ValidationException;

/**
 * Service contracts & AMC: a contract runs a year from its start unless a renewal date is given. It shows as
 * expiring in the 30 days before renewal and expires once that date passes; this is checked each night.
 * Renewing moves it on by a year. Visits only go on live contracts, and a completed visit needs its report.
 * Scheduling spreads the year's visits evenly over the term, and scheduled visits left past their date are
 * marked missed.
 */
class MaintenanceContractLogic extends AppLogic
{
    public const EXPIRING_DAYS = 30;

    /**
     * Contract statuses that still owe visits.
     *
     * @var list<string>
     */
    public const LIVE = ['active', 'expiring'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'contracts') {
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The renewal date must be after the start.';
            }
            if ((int) ($data['visits_per_year'] ?? 0) < 0) {
                $errors['data.visits_per_year'] = 'Visits per year can\'t be negative.';
            }

            return $errors;
        }
        if (! $existing && filled($data['contract'] ?? null) && ($contract = $this->records('contracts')->find($data['contract'])) && ! in_array($contract->status, self::LIVE, true)) {
            $errors['data.contract'] = $contract->title.' is '.$contract->status.'.';
        }
        if ($payload['status'] === 'completed' && blank($data['report'] ?? null)) {
            $errors['data.report'] = 'Write what was done on the visit.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'visits') {
            if ($record->status === 'scheduled' && $record->occurs_on->lt(today())) {
                $record->status = 'missed';
            }

            return;
        }
        $record->due_on ??= $record->occurs_on->copy()->addYear();
        if ($record->status === 'cancelled') {
            return;
        }
        $record->status = match (true) {
            $record->due_on->lt(today()) => 'expired',
            $record->due_on->lte(today()->addDays(self::EXPIRING_DAYS)) => 'expiring',
            default => 'active',
        };
    }

    public function daily(Workspace $workspace): int
    {
        $contracts = $this->records('contracts')->whereIn('status', self::LIVE)->get()->filter(function (Record $contract) {
            $before = $contract->status;
            $contract->save();

            return $contract->status !== $before;
        });
        $visits = $this->records('visits')->where('status', 'scheduled')->whereDate('occurs_on', '<', today()->toDateString())->get()->each(fn (Record $visit) => $visit->save());

        return $contracts->count() + $visits->count();
    }

    /**
     * The visits on a contract during its current term.
     *
     * @return Collection<int, Record>
     */
    protected function termVisits(Record $contract)
    {
        return $this->linked('visits', 'contract', $contract)->whereDate('occurs_on', '>=', $contract->occurs_on->toDateString())->whereDate('occurs_on', '<=', $contract->due_on->toDateString())->orderBy('occurs_on')->get();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'visits') {
            return $record->status === 'scheduled' ? [
                'complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [['name' => 'report', 'label' => 'What was done', 'type' => 'textarea', 'value' => $record->value('report')]]],
                'missed' => ['label' => 'Missed', 'icon' => 'x'],
            ] : [];
        }

        return match ($record->status) {
            'active', 'expiring' => [
                'schedule' => ['label' => 'Schedule the year\'s visits', 'icon' => 'calendar-plus'],
                'renew' => ['label' => 'Renew', 'icon' => 'refresh-cw'],
                'cancel' => ['label' => 'Cancel', 'icon' => 'ban'],
            ],
            'expired' => ['renew' => ['label' => 'Renew', 'icon' => 'refresh-cw']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'complete':
                $report = trim((string) ($request->validate(['report' => ['nullable', 'string']])['report'] ?? '')) ?: (string) $record->value('report');
                if ($report === '') {
                    throw ValidationException::withMessages(['report' => 'Write what was done on the visit.']);
                }
                $record->update(['status' => 'completed', 'data' => [...$record->data, 'report' => $report]]);

                return $record->title.' completed.';
            case 'missed':
                $record->update(['status' => 'missed']);

                return $record->title.' missed.';
            case 'schedule':
                return $this->schedule($record);
            case 'renew':
                $from = $record->due_on->gte(today()) ? $record->due_on->copy()->addDay() : today();
                $record->update(['status' => 'active', 'occurs_on' => $from, 'due_on' => $from->copy()->addYear()]);

                return $record->title.' renewed until '.$record->due_on->format('d M Y').'.';
            default:
                $record->update(['status' => 'cancelled']);
                $open = $this->linked('visits', 'contract', $record)->where('status', 'scheduled')->get()->each(fn (Record $visit) => $visit->delete())->count();

                return $record->title.' cancelled'.($open ? '; '.$open.' scheduled '.str('visit')->plural($open).' removed' : '').'.';
        }
    }

    /**
     * Spread the term's visits evenly from today (or the start, if later) to the renewal date, skipping
     * slots already covered by a visit.
     */
    protected function schedule(Record $contract): string
    {
        $perYear = (int) $contract->value('visits_per_year');
        if ($perYear <= 0) {
            throw ValidationException::withMessages(['visits_per_year' => 'Set the visits per year first.']);
        }
        $existing = $this->termVisits($contract)->whereIn('status', ['scheduled', 'completed']);
        $needed = $perYear - $existing->count();
        if ($needed <= 0) {
            return 'All '.$perYear.' visits for '.$contract->title.' are already booked.';
        }
        $from = $contract->occurs_on->gt(today()) ? $contract->occurs_on->copy() : today();
        $gap = max(1, intdiv((int) $from->diffInDays($contract->due_on), $needed));
        for ($i = 0; $i < $needed; $i++) {
            Record::create([
                'workspace_id' => $contract->workspace_id, 'blueprint' => $contract->blueprint, 'entity' => 'visits',
                'title' => 'Service visit '.($existing->count() + $i + 1).' of '.$perYear, 'status' => 'scheduled',
                'occurs_on' => $from->copy()->addDays($gap * $i + intdiv($gap, 2)), 'data' => ['contract' => $contract->id],
            ]);
        }

        return 'Scheduled '.$needed.' '.str('visit')->plural($needed).' for '.$contract->title.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'contracts') {
            return [];
        }
        $visits = $this->termVisits($record);
        $technicians = User::query()->whereIn('id', $visits->map(fn (Record $visit) => $visit->value('technician'))->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Visits this term: '.$visits->where('status', 'completed')->count().' of '.(int) $record->value('visits_per_year').' done', 'icon' => 'wrench', 'empty' => 'No visits booked this term.',
            'rows' => $visits->map(fn (Record $visit) => ['label' => $visit->occurs_on->format('d M Y'), 'sub' => $technicians[$visit->value('technician')] ?? 'No technician', 'value' => ucfirst($visit->status), 'href' => $visit->url(), 'tone' => match ($visit->status) {
                'missed' => 'danger', 'completed' => 'success', default => null,
            }])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $contracts = $this->records('contracts')->get()->keyBy('id');

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Up for renewal', 'icon' => 'refresh-cw', 'empty' => 'No contracts up for renewal.',
                'rows' => $contracts->where('status', 'expiring')->sortBy('due_on')->map(fn (Record $contract) => ['label' => $contract->title, 'sub' => $this->money($contract->amount).' a year', 'value' => $contract->due_on->format('d M Y'), 'href' => $contract->url(), 'tone' => 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Visits in the next 7 days', 'icon' => 'calendar', 'empty' => 'No visits this week.',
                'rows' => $this->records('visits')->where('status', 'scheduled')->whereDate('occurs_on', '<=', today()->addDays(7)->toDateString())->orderBy('occurs_on')->get()
                    ->map(fn (Record $visit) => ['label' => $contracts->get((int) $visit->value('contract'))?->title ?? $visit->title, 'sub' => $visit->title, 'value' => $visit->occurs_on->format('d M'), 'href' => $visit->url()])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->get()->groupBy(fn (Record $visit) => (int) $visit->value('contract'));

        return [['title' => 'Visits by contract', 'columns' => ['Contract', 'Status', 'Visits a year', 'Completed', 'Missed', 'Annual fee'], 'rows' => $this->records('contracts')->orderBy('title')->get()
            ->map(fn (Record $contract) => [$contract->title, ucfirst($contract->status), (int) $contract->value('visits_per_year'), ($visits->get($contract->id) ?? collect())->where('status', 'completed')->count(), ($visits->get($contract->id) ?? collect())->where('status', 'missed')->count(), $this->money($contract->amount)])
            ->all()]];
    }
}
