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
 * Weighbridge & bulk dispatch: a truck has one open ticket at a time. The first weigh is recorded on
 * arrival and the second weigh completes the ticket: the heavier mass is the gross, the lighter the
 * tare, and the net is worked out, never typed. A second weigh that goes the wrong way for the
 * direction (an inbound truck leaving heavier) is refused. Completed tickets are locked, voiding
 * needs a reason, loads over the legal limit are flagged, and outbound tickets print a dispatch note.
 */
class BulkDispatchLogic extends AppLogic
{
    /**
     * Legal gross vehicle mass (kg) before a load is flagged as overloaded.
     */
    public const LEGAL_LIMIT = 56000;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($existing && in_array($existing->status, ['completed', 'void'], true)) {
            foreach (['gross_mass', 'tare_mass', 'net_mass', 'direction', 'product'] as $field) {
                if ((string) ($data[$field] ?? '') !== (string) $existing->value($field)) {
                    $errors['data.'.$field] = 'This ticket is '.$existing->status.' and its weights are locked.';
                }
            }
            if ($status !== $existing->status) {
                $errors['status'] = 'This ticket is '.$existing->status.'.';
            }

            return $errors;
        }
        if ($status === 'void') {
            $errors['status'] = 'Use "Void" so the reason is recorded.';
        }
        if ((float) ($data['gross_mass'] ?? 0) <= 0) {
            $errors['data.gross_mass'] = 'Enter the first weigh.';
        }
        if (filled($data['tare_mass'] ?? null) && (float) $data['tare_mass'] <= 0) {
            $errors['data.tare_mass'] = 'Enter a mass above zero.';
        }
        if ($status === 'completed' && blank($data['tare_mass'] ?? null)) {
            $errors['status'] = 'Record the second weigh to complete the ticket.';
        }
        $registration = $this->registration((string) $payload['title']);
        $open = $this->records('tickets')->where('status', 'first_weigh')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $ticket) => $this->registration($ticket->title) === $registration);
        if ($open) {
            $errors['title'] = $registration.' is still on the bridge with '.$open->number.'.';
        }

        return $errors;
    }

    /**
     * A registration in one form however it was typed.
     */
    protected function registration(string $registration): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $registration));
    }

    public function saving(Record $record): void
    {
        $record->title = $this->registration($record->title);
        $record->occurs_on ??= now();
        if (in_array($record->getOriginal('status'), ['completed', 'void'], true)) {
            return;
        }
        $first = $record->value('_first_mass') ?? $this->number($record, 'gross_mass');
        $this->put($record, ['_first_mass' => (float) $first]);
        if (filled($record->value('tare_mass'))) {
            $this->weigh($record, (float) $first, $this->number($record, 'tare_mass'));
        } else {
            $this->put($record, ['net_mass' => null]);
        }
    }

    /**
     * Settle gross, tare and net from the two weighs.
     */
    protected function weigh(Record $record, float $first, float $second): void
    {
        $gross = max($first, $second);
        $this->put($record, [
            'gross_mass' => $gross,
            'tare_mass' => min($first, $second),
            'net_mass' => abs($first - $second),
            '_second_mass' => $second,
            '_overload' => $gross > self::LEGAL_LIMIT,
            '_completed_at' => $record->value('_completed_at') ?? now()->toDateTimeString(),
        ]);
        $record->status = 'completed';
    }

    public function actions(Record $record): array
    {
        if ($record->status !== 'first_weigh') {
            return [];
        }

        return [
            'second_weigh' => ['label' => 'Second weigh', 'icon' => 'weight', 'fields' => [['name' => 'mass', 'label' => 'Mass (kg)', 'type' => 'number']]],
            'void' => ['label' => 'Void', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'void') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $record->update(['status' => 'void', 'data' => [...$record->data, '_void_reason' => $reason]]);

            return $record->number.' voided: '.$reason.'.';
        }

        $mass = (float) $request->validate(['mass' => ['required', 'numeric', 'gt:0']])['mass'];
        $first = $this->number($record, '_first_mass');
        if ($mass === $first) {
            throw ValidationException::withMessages(['mass' => 'The second weigh matches the first; nothing was loaded or off-loaded.']);
        }
        $inbound = $record->value('direction') === 'inbound';
        if ($inbound && $mass > $first) {
            throw ValidationException::withMessages(['mass' => 'An inbound truck should leave lighter than it arrived ('.number_format($first).' kg).']);
        }
        if (! $inbound && $mass < $first) {
            throw ValidationException::withMessages(['mass' => 'An outbound truck should leave heavier than it arrived ('.number_format($first).' kg).']);
        }
        $record->update(['data' => [...$record->data, 'tare_mass' => $mass]]);
        $record->refresh();

        return $record->title.': '.number_format($this->number($record, 'net_mass')).' kg net '.$record->value('product').($record->value('_overload') ? '; overloaded at '.number_format($this->number($record, 'gross_mass')).' kg gross.' : '.');
    }

    public function documents(Record $record): array
    {
        return $record->status === 'completed' && $record->value('direction') === 'outbound' ? ['dispatch_note' => 'Dispatch note'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'dispatch_note' || $record->status !== 'completed' || $record->value('direction') !== 'outbound') {
            return null;
        }

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Dispatch note',
            'meta' => array_filter([
                'Ticket' => $record->number,
                'Customer' => $record->contact?->name,
                'Truck' => $record->title,
                'Driver' => $record->value('driver'),
                'Order' => $record->value('order_reference'),
                'Weighed' => $record->value('_completed_at') ? Carbon::parse($record->value('_completed_at'))->format('d M Y H:i') : null,
            ]),
            'columns' => ['Product', 'Gross (kg)', 'Tare (kg)', 'Net (kg)'],
            'rows' => [[$record->value('product'), number_format($this->number($record, 'gross_mass')), number_format($this->number($record, 'tare_mass')), number_format($this->number($record, 'net_mass'))]],
            'totals' => ['Net tonnes' => number_format($this->number($record, 'net_mass') / 1000, 2).' t'],
        ]];
    }

    public function homeCards(): array
    {
        $onBridge = $this->records('tickets')->where('status', 'first_weigh')->orderBy('occurs_on')->get();
        $today = $this->records('tickets')->where('status', 'completed')->whereDate('occurs_on', today()->toDateString())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'weight', 'stats' => [
                ['label' => 'Tonnes out', 'value' => number_format($today->where('data.direction', 'outbound')->sum(fn (Record $ticket) => $this->number($ticket, 'net_mass')) / 1000, 1)],
                ['label' => 'Tonnes in', 'value' => number_format($today->where('data.direction', 'inbound')->sum(fn (Record $ticket) => $this->number($ticket, 'net_mass')) / 1000, 1)],
                ['label' => 'Overloads', 'value' => $today->filter(fn (Record $ticket) => $ticket->value('_overload'))->count(), 'tone' => $today->contains(fn (Record $ticket) => $ticket->value('_overload')) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Waiting for second weigh', 'icon' => 'truck', 'empty' => 'No trucks on site.',
                'rows' => $onBridge->map(fn (Record $ticket) => ['label' => $ticket->title, 'sub' => $ticket->value('direction').' · '.$ticket->value('product'), 'value' => number_format($this->number($ticket, '_first_mass')).' kg', 'href' => $ticket->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tickets = $this->dated('tickets', $from, $to)->get();
        $completed = $tickets->where('status', 'completed');

        $byProduct = $completed->groupBy(fn (Record $ticket) => $ticket->value('product').'|'.$ticket->value('direction'))->sortKeys()
            ->map(function (Collection $group) {
                $first = $group->first();

                return [$first->value('product'), ucfirst((string) $first->value('direction')), $group->count(), number_format($group->sum(fn (Record $ticket) => $this->number($ticket, 'net_mass')) / 1000, 2).' t', number_format($group->avg(fn (Record $ticket) => $this->number($ticket, 'net_mass')) / 1000, 2).' t'];
            })->values()->all();

        $exceptions = $tickets->filter(fn (Record $ticket) => $ticket->status === 'void' || $ticket->value('_overload'))->sortBy('occurs_on')
            ->map(fn (Record $ticket) => [$ticket->number, $ticket->title, $ticket->occurs_on?->format('d M Y'), $ticket->status === 'void' ? 'Void: '.$ticket->value('_void_reason') : 'Overload: '.number_format($this->number($ticket, 'gross_mass')).' kg'])->values()->all();

        return [
            ['title' => 'Tonnage by product', 'columns' => ['Product', 'Direction', 'Loads', 'Net', 'Average load'], 'rows' => $byProduct],
            ['title' => 'Overloads and voided tickets', 'columns' => ['Ticket', 'Truck', 'Date', 'Exception'], 'rows' => $exceptions],
        ];
    }
}
