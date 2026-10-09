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
 * Traffic fines & vehicle licensing: registrations are unique and stored in capitals, a vehicle's
 * licence expires by itself after its expiry date, and a renewal extends it a year from the later
 * of today and the old expiry. A vehicle with unpaid warrants cannot be renewed. Fines are paid by
 * their pay-by date, turn into warrants once overdue, and a contested fine is upheld or cancelled.
 */
class TrafficLogic extends AppLogic
{
    public const UNPAID = ['issued', 'contested', 'warrant'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'vehicles') {
            $plate = $this->plate((string) ($payload['title'] ?? ''));
            if ($plate !== '' && $this->records('vehicles')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $vehicle) => $this->plate($vehicle->title) === $plate)) {
                $errors['title'] = $plate.' is already registered.';
            }
            if ((float) ($payload['amount'] ?? 0) < 0) {
                $errors['amount'] = 'The licence fee cannot be negative.';
            }

            return $errors;
        }

        $vehicle = ! empty($data['vehicle']) ? $this->records('vehicles')->find($data['vehicle']) : null;
        if ($vehicle && $vehicle->status === 'deregistered' && (! $existing || (int) $existing->value('vehicle') !== $vehicle->id)) {
            $errors['data.vehicle'] = $vehicle->title.' is deregistered.';
        }
        if ((float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Enter the fine amount.';
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'A fine cannot be issued in the future.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'fines') {
            $record->occurs_on ??= today();
            $record->due_on ??= $record->occurs_on->copy()->addDays(30);
            $this->put($record, [
                '_paid_on' => $record->status === 'paid' ? ($record->value('_paid_on') ?? today()->toDateString()) : null,
                '_overdue' => in_array($record->status, ['issued', 'warrant'], true) && $record->due_on->lt(today()),
            ]);

            return;
        }

        $record->title = $this->plate($record->title);
        $expiry = filled($record->value('licence_expiry')) ? Carbon::parse($record->value('licence_expiry')) : null;
        if ($record->status === 'licensed' && $expiry?->lt(today())) {
            $record->status = 'expired';
        } elseif ($record->status === 'expired' && $expiry?->gte(today())) {
            $record->status = 'licensed';
        }
        $fines = $record->exists ? $this->linked('fines', 'vehicle', $record)->get() : collect();
        $unpaid = $fines->whereIn('status', self::UNPAID);
        $this->put($record, [
            '_days_to_expiry' => $expiry ? (int) today()->diffInDays($expiry, false) : null,
            '_fines' => $fines->count(),
            '_unpaid_fines' => $unpaid->count(),
            '_unpaid_amount' => round($unpaid->sum('amount'), 2),
            '_warrants' => $fines->where('status', 'warrant')->count(),
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'fines') {
            $this->recalculate($this->parent($record, 'vehicle'));
            $this->recalculate($this->previousParent($record, 'vehicle'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'fines') {
            $this->recalculate($this->parent($record, 'vehicle'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('vehicles')->where('status', 'licensed')->get() as $vehicle) {
            if (filled($vehicle->value('licence_expiry')) && Carbon::parse($vehicle->value('licence_expiry'))->lt(today())) {
                $vehicle->update(['status' => 'expired']);
                $changed++;
            }
        }
        foreach ($this->records('fines')->where('status', 'issued')->whereDate('due_on', '<', today())->get() as $fine) {
            $fine->update(['status' => 'warrant']);
            $changed++;
        }

        return $changed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'fines') {
            return match ($record->status) {
                'issued', 'warrant' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote'], 'contest' => ['label' => 'Contest', 'icon' => 'scale', 'fields' => [['name' => 'reason', 'label' => 'Grounds', 'type' => 'textarea']]]],
                'contested' => ['uphold' => ['label' => 'Uphold', 'icon' => 'gavel', 'fields' => [['name' => 'due_on', 'label' => 'Pay by', 'type' => 'date', 'value' => today()->addDays(14)->toDateString()]]], 'cancel' => ['label' => 'Cancel fine', 'icon' => 'x']],
                default => [],
            };
        }

        return match ($record->status) {
            'licensed', 'expired' => ['renew' => ['label' => 'Renew licence', 'icon' => 'refresh-cw', 'fields' => [['name' => 'amount', 'label' => 'Fee', 'type' => 'number', 'value' => $record->amount]]], 'deregister' => ['label' => 'Deregister', 'icon' => 'ban', 'confirm' => 'Deregister '.$record->title.'?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'pay':
                $record->update(['status' => 'paid']);

                return 'Fine '.$record->title.' paid.';
            case 'contest':
                $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
                $record->update(['status' => 'contested', 'data' => [...$record->data, '_grounds' => $reason]]);

                return 'Fine '.$record->title.' contested.';
            case 'uphold':
                $due = Carbon::parse($request->validate(['due_on' => ['required', 'date', 'after:today']])['due_on']);
                $record->update(['status' => 'issued', 'due_on' => $due]);

                return 'Fine '.$record->title.' upheld; pay by '.$due->format('d M Y').'.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return 'Fine '.$record->title.' cancelled.';
            case 'renew':
                $fee = $request->validate(['amount' => ['required', 'numeric', 'min:0']])['amount'];
                if ((int) $record->value('_warrants') > 0) {
                    throw ValidationException::withMessages(['status' => $record->title.' has '.(int) $record->value('_warrants').' unpaid warrants.']);
                }
                $current = filled($record->value('licence_expiry')) ? Carbon::parse($record->value('licence_expiry')) : today();
                $expiry = ($current->gt(today()) ? $current : today())->copy()->addYear();
                $record->update(['status' => 'licensed', 'amount' => $fee, 'data' => [...$record->data, 'licence_expiry' => $expiry->toDateString(), '_renewed_on' => today()->toDateString()]]);

                return $record->title.' licensed until '.$expiry->format('d M Y').'.';
        }

        $record->update(['status' => 'deregistered']);

        return $record->title.' deregistered.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'vehicles') {
            return [];
        }

        $fines = $this->linked('fines', 'vehicle', $record)->orderByDesc('occurs_on')->get();
        $days = $record->value('_days_to_expiry');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Vehicle', 'icon' => 'car', 'stats' => [
                ['label' => 'Owner', 'value' => (string) ($record->value('owner') ?: '—')],
                ['label' => 'Make & model', 'value' => (string) ($record->value('make_model') ?: '—')],
                ['label' => 'Licence expiry', 'value' => filled($record->value('licence_expiry')) ? Carbon::parse($record->value('licence_expiry'))->format('d M Y') : '—', 'tone' => $days !== null && (int) $days < 0 ? 'danger' : ($days !== null && (int) $days <= 30 ? 'warning' : null)],
                ['label' => 'Unpaid fines', 'value' => (int) $record->value('_unpaid_fines').' · '.$this->money((float) $record->value('_unpaid_amount')), 'tone' => (int) $record->value('_unpaid_fines') > 0 ? 'warning' : null],
                ['label' => 'Warrants', 'value' => (string) (int) $record->value('_warrants'), 'tone' => (int) $record->value('_warrants') > 0 ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Fines', 'icon' => 'receipt', 'empty' => 'No fines.',
                'rows' => $fines->take(15)->map(fn (Record $fine) => [
                    'label' => $fine->title.' · '.ucfirst(str_replace('_', ' ', (string) $fine->value('offence'))), 'sub' => $fine->occurs_on?->format('d M Y').($fine->value('location') ? ' · '.$fine->value('location') : ''), 'value' => $this->money($fine->amount).' · '.ucfirst($fine->status), 'href' => $fine->url(), 'tone' => $fine->status === 'paid' ? 'success' : ($fine->status === 'warrant' ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $vehicles = $this->records('vehicles')->get();
        $fines = $this->records('fines')->get();
        $expiring = $vehicles->filter(fn (Record $vehicle) => $vehicle->status === 'licensed' && $vehicle->value('_days_to_expiry') !== null && (int) $vehicle->value('_days_to_expiry') <= 30)->sortBy(fn (Record $vehicle) => (int) $vehicle->value('_days_to_expiry'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Licensing & fines', 'icon' => 'car', 'stats' => [
                ['label' => 'Licensed', 'value' => (string) $vehicles->where('status', 'licensed')->count()],
                ['label' => 'Expired', 'value' => (string) $vehicles->where('status', 'expired')->count(), 'tone' => $vehicles->where('status', 'expired')->isNotEmpty() ? 'warning' : null],
                ['label' => 'Expiring in 30 days', 'value' => (string) $expiring->count()],
                ['label' => 'Fines issued this month', 'value' => (string) $fines->filter(fn (Record $fine) => $fine->occurs_on?->isCurrentMonth())->count()],
                ['label' => 'Fines collected this month', 'value' => $this->money($fines->filter(fn (Record $fine) => filled($fine->value('_paid_on')) && Carbon::parse($fine->value('_paid_on'))->isCurrentMonth())->sum('amount'))],
                ['label' => 'Warrants', 'value' => (string) $fines->where('status', 'warrant')->count(), 'tone' => $fines->where('status', 'warrant')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Licences expiring', 'icon' => 'calendar-clock', 'empty' => 'No licence expires in the next 30 days.',
                'rows' => $expiring->take(10)->map(fn (Record $vehicle) => [
                    'label' => $vehicle->title, 'sub' => (string) $vehicle->value('owner'), 'value' => Carbon::parse($vehicle->value('licence_expiry'))->format('d M Y'), 'href' => $vehicle->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $fines = $this->dated('fines', $from, $to)->get();
        $offences = $this->app->entities['fines']->field('offence')?->options ?? [];
        $byOffence = collect($offences)->map(fn (string $label, string $offence) => [
            $label, $fines->where('data.offence', $offence)->count(), $this->money($fines->where('data.offence', $offence)->sum('amount')), $this->money($fines->where('data.offence', $offence)->where('status', 'paid')->sum('amount')), $fines->where('data.offence', $offence)->where('status', 'contested')->count(),
        ])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($fines) {
            $group = $fines->filter(fn (Record $fine) => $fine->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $this->money($group->sum('amount')), $this->money($group->where('status', 'paid')->sum('amount')), $group->where('status', 'warrant')->count()];
        })->values()->all();

        $byLocation = $fines->groupBy(fn (Record $fine) => $fine->value('location') ?: 'Unknown')->sortKeys()->map(fn ($group, $location) => [$location, $group->count(), $this->money($group->sum('amount'))])->values()->all();

        return [
            ['title' => 'Fines by offence', 'columns' => ['Offence', 'Fines', 'Value', 'Collected', 'Contested'], 'rows' => $byOffence],
            ['title' => 'Fines by month', 'columns' => ['Month', 'Issued', 'Value', 'Collected', 'Warrants'], 'rows' => $byMonth],
            ['title' => 'Fines by location', 'columns' => ['Location', 'Fines', 'Value'], 'rows' => $byLocation],
        ];
    }

    private function plate(string $registration): string
    {
        return strtoupper(preg_replace('/\s+/', ' ', trim($registration)));
    }
}
