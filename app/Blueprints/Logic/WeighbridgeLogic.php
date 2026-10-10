<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Weighbridge: a ticket is opened on the first weigh and completed on the second, whichever way the
 * truck travels: the heavier mass is the gross, the lighter the tare and the net is the difference, so
 * net is never typed. A vehicle has one open ticket at a time. A completed ticket can use the vehicle's
 * stored tare from the last 30 days instead of a second weigh. Gross mass over the 56 t legal limit is
 * flagged as an overload, and voiding a ticket needs a reason.
 */
class WeighbridgeLogic extends AppLogic
{
    /**
     * Legal gross vehicle mass in kg.
     */
    protected const LEGAL_GROSS = 56000;

    /**
     * Days a stored tare stays usable.
     */
    protected const TARE_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($existing && in_array($existing->status, ['completed', 'void'], true) && $payload['status'] !== $existing->status && $payload['status'] !== 'void') {
            $errors['status'] = 'This ticket is '.$existing->status.'.';
        }
        if ($existing?->status === 'completed' && ((float) ($data['gross'] ?? 0) !== $this->number($existing, 'gross') || (float) ($data['tare'] ?? 0) !== $this->number($existing, 'tare'))) {
            $errors['data.gross'] = 'The masses on a completed ticket cannot be changed; void it and weigh again.';
        }
        if ((float) ($data['gross'] ?? 0) <= 0) {
            $errors['data.gross'] = 'Enter the mass from the bridge.';
        }
        if ($payload['status'] === 'completed') {
            if ((float) ($data['tare'] ?? 0) <= 0) {
                $errors['data.tare'] = 'Weigh the vehicle a second time or use its stored tare.';
            } elseif ((float) $data['tare'] >= (float) ($data['gross'] ?? 0)) {
                $errors['data.tare'] = 'The tare must be lighter than the gross.';
            }
        }
        if ($payload['status'] === 'first_weigh' && ($open = $this->openTicket((string) $payload['title'], $existing?->id))) {
            $errors['title'] = $open->title.' is already on '.$open->number.'; complete or void it first.';
        }

