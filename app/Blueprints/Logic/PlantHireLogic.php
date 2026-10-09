<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Plant & equipment hire: fleet numbers are unique and hour meters never go backwards. A machine that
 * is broken down, in for service or already out cannot be hired. Hires are valued from the rate and
 * the hours or days out, dispatching a hire takes the machine off the yard, and returning it adds
 * the hours used to the meter and sends it for service once the service hours are reached.
 */
class PlantHireLogic extends AppLogic
{
    public const OPEN_HIRES = ['booked', 'on_hire'];

    public const UNAVAILABLE = ['breakdown', 'service'];

    public const SERVICE_INTERVAL = 250;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'plant') {
            if (filled($data['fleet_number'] ?? null) && $this->records('plant')->where('data->fleet_number', $data['fleet_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.fleet_number'] = 'Fleet number '.$data['fleet_number'].' is already used.';
            }
            if ($existing && (float) ($data['hour_meter'] ?? 0) < $this->number($existing, 'hour_meter')) {
                $errors['data.hour_meter'] = 'The hour meter cannot go backwards (it reads '.$this->number($existing, 'hour_meter').').';
            }

            return $errors;
        }

        $plant = ! empty($data['plant']) ? $this->records('plant')->find($data['plant']) : null;
        $changed = ! $existing || (int) $existing->value('plant') !== $plant?->id;
        if ($plant && in_array($payload['status'], self::OPEN_HIRES, true)) {
            if (in_array($plant->status, self::UNAVAILABLE, true)) {
                $errors['data.plant'] = $plant->title.' is '.($plant->status === 'service' ? 'in for service' : 'broken down').'.';
            } elseif ($changed || ! in_array($existing->status, self::OPEN_HIRES, true)) {
                $other = $this->linked('hires', 'plant', $plant)->whereIn('status', self::OPEN_HIRES)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
                if ($other) {
                    $errors['data.plant'] = $plant->title.' is already out on hire for '.$other->title.'.';
                }
            }
        }
        if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['due_on'] = 'The return date cannot be before the hire goes out.';
        }
        if ((float) ($data['hours_used'] ?? 0) < 0) {
            $errors['data.hours_used'] = 'Hours used cannot be negative.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'hires') {
            return;
        }

        $record->occurs_on ??= today();
        $rate = $this->number($record, 'rate');
        if ($rate <= 0 || blank($record->value('rate_type'))) {
            return;
        }
        $days = max(1, (int) $record->occurs_on->diffInDays($record->due_on ?? today()) + 1);
        $record->amount = round($rate * match ($record->value('rate_type')) {
            'hourly' => $this->number($record, 'hours_used'),
            'weekly' => ceil($days / 7),
            'monthly' => ceil($days / 30),
            default => $days,
        }, 2);
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'hires' || ! ($plant = $this->parent($record, 'plant'))) {
            return;
        }

        if ($record->status === 'on_hire' && $plant->status !== 'on_hire') {
            $plant->update(['status' => 'on_hire']);

            return;
        }
        if (! in_array($record->status, ['returned', 'invoiced'], true)) {
            return;
        }

        $changes = [];
        $meter = $this->number($plant, 'hour_meter');
        if (! $record->value('_metered') && $this->number($record, 'hours_used') > 0) {
            $meter += $this->number($record, 'hours_used');
            $changes['data'] = [...(array) $plant->data, 'hour_meter' => $meter];
            $this->put($record, ['_metered' => true]);
            $record->saveQuietly();
        }
        if ($plant->status === 'on_hire') {
            $due = $this->number($plant, 'service_due_hours');
            $changes['status'] = $due > 0 && $meter >= $due ? 'service' : 'available';
        }
        if ($changes) {
            $plant->update($changes);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'plant') {
            return $record->status === 'service' ? ['back_in_service' => ['label' => 'Service done', 'icon' => 'wrench', 'fields' => [
                ['name' => 'service_due_hours', 'label' => 'Next service at (hours)', 'type' => 'number', 'value' => $this->number($record, 'hour_meter') + self::SERVICE_INTERVAL],
            ]]] : [];
        }

        return match ($record->status) {
            'booked' => ['dispatch' => ['label' => 'Dispatch', 'icon' => 'truck']],
            'on_hire' => ['return_plant' => ['label' => 'Return', 'icon' => 'undo-2', 'fields' => [
                ['name' => 'hours_used', 'label' => 'Hours used', 'type' => 'number', 'value' => $this->number($record, 'hours_used')],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'dispatch') {
            $record->update(['status' => 'on_hire']);

            return $record->title.' is out on hire.';
        }
        if ($action === 'back_in_service') {
            $due = (float) $request->validate(['service_due_hours' => ['required', 'numeric', 'min:0']])['service_due_hours'];
            $record->update(['status' => 'available', 'data' => [...(array) $record->data, 'service_due_hours' => $due]]);

            return $record->title.' is back in service; next service at '.$due.' hours.';
        }

        $hours = (float) $request->validate(['hours_used' => ['required', 'numeric', 'min:0']])['hours_used'];
        $record->update(['status' => 'returned', 'data' => [...(array) $record->data, 'hours_used' => $hours]]);

        return $record->title.' returned after '.$hours.' hours ('.$this->money($record->amount).').';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'plant') {
            return [];
        }

        $meter = $this->number($record, 'hour_meter');
        $due = $this->number($record, 'service_due_hours');
        $toService = $due > 0 ? $due - $meter : null;
        $hires = $this->linked('hires', 'plant', $record)->with('contact')->orderByDesc('occurs_on')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Machine', 'icon' => 'forklift', 'stats' => [
                ['label' => 'Hour meter', 'value' => $meter.' h'],
                ['label' => 'Service due at', 'value' => $due > 0 ? $due.' h' : '—'],
                ['label' => 'Hours to service', 'value' => $toService === null ? '—' : $toService.' h', 'tone' => $toService === null ? null : ($toService <= 0 ? 'danger' : ($toService <= 50 ? 'warning' : null))],
                ['label' => 'Hires', 'value' => (string) $hires->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Hire history', 'icon' => 'calendar-range', 'empty' => 'Never been out on hire.',
                'rows' => $hires->take(10)->map(fn (Record $hire) => [
                    'label' => $hire->title, 'sub' => trim(($hire->contact?->name ?? '').' · '.$hire->occurs_on?->format('d M Y'), ' ·'), 'value' => $this->money($hire->amount), 'href' => $hire->url(),
                    'tone' => $hire->status === 'on_hire' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $plant = $this->records('plant')->get();
        $dueForService = $plant->filter(fn (Record $item) => $this->number($item, 'service_due_hours') > 0 && $this->number($item, 'hour_meter') >= $this->number($item, 'service_due_hours'));
        $overdue = $this->records('hires')->where('status', 'on_hire')->whereDate('due_on', '<', today())->with('contact')->orderBy('due_on')->get();
        $names = $plant->pluck('title', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Fleet', 'icon' => 'forklift', 'stats' => [
                ['label' => 'Available', 'value' => (string) $plant->where('status', 'available')->count()],
                ['label' => 'On hire', 'value' => (string) $plant->where('status', 'on_hire')->count()],
                ['label' => 'Down', 'value' => (string) $plant->whereIn('status', self::UNAVAILABLE)->count(), 'tone' => $plant->whereIn('status', self::UNAVAILABLE)->isNotEmpty() ? 'danger' : null],
                ['label' => 'Due for service', 'value' => (string) $dueForService->count(), 'tone' => $dueForService->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue returns', 'icon' => 'clock-alert', 'empty' => 'Everything on hire is within its return date.',
                'rows' => $overdue->map(fn (Record $hire) => [
                    'label' => $hire->title, 'sub' => trim(($names[$hire->value('plant')] ?? '').' · '.($hire->contact?->name ?? ''), ' ·'), 'value' => 'Due '.$hire->due_on->format('d M'), 'href' => $hire->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $hires = $this->dated('hires', $from, $to)->with('contact')->get();
        $plant = $this->records('plant')->orderBy('title')->get();

        $utilisation = $plant->map(fn (Record $item) => [
            $item->title, $item->value('fleet_number'), $hires->where('data.plant', $item->id)->count(),
            (float) $hires->where('data.plant', $item->id)->sum(fn (Record $hire) => $this->number($hire, 'hours_used')), $this->money($hires->where('data.plant', $item->id)->sum('amount')),
        ])->values()->all();

        $customers = $hires->groupBy(fn (Record $hire) => $hire->contact?->name ?? 'No customer')->sortKeys()->map(fn ($group, $customer) => [
            $customer, $group->count(), $this->money($group->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Utilisation by machine', 'columns' => ['Machine', 'Fleet no.', 'Hires', 'Hours', 'Revenue'], 'rows' => $utilisation],
            ['title' => 'Revenue by customer', 'columns' => ['Customer', 'Hires', 'Revenue'], 'rows' => $customers],
        ];
    }
}
