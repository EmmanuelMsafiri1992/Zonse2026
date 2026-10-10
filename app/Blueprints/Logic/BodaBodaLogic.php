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
 * Motorbike / boda-boda riders: an active rider must have a helmet issued. Each rider remits once a day: the
 * remittance is marked paid when it meets the rider's daily target and short when it doesn't, with the
 * shortfall kept. Each night a missed remittance is logged for every active rider who paid nothing the day
 * before. Delivery jobs only go to active riders. Each rider shows what they remitted and owe this month.
 */
class BodaBodaLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'riders') {
            if ($payload['status'] === 'active' && ! filter_var($data['helmet_issued'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $errors['data.helmet_issued'] = 'Issue a helmet before the rider starts.';
            }

            return $errors;
        }
        $rider = filled($data['rider'] ?? null) ? $this->records('riders')->find($data['rider']) : null;
        $riderChanged = ! $existing || (string) $existing->value('rider') !== (string) ($data['rider'] ?? '');
        if ($entity->key === 'jobs' && $rider && $riderChanged && $rider->status !== 'active') {
            $errors['data.rider'] = $rider->title.' is '.$rider->status.'.';
        }
        if ($entity->key === 'jobs' && in_array($payload['status'], ['assigned', 'picked_up'], true) && ! $rider) {
            $errors['data.rider'] = 'Give the rider.';
        }
        if ($entity->key === 'remittances' && $rider && ! $existing) {
            $date = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on'])->toDateString() : today()->toDateString();
            if ($this->linked('remittances', 'rider', $rider)->whereDate('occurs_on', $date)->exists()) {
                $errors['data.rider'] = $rider->title.' has already remitted for '.Carbon::parse($date)->format('d M Y').'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity !== 'remittances' || ! ($rider = $this->parent($record, 'rider'))) {
            return;
        }
        $record->title = $rider->title;
        $target = $this->number($rider, 'daily_target');
        $paid = (float) $record->amount;
        $record->status = match (true) {
            $paid <= 0 => 'missed',
            $target > 0 && $paid < $target => 'short',
            default => 'paid',
        };
        $this->put($record, ['_shortfall' => max(0, round($target - $paid, 2))]);
    }

    public function daily(Workspace $workspace): int
    {
        $yesterday = today()->subDay();
        $remitted = $this->records('remittances')->whereDate('occurs_on', $yesterday->toDateString())->get()->map(fn (Record $remittance) => (int) $remittance->value('rider'))->all();

        return $this->records('riders')->where('status', 'active')->whereDate('created_at', '<', today()->toDateString())->get()
            ->reject(fn (Record $rider) => in_array($rider->id, $remitted, true))
            ->each(fn (Record $rider) => Record::create([
                'workspace_id' => $workspace->id, 'blueprint' => $rider->blueprint, 'entity' => 'remittances', 'title' => $rider->title,
                'status' => 'missed', 'amount' => 0, 'occurs_on' => $yesterday, 'data' => ['rider' => $rider->id],
            ]))
            ->count();
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'riders') {
            return $record->status === 'active' ? ['remit' => ['label' => 'Record remittance', 'icon' => 'coins', 'fields' => [
                ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => $record->value('daily_target')],
                ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => ['cash' => 'Cash', 'mobile_money' => 'Mobile money'], 'value' => 'cash'],
            ]]] : [];
        }
        if ($record->entity !== 'jobs') {
            return [];
        }

        return match ($record->status) {
            'requested' => ['assign' => ['label' => 'Assign rider', 'icon' => 'bike', 'fields' => [['name' => 'rider', 'label' => 'Rider', 'type' => 'select', 'options' => $this->records('riders')->where('status', 'active')->orderBy('title')->pluck('title', 'id')->all()]]], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'assigned' => ['pick_up' => ['label' => 'Picked up', 'icon' => 'package'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'picked_up' => ['deliver' => ['label' => 'Delivered', 'icon' => 'check']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'remit':
                $input = $request->validate(['amount' => ['required', 'numeric', 'min:0'], 'method' => ['nullable', 'in:cash,mobile_money']]);
                if ($this->linked('remittances', 'rider', $record)->whereDate('occurs_on', today()->toDateString())->exists()) {
                    throw ValidationException::withMessages(['amount' => $record->title.' has already remitted today.']);
                }
                $remittance = Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'remittances', 'title' => $record->title,
                    'status' => 'paid', 'amount' => $input['amount'], 'occurs_on' => today(), 'data' => ['rider' => $record->id, 'method' => $input['method'] ?? 'cash'],
                ]);

                return $remittance->status === 'short'
                    ? $record->title.' remitted '.$this->money($remittance->amount).', '.$this->money($remittance->value('_shortfall')).' short.'
                    : $record->title.' remitted '.$this->money($remittance->amount).'.';
            case 'assign':
                $rider = $this->records('riders')->where('status', 'active')->find($request->validate(['rider' => ['required']])['rider']);
                if (! $rider) {
                    throw ValidationException::withMessages(['rider' => 'Choose an active rider.']);
                }
                $record->update(['status' => 'assigned', 'data' => [...$record->data, 'rider' => $rider->id]]);

                return $record->title.' assigned to '.$rider->title.'.';
            case 'pick_up':
                $record->update(['status' => 'picked_up']);

                return $record->title.' picked up.';
            case 'deliver':
                $record->update(['status' => 'delivered']);

                return $record->title.' delivered to '.$record->value('dropoff').'.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'riders') {
            return [];
        }
        $month = $this->linked('remittances', 'rider', $record)->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'coins', 'stats' => [
            ['label' => 'Remitted', 'value' => $this->money($month->sum('amount'))],
            ['label' => 'Shortfall', 'value' => $this->money($month->sum(fn (Record $remittance) => $this->number($remittance, '_shortfall')))],
            ['label' => 'Days missed', 'value' => $month->where('status', 'missed')->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $paidToday = $this->records('remittances')->whereDate('occurs_on', today()->toDateString())->get()->map(fn (Record $remittance) => (int) $remittance->value('rider'))->all();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Still to remit today', 'icon' => 'coins', 'empty' => 'Every rider has remitted today.',
                'rows' => $this->records('riders')->where('status', 'active')->orderBy('title')->get()->reject(fn (Record $rider) => in_array($rider->id, $paidToday, true))
                    ->map(fn (Record $rider) => ['label' => $rider->title, 'sub' => (string) $rider->value('bike'), 'value' => $this->money($rider->value('daily_target')), 'href' => $rider->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Deliveries', 'icon' => 'bike', 'stats' => [
                ['label' => 'Waiting for a rider', 'value' => $this->records('jobs')->where('status', 'requested')->count()],
                ['label' => 'On the road', 'value' => $this->records('jobs')->whereIn('status', ['assigned', 'picked_up'])->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Remittances by rider', 'columns' => ['Rider', 'Days paid', 'Days short', 'Days missed', 'Remitted', 'Shortfall'], 'rows' => $this->dated('remittances', $from, $to)->get()
            ->groupBy('title')->sortKeys()
            ->map(fn ($group, string $rider) => [$rider, $group->where('status', 'paid')->count(), $group->where('status', 'short')->count(), $group->where('status', 'missed')->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $remittance) => $this->number($remittance, '_shortfall')))])
            ->values()->all()]];
    }
}
