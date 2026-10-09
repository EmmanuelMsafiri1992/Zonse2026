<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Construction projects & BOQ: every bill item is priced as quantity × rate, item numbers are unique
 * within a tender, and the tender value is the bill's cost plus the markup. A tender cannot go out
 * before its bill is priced, and lost or withdrawn tenders take no more items.
 */
class ConstructionLogic extends AppLogic
{
    public const CLOSED = ['lost', 'withdrawn'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'tenders') {
            $markup = (float) ($data['markup'] ?? 0);
            if ($markup < 0 || $markup > 100) {
                $errors['data.markup'] = 'Markup must be between 0 and 100%.';
            }
            if ($existing?->status === 'preparing' && ! in_array($payload['status'], ['preparing', 'withdrawn'], true) && ! $this->linked('boq_items', 'tender', $existing)->exists()) {
                $errors['status'] = 'Price the bill of quantities before the tender goes out.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The closing date cannot be before the tender was issued.';
            }

            return $errors;
        }

        if ((float) ($data['quantity'] ?? 0) <= 0) {
            $errors['data.quantity'] = 'Quantity must be more than 0.';
        }
        if ((float) ($data['rate'] ?? 0) < 0) {
            $errors['data.rate'] = 'The rate cannot be negative.';
        }
        $tender = ! empty($data['tender']) ? $this->records('tenders')->find($data['tender']) : null;
        if ($tender && in_array($tender->status, self::CLOSED, true) && (! $existing || (int) $existing->value('tender') !== $tender->id)) {
            $errors['data.tender'] = $tender->title.' is '.$tender->status.' and takes no more items.';
        }
        if ($tender && filled($data['item_number'] ?? null) && $this->linked('boq_items', 'tender', $tender)->where('data->item_number', $data['item_number'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
            $errors['data.item_number'] = 'Item '.$data['item_number'].' is already in this bill.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'boq_items') {
            $record->amount = round($this->number($record, 'quantity') * $this->number($record, 'rate'), 2);

            return;
        }

        $items = $record->exists ? $this->linked('boq_items', 'tender', $record)->get() : collect();
        $cost = (float) $items->sum('amount');
        $this->put($record, ['_items' => $items->count(), '_cost' => $cost]);
        if ($items->isNotEmpty()) {
            $record->amount = round($cost * (1 + $this->number($record, 'markup') / 100), 2);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'boq_items') {
            $this->recalculate($this->parent($record, 'tender'));
            $this->recalculate($this->previousParent($record, 'tender'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'boq_items') {
            $this->recalculate($this->parent($record, 'tender'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'tenders') {
            return [];
        }

        return match ($record->status) {
            'preparing' => (int) $record->value('_items') > 0 ? ['submit' => ['label' => 'Submit tender', 'icon' => 'send', 'confirm' => 'Submit this tender at '.$this->money($record->amount).'?']] : [],
            'submitted' => [
                'won' => ['label' => 'Won', 'icon' => 'trophy'],
                'lost' => ['label' => 'Lost', 'icon' => 'x-circle'],
            ],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $record->update(['status' => $action === 'submit' ? 'submitted' : $action]);

        return match ($action) {
            'submit' => $record->title.' submitted at '.$this->money($record->amount).'.',
            'won' => 'Congratulations, '.$record->title.' is won.',
            default => $record->title.' is marked lost.',
        };
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'tenders') {
            return [];
        }

        $items = $this->linked('boq_items', 'tender', $record)->get()->sortBy(fn (Record $item) => (string) $item->value('item_number'), SORT_NATURAL);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Bill of quantities', 'icon' => 'list-ordered', 'stats' => [
                ['label' => 'Items', 'value' => (string) (int) $record->value('_items')],
                ['label' => 'Cost', 'value' => $this->money($record->value('_cost'))],
                ['label' => 'Markup', 'value' => $this->number($record, 'markup').'%'],
                ['label' => 'Tender value', 'value' => $this->money($record->amount)],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'BOQ items', 'icon' => 'list-ordered', 'empty' => 'No items priced yet.',
                'rows' => $items->take(15)->map(fn (Record $item) => [
                    'label' => trim($item->value('item_number').' '.$item->title), 'sub' => $this->number($item, 'quantity').' '.$item->value('unit').' @ '.$this->money($item->value('rate')),
                    'value' => $this->money($item->amount), 'href' => $item->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $tenders = $this->records('tenders')->with('contact')->get();
        $open = $tenders->whereIn('status', ['preparing', 'submitted']);
        $decided = $tenders->whereIn('status', ['won', 'lost']);
        $wonThisYear = $tenders->where('status', 'won')->filter(fn (Record $tender) => $tender->occurs_on?->isCurrentYear());
        $closing = $open->filter(fn (Record $tender) => $tender->due_on !== null && $tender->due_on->lte(today()->addDays(14)))->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tenders', 'icon' => 'hard-hat', 'stats' => [
                ['label' => 'Being priced', 'value' => (string) $open->where('status', 'preparing')->count()],
                ['label' => 'Submitted', 'value' => $this->money($open->where('status', 'submitted')->sum('amount'))],
                ['label' => 'Won this year', 'value' => $this->money($wonThisYear->sum('amount')), 'tone' => $wonThisYear->isNotEmpty() ? 'success' : null],
                ['label' => 'Win rate', 'value' => $decided->isEmpty() ? '—' : round($tenders->where('status', 'won')->count() / $decided->count() * 100).'%'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Closing soon', 'icon' => 'calendar-clock', 'empty' => 'Nothing closes in the next two weeks.',
                'rows' => $closing->map(fn (Record $tender) => [
                    'label' => $tender->title, 'sub' => $tender->contact?->name ?? $tender->value('employer'), 'value' => $tender->due_on->format('d M'), 'href' => $tender->url(),
                    'tone' => $tender->due_on->lt(today()) ? 'danger' : ($tender->due_on->lte(today()->addDays(3)) ? 'warning' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tenders = $this->dated('tenders', $from, $to)->get();
        $statuses = $this->app->entities['tenders']->statuses;

        $outcomes = collect($statuses)->map(fn (string $label, string $status) => [
            $label, $tenders->where('status', $status)->count(), $this->money($tenders->where('status', $status)->sum('amount')),
        ])->values()->all();

        $costs = $tenders->sortByDesc('amount')->map(fn (Record $tender) => [
            $tender->title, $statuses[$tender->status] ?? $tender->status, (int) $tender->value('_items'), $this->money($tender->value('_cost')), $this->number($tender, 'markup').'%', $this->money($tender->amount),
        ])->values()->all();

        return [
            ['title' => 'Tenders by outcome', 'columns' => ['Outcome', 'Tenders', 'Value'], 'rows' => $outcomes],
            ['title' => 'Cost by tender', 'columns' => ['Tender', 'Status', 'Items', 'Cost', 'Markup', 'Tender value'], 'rows' => $costs],
        ];
    }
}
