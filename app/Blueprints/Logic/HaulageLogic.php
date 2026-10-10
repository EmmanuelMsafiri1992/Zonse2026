<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Haulage & trucking: the delivery date can't be before loading. A load needs a truck and a driver once it is
 * loading, and a truck can't carry two loads at once. A load is only delivered with proof of delivery, and only
 * delivered loads can be invoiced. Trip expenses are claimed against a load, approved and then paid, and each
 * load shows its margin after expenses.
 */
class HaulageLogic extends AppLogic
{
    /**
     * Load statuses where the truck is committed.
     *
     * @var list<string>
     */
    public const ON_ROAD = ['loading', 'in_transit'];

    /**
     * The next status for a load and its action label.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const NEXT = ['quoted' => ['booked', 'Confirm booking'], 'booked' => ['loading', 'Start loading'], 'loading' => ['in_transit', 'Depart'], 'in_transit' => ['delivered', 'Delivered'], 'delivered' => ['invoiced', 'Invoiced']];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'trip_expenses') {
            if (! $existing && filled($data['load'] ?? null) && ($load = $this->records('loads')->find($data['load'])) && $load->status === 'invoiced') {
                $errors['data.load'] = $load->title.' has been invoiced.';
            }

            return $errors;
        }

        return [...$errors, ...$this->loadProblems($payload['status'], $data, $payload['occurs_on'] ?? null, $payload['due_on'] ?? null, $existing)];
    }

    /**
     * What stops a load from having the given status, keyed by field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function loadProblems(string $status, array $data, mixed $loading, mixed $delivery, ?Record $existing): array
    {
        $errors = [];
        if (filled($loading) && filled($delivery) && Carbon::parse($delivery)->lt(Carbon::parse($loading))) {
            $errors['due_on'] = 'The delivery date is before loading.';
        }
        if (in_array($status, [...self::ON_ROAD, 'delivered', 'invoiced'], true)) {
            if (blank($data['truck'] ?? null)) {
                $errors['data.truck'] = 'Give the truck.';
            }
            if (blank($data['driver'] ?? null)) {
                $errors['data.driver'] = 'Give the driver.';
            }
        }
        if (in_array($status, self::ON_ROAD, true) && filled($truck = strtoupper(trim((string) ($data['truck'] ?? ''))))
            && ($busy = $this->records('loads')->whereIn('status', self::ON_ROAD)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $load) => strtoupper(trim((string) $load->value('truck'))) === $truck))) {
            $errors['data.truck'] = 'This truck is already carrying '.$busy->title.'.';
        }
        if (in_array($status, ['delivered', 'invoiced'], true) && blank($data['pod_url'] ?? null)) {
            $errors['data.pod_url'] = 'Attach the proof of delivery.';
        }
        if ($status === 'invoiced' && (! $existing || ! in_array($existing->status, ['delivered', 'invoiced'], true))) {
            $errors['status'] = 'Only delivered loads can be invoiced.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'trip_expenses') {
            return match ($record->status) {
                'claimed' => ['approve' => ['label' => 'Approve', 'icon' => 'check']],
                'approved' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote']],
                default => [],
            };
        }
        if (! isset(self::NEXT[$record->status])) {
            return [];
        }
        [, $label] = self::NEXT[$record->status];

        return ['advance' => ['label' => $label, 'icon' => 'arrow-right', 'fields' => $record->status === 'in_transit'
            ? [['name' => 'pod_url', 'label' => 'Proof of delivery link', 'type' => 'url', 'value' => $record->value('pod_url')]]
            : []]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'approve':
                $record->update(['status' => 'approved']);

                return $record->title.' approved.';
            case 'pay':
                $record->update(['status' => 'paid']);

                return $record->title.' paid.';
            default:
                [$next] = self::NEXT[$record->status];
                $data = [...$record->data, ...array_filter(['pod_url' => $request->input('pod_url')])];
                if ($errors = $this->loadProblems($next, $data, null, null, $record)) {
                    throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $key) => [str_replace('data.', '', $key) => $message])->all());
                }
                $record->update(['status' => $next, 'data' => $data, ...($next === 'delivered' ? ['due_on' => $record->due_on ?? today()] : [])]);

                return $record->title.' is '.str_replace('_', ' ', $next).($next === 'delivered' && $record->due_on && $record->due_on->lt(today()) ? ', '.($late = (int) $record->due_on->diffInDays(today())).' '.str('day')->plural($late).' late' : '').'.';
        }
    }

    /**
     * The approved and paid expenses on a load.
     */
    protected function expenses(Record $load): float
    {
        return (float) $this->linked('trip_expenses', 'load', $load)->whereIn('status', ['approved', 'paid'])->sum('amount');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'loads') {
            return [];
        }
        $expenses = $this->expenses($record);

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Trip margin', 'icon' => 'receipt', 'stats' => [
            ['label' => 'Rate', 'value' => $this->money($record->amount)],
            ['label' => 'Expenses', 'value' => $this->money($expenses)],
            ['label' => 'Margin', 'value' => $this->money((float) $record->amount - $expenses)],
            ['label' => 'Claims waiting', 'value' => $this->linked('trip_expenses', 'load', $record)->where('status', 'claimed')->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'On the road', 'icon' => 'truck', 'empty' => 'No loads on the road.',
                'rows' => $this->records('loads')->whereIn('status', self::ON_ROAD)->orderBy('due_on')->get()
                    ->map(fn (Record $load) => ['label' => $load->title, 'sub' => $load->value('origin').' → '.$load->value('destination').' · '.$load->value('truck'), 'value' => $load->due_on ? 'Due '.$load->due_on->format('d M') : str_replace('_', ' ', $load->status), 'href' => $load->url(), 'tone' => $load->due_on && $load->due_on->lt(today()) ? 'danger' : null])->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Billing', 'icon' => 'receipt', 'stats' => [
                ['label' => 'Delivered, not invoiced', 'value' => $this->records('loads')->where('status', 'delivered')->count()],
                ['label' => 'Expense claims waiting', 'value' => $this->records('trip_expenses')->where('status', 'claimed')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $loads = $this->dated('loads', $from, $to)->whereIn('status', ['delivered', 'invoiced'])->get();

        return [['title' => 'Lanes', 'columns' => ['Lane', 'Loads', 'Tonnes', 'Revenue', 'Expenses', 'Margin'], 'rows' => $loads
            ->groupBy(fn (Record $load) => trim((string) $load->value('origin')).' → '.trim((string) $load->value('destination')))->sortKeys()
            ->map(function ($group, string $lane) {
                $expenses = $group->sum(fn (Record $load) => $this->expenses($load));

                return [$lane, $group->count(), round($group->sum(fn (Record $load) => $this->number($load, 'weight')), 1), $this->money($group->sum('amount')), $this->money($expenses), $this->money($group->sum('amount') - $expenses)];
            })->values()->all()]];
    }
}
