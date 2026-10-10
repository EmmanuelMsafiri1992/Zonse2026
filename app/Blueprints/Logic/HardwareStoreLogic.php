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
 * Hardware & building supplies: a quote is valid for 14 days unless a date is set, and one that needs
 * delivery must have a site address. Quotes past their date expire each night and cannot be accepted.
 * Accepting a quote that needs delivery books the delivery. Deliveries only go out on accepted quotes,
 * move scheduled → loaded → delivered, and need a signed delivery note to be marked delivered.
 */
class HardwareStoreLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'quotes') {
            if (! empty($data['delivery_needed']) && blank($data['site_address'] ?? null)) {
                $errors['data.site_address'] = 'Give the site address for delivery.';
            }
            if ($payload['status'] === 'accepted' && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(today()) && $existing?->status !== 'accepted') {
                $errors['status'] = 'This quote expired on '.Carbon::parse($payload['due_on'])->format('d M Y').'; quote again.';
            }

            return $errors;
        }
        if (filled($data['quote'] ?? null) && ($quote = $this->records('quotes')->find($data['quote'])) && $quote->status !== 'accepted') {
            $errors['data.quote'] = 'Quote '.$quote->title.' is '.$quote->status.', not accepted.';
        }
        if ($payload['status'] === 'delivered' && blank($data['delivery_note'] ?? null)) {
            $errors['data.delivery_note'] = 'Give the signed delivery note number.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'quotes') {
            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(14);
        if (in_array($record->status, ['draft', 'sent'], true) && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('quotes')->whereIn('status', ['draft', 'sent'])->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $quote) => $quote->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'quotes' && $record->status === 'draft' => ['send' => ['label' => 'Sent to customer', 'icon' => 'send']],
            $record->entity === 'quotes' && $record->status === 'sent' => ['accept' => ['label' => 'Accepted', 'icon' => 'check', 'fields' => $record->value('delivery_needed') ? [['name' => 'delivery_date', 'label' => 'Delivery date', 'type' => 'date', 'value' => today()->addDay()->toDateString()]] : []]],
            $record->entity === 'deliveries' && $record->status === 'scheduled' => ['load' => ['label' => 'Loaded', 'icon' => 'package'], 'returned' => ['label' => 'Returned to yard', 'icon' => 'undo-2']],
            $record->entity === 'deliveries' && $record->status === 'loaded' => ['deliver' => ['label' => 'Delivered', 'icon' => 'check', 'fields' => [['name' => 'delivery_note', 'label' => 'Signed delivery note', 'type' => 'text', 'value' => $record->value('delivery_note')]]], 'returned' => ['label' => 'Returned to yard', 'icon' => 'undo-2']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'send':
                $record->update(['status' => 'sent']);

                return 'Quote sent to '.$record->title.'; valid until '.$record->due_on->format('d M Y').'.';
            case 'accept':
                if ($record->due_on && $record->due_on->lt(today())) {
                    throw ValidationException::withMessages(['status' => 'This quote expired on '.$record->due_on->format('d M Y').'; quote again.']);
                }
                $record->update(['status' => 'accepted']);
                if (! $record->value('delivery_needed')) {
                    return $record->title.' accepted the quote.';
                }
                $date = Carbon::parse($request->validate(['delivery_date' => ['nullable', 'date', 'after_or_equal:today']])['delivery_date'] ?? today()->addDay());
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'deliveries',
                    'title' => $record->title, 'status' => 'scheduled', 'occurs_on' => $date, 'assignee_id' => $record->assignee_id,
                    'data' => ['quote' => $record->id],
                ]);

                return $record->title.' accepted the quote; delivery booked for '.$date->format('d M').'.';
            case 'load':
                $record->update(['status' => 'loaded']);

                return 'Delivery to '.$record->title.' loaded.';
            case 'deliver':
                $note = trim((string) ($request->validate(['delivery_note' => ['nullable', 'string', 'max:100']])['delivery_note'] ?? '')) ?: $record->value('delivery_note');
                if (blank($note)) {
                    throw ValidationException::withMessages(['delivery_note' => 'Give the signed delivery note number.']);
                }
                $record->update(['status' => 'delivered', 'data' => [...$record->data, 'delivery_note' => $note]]);

                return 'Delivered to '.$record->title.' on note '.$note.'.';
            default:
                $record->update(['status' => 'returned']);

                return 'Delivery to '.$record->title.' came back to the yard.';
        }
    }

    public function homeCards(): array
    {
        $today = $this->records('deliveries')->whereIn('status', ['scheduled', 'loaded'])->whereDate('occurs_on', '<=', today()->toDateString())->orderBy('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Deliveries due', 'icon' => 'truck', 'empty' => 'No deliveries due today.',
            'rows' => $today->map(fn (Record $delivery) => ['label' => $delivery->title, 'sub' => trim(($delivery->value('vehicle') ?? '').' '.($delivery->value('driver') ?? '')) ?: 'No vehicle yet', 'value' => $delivery->occurs_on->isToday() ? ucfirst($delivery->status) : 'late since '.$delivery->occurs_on->format('d M'), 'href' => $delivery->url(), 'tone' => $delivery->occurs_on->lt(today()) ? 'danger' : null])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $quotes = $this->dated('quotes', $from, $to)->get();

        return [['title' => 'Quotes won by month', 'columns' => ['Month', 'Quotes', 'Accepted', 'Win rate', 'Value won'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($quotes) {
                $inMonth = $quotes->filter(fn (Record $quote) => $quote->occurs_on->format('Y-m') === $month);
                $won = $inMonth->where('status', 'accepted');

                return [$label, $inMonth->count(), $won->count(), $inMonth->isNotEmpty() ? round($won->count() / $inMonth->count() * 100).'%' : '—', $this->money($won->sum('amount'))];
            })->values()->all()]];
    }
}
