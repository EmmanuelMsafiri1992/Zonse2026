<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Equipment maintenance: serial numbers are unique, and retired equipment takes no new work. A
 * breakdown marks the equipment faulty until its last open breakdown is fixed. Finishing preventive
 * work sets the next service date from the service interval, and each morning a preventive work order
 * is raised for every service coming due within a week. Done and cancelled work orders stay closed.
 */
class MaintenanceLogic extends AppLogic
{
    /**
     * Days ahead that scheduled services are raised as work orders.
     */
    public const LEAD_DAYS = 7;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'equipment') {
            $serial = mb_strtoupper(trim((string) ($data['serial_number'] ?? '')));
            if ($serial !== '' && ($twin = $this->records('equipment')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $item) => mb_strtoupper(trim((string) $item->value('serial_number'))) === $serial))) {
                $errors['data.serial_number'] = 'Serial number '.$serial.' belongs to '.$twin->title.'.';
            }
            if (filled($data['service_interval_days'] ?? null) && (int) $data['service_interval_days'] < 1) {
                $errors['data.service_interval_days'] = 'The service interval is at least one day.';
            }

            return $errors;
        }

        if ($existing && in_array($existing->status, ['done', 'cancelled'], true) && $payload['status'] !== $existing->status) {
            $errors['status'] = 'This work order is '.$existing->status.'; raise a new one.';
        }
        $equipment = filled($data['equipment'] ?? null) ? $this->records('equipment')->find($data['equipment']) : null;
        if (! $existing && $equipment?->status === 'retired') {
            $errors['data.equipment'] = $equipment->title.' is retired.';
        }
        if ((float) ($data['downtime_hours'] ?? 0) < 0) {
            $errors['data.downtime_hours'] = 'Downtime cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'work_orders') {
            $record->occurs_on ??= today();
            if ($record->isDirty('status') && $record->status === 'done') {
                $this->put($record, ['_completed_on' => today()->toDateString()]);
            }
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'work_orders' || ! ($equipment = $this->parent($record, 'equipment')) || $equipment->status === 'retired') {
            return;
        }
        $orders = $this->linked('work_orders', 'equipment', $equipment)->get();
        $broken = $orders->contains(fn (Record $order) => $order->value('type') === 'breakdown' && in_array($order->status, ['open', 'in_progress'], true));
        $changes = [];
        if ($broken && $equipment->status !== 'faulty') {
            $changes['status'] = 'faulty';
        } elseif (! $broken && $equipment->status === 'faulty') {
            $changes['status'] = 'operational';
        }
        $statusChanged = $record->wasRecentlyCreated || $record->wasChanged('status');
        if ($statusChanged && $record->status === 'done' && $record->value('type') !== 'breakdown' && ($interval = (int) $equipment->value('service_interval_days')) > 0) {
            $changes['due_on'] = today()->addDays($interval);
            $changes['data'] = [...$equipment->data, '_last_service' => today()->toDateString()];
        }
        if ($changes) {
            $equipment->update($changes);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'work_orders') {
            return [];
        }
        $complete = ['complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [
            ['name' => 'downtime_hours', 'label' => 'Downtime (hours)', 'type' => 'number', 'value' => $record->value('downtime_hours') ?? 0],
            ['name' => 'cost', 'label' => 'Cost', 'type' => 'number', 'value' => $record->amount],
            ['name' => 'notes', 'label' => 'What was done', 'type' => 'text'],
        ]]];

        return match ($record->status) {
            'open' => ['start' => ['label' => 'Start work', 'icon' => 'play'], ...$complete, 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'in_progress' => $complete,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $record->update(['status' => 'in_progress']);

                return $record->title.' is in progress.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled.';
            default:
                $values = $request->validate(['downtime_hours' => ['nullable', 'numeric', 'min:0'], 'cost' => ['nullable', 'numeric', 'min:0'], 'notes' => ['nullable', 'string', 'max:500']]);
                $notes = trim((string) ($values['notes'] ?? ''));
                $record->update(['status' => 'done', 'amount' => $values['cost'] ?? $record->amount, 'data' => [...$record->data,
                    'downtime_hours' => (float) ($values['downtime_hours'] ?? 0),
                    'notes' => $notes !== '' ? trim(((string) $record->value('notes'))."\n".$notes) : $record->value('notes'),
                ]]);
                $equipment = $this->parent($record, 'equipment');

                return $record->title.' done.'.($equipment?->due_on && $record->value('type') !== 'breakdown' ? ' Next service for '.$equipment->title.' on '.$equipment->due_on->format('d M Y').'.' : '');
        }
    }

    public function daily(Workspace $workspace): int
    {
        $raised = 0;
        $horizon = today()->addDays(self::LEAD_DAYS)->toDateString();
        foreach ($this->records('equipment')->where('status', '!=', 'retired')->whereNotNull('due_on')->whereDate('due_on', '<=', $horizon)->get() as $equipment) {
            $planned = $this->linked('work_orders', 'equipment', $equipment)->whereIn('status', ['open', 'in_progress'])->get()->contains(fn (Record $order) => $order->value('type') === 'preventive');
            if ($planned) {
                continue;
            }
            Record::create([
                'workspace_id' => $workspace->id,
                'blueprint' => $equipment->blueprint,
                'entity' => 'work_orders',
                'title' => 'Scheduled service: '.$equipment->title,
                'status' => 'open',
                'occurs_on' => today(),
                'due_on' => $equipment->due_on,
                'data' => ['equipment' => $equipment->id, 'type' => 'preventive'],
            ]);
            $raised++;
        }

        return $raised;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'equipment') {
            return [];
        }
        $orders = $this->linked('work_orders', 'equipment', $record)->get();
        $done = $orders->where('status', 'done');
        $breakdowns = $done->filter(fn (Record $order) => $order->value('type') === 'breakdown');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Maintenance', 'icon' => 'wrench', 'stats' => [
            ['label' => 'Open work orders', 'value' => $orders->whereIn('status', ['open', 'in_progress'])->count()],
            ['label' => 'Breakdowns', 'value' => $breakdowns->count()],
            ['label' => 'Downtime', 'value' => number_format($done->sum(fn (Record $order) => $this->number($order, 'downtime_hours')), 1).' h'],
            ['label' => 'Maintenance cost', 'value' => $this->money($done->sum('amount'))],
            ['label' => 'Next service', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->due_on && $record->due_on->lt(today()) ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('work_orders')->whereIn('status', ['open', 'in_progress'])->orderByRaw('due_on is null')->orderBy('due_on')->limit(10)->get();
        $names = $this->records('equipment')->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Equipment', 'icon' => 'cog', 'stats' => [
                ['label' => 'Operational', 'value' => $this->records('equipment')->where('status', 'operational')->count()],
                ['label' => 'Faulty', 'value' => $faulty = $this->records('equipment')->where('status', 'faulty')->count(), 'tone' => $faulty > 0 ? 'danger' : null],
                ['label' => 'Service due this week', 'value' => $this->records('equipment')->where('status', '!=', 'retired')->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(self::LEAD_DAYS)->toDateString())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open work orders', 'icon' => 'clipboard-list', 'empty' => 'No open work orders.',
                'rows' => $open->map(fn (Record $order) => ['label' => $order->title, 'sub' => ($names[$order->value('equipment')] ?? '').' · '.$order->value('type'), 'value' => $order->due_on?->format('d M') ?? '—', 'href' => $order->url(),
                    'tone' => $order->value('type') === 'breakdown' || ($order->due_on && $order->due_on->lt(today())) ? 'danger' : null])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('work_orders', $from, $to)->where('status', 'done')->get()->groupBy(fn (Record $order) => (int) $order->value('equipment'));
        $equipment = $this->records('equipment')->orderBy('title')->get()->filter(fn (Record $item) => $orders->has($item->id));

        return [['title' => 'Maintenance by equipment', 'columns' => ['Equipment', 'Work orders', 'Preventive', 'Breakdowns', 'Downtime (hours)', 'Cost'], 'rows' => $equipment->map(function (Record $item) use ($orders) {
            $done = $orders->get($item->id);

            return [$item->title, $done->count(), $done->filter(fn (Record $order) => $order->value('type') === 'preventive')->count(), $done->filter(fn (Record $order) => $order->value('type') === 'breakdown')->count(),
                number_format($done->sum(fn (Record $order) => $this->number($order, 'downtime_hours')), 1), $this->money($done->sum('amount'))];
        })->values()->all()]];
    }
}