        return $errors;
    }

    /**
     * The registration in a form that matches however it was typed.
     */
    protected function plate(string $registration): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $registration));
    }

    /**
     * A ticket still waiting for its second weigh.
     */
    protected function openTicket(string $registration, ?int $except = null): ?Record
    {
        return $this->records('tickets')->where('status', 'first_weigh')->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $ticket) => $this->plate($ticket->title) === $this->plate($registration));
    }

    /**
     * The vehicle's tare from a recent completed ticket.
     */
    protected function storedTare(string $registration, ?int $except = null): ?Record
    {
        return $this->records('tickets')->where('status', 'completed')->where('occurs_on', '>=', today()->subDays(self::TARE_DAYS)->startOfDay())
            ->when($except, fn ($query) => $query->whereKeyNot($except))->orderByDesc('occurs_on')->orderByDesc('id')->get()
            ->first(fn (Record $ticket) => $this->plate($ticket->title) === $this->plate($registration) && $this->number($ticket, 'tare') > 0);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $record->title = strtoupper(trim($record->title));
        $gross = $this->number($record, 'gross');
        $tare = $this->number($record, 'tare');
        $this->put($record, [
            'net' => $tare > 0 ? round(abs($gross - $tare), 1) : null,
            '_overload' => max($gross, $tare) > self::LEGAL_GROSS ? round(max($gross, $tare) - self::LEGAL_GROSS, 1) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        if ($record->status === 'first_weigh') {
            $stored = $this->storedTare($record->title, $record->id);

            return [
                'second_weigh' => ['label' => 'Second weigh', 'icon' => 'weight', 'fields' => [['name' => 'mass', 'label' => 'Mass on the bridge (kg)', 'type' => 'number']]],
                ...($stored ? ['stored_tare' => ['label' => 'Use stored tare ('.number_format($this->number($stored, 'tare')).' kg)', 'icon' => 'history']] : []),
                'void' => ['label' => 'Void', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
            ];
        }

        return $record->status === 'completed' ? ['void' => ['label' => 'Void', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'void') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $record->update(['status' => 'void', 'data' => [...$record->data, '_void_reason' => $reason]]);

            return $record->number.' voided: '.$reason.'.';
        }

        $first = $this->number($record, 'gross');
        if ($action === 'stored_tare') {
            $stored = $this->storedTare($record->title, $record->id) ?? throw ValidationException::withMessages(['mass' => 'No stored tare for '.$record->title.' in the last '.self::TARE_DAYS.' days.']);
            $second = $this->number($stored, 'tare');
        } else {
            $second = (float) $request->validate(['mass' => ['required', 'numeric', 'gt:0']])['mass'];
        }
        if (abs($first - $second) < 0.5) {
            throw ValidationException::withMessages(['mass' => 'Both weighs are the same; nothing was loaded or offloaded.']);
        }
        $record->update(['status' => 'completed', 'data' => [...$record->data, 'gross' => max($first, $second), 'tare' => min($first, $second), '_stored_tare' => $action === 'stored_tare']]);

        return $record->title.' weighed: net '.number_format($this->number($record, 'net')).' kg'.($record->value('_overload') ? '; overloaded by '.number_format($this->number($record, '_overload')).' kg.' : '.');
    }

    public function homeCards(): array
    {
        $open = $this->records('tickets')->where('status', 'first_weigh')->orderBy('created_at')->get();
        $today = $this->records('tickets')->where('status', 'completed')->whereDate('occurs_on', today())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'weight', 'stats' => [
                ['label' => 'Tonnes in', 'value' => number_format($today->where('data.direction', 'in')->sum(fn (Record $ticket) => $this->number($ticket, 'net')) / 1000, 1)],
                ['label' => 'Tonnes out', 'value' => number_format($today->where('data.direction', 'out')->sum(fn (Record $ticket) => $this->number($ticket, 'net')) / 1000, 1)],
                ['label' => 'Tickets', 'value' => (string) $today->count()],
                ['label' => 'Overloads', 'value' => (string) $today->filter(fn (Record $ticket) => $ticket->value('_overload'))->count(), 'tone' => $today->contains(fn (Record $ticket) => $ticket->value('_overload')) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for second weigh', 'icon' => 'truck', 'empty' => 'No vehicles on site.',
                'rows' => $open->map(fn (Record $ticket) => ['label' => $ticket->title, 'sub' => ucfirst((string) $ticket->value('direction')).' · '.($ticket->value('product') ?: '—'), 'value' => number_format($this->number($ticket, 'gross')).' kg', 'href' => $ticket->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tickets = $this->dated('tickets', $from, $to)->get();
        $completed = $tickets->where('status', 'completed');

        $byProduct = $completed->groupBy(fn (Record $ticket) => (trim((string) $ticket->value('product')) ?: '—').'|'.$ticket->value('direction'))->sortKeys()
            ->map(function (Collection $group, string $key) {
                [$product, $direction] = explode('|', $key);

                return [$product, ucfirst($direction), $group->count(), number_format($group->sum(fn (Record $ticket) => $this->number($ticket, 'net')) / 1000, 2)];
            })->values()->all();

        $overloads = $completed->filter(fn (Record $ticket) => $ticket->value('_overload'))
            ->map(fn (Record $ticket) => [$ticket->occurs_on?->format('d M Y'), $ticket->title, number_format(max($this->number($ticket, 'gross'), $this->number($ticket, 'tare'))), number_format($this->number($ticket, '_overload'))])->values()->all();

        $voids = $tickets->where('status', 'void')->map(fn (Record $ticket) => [$ticket->number, $ticket->title, $ticket->value('_void_reason') ?? '—'])->values()->all();

        return [
            ['title' => 'Tonnage by product', 'columns' => ['Product', 'Direction', 'Tickets', 'Net tonnes'], 'rows' => $byProduct],
            ['title' => 'Overloads', 'columns' => ['Date', 'Vehicle', 'Gross (kg)', 'Over by (kg)'], 'rows' => $overloads],
            ['title' => 'Voided tickets', 'columns' => ['Ticket', 'Vehicle', 'Reason'], 'rows' => $voids],
        ];
    }
}
