<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Canteen: a cashless card's balance is its top-ups less its card sales, so a card sale needs an
 * active card with enough balance and within its daily limit, and a refund or reversal puts the
 * money back. Card numbers are unique, blocked and lost cards cannot spend, and the canteen
 * flags cards running low.
 */
class CanteenLogic extends AppLogic
{
    public const LOW_BALANCE = 100;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'cards') {
            $number = strtoupper(trim((string) ($data['card_number'] ?? '')));
            if ($number !== '' && $this->records('cards')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $card) => strtoupper(trim((string) $card->value('card_number'))) === $number)) {
                $errors['data.card_number'] = 'Card '.$number.' is already issued.';
            }
            if (filled($data['daily_limit'] ?? null) && (float) $data['daily_limit'] < 0) {
                $errors['data.daily_limit'] = 'The daily limit cannot be negative.';
            }

            return $errors;
        }

        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            $errors['amount'] = 'Enter an amount above zero.';
        }
        $card = ! empty($data['card']) ? $this->records('cards')->find($data['card']) : null;

        if ($entity->key === 'topups') {
            if ($card && $card->status === 'lost' && $payload['status'] === 'received') {
                $errors['data.card'] = 'Card '.$card->value('card_number').' is lost; issue a new one.';
            }

            return $errors;
        }

        if (($data['payment'] ?? 'card') === 'card' && ! $card) {
            $errors['data.card'] = 'Choose the card to charge.';
        }
        $charging = $card && ($data['payment'] ?? 'card') === 'card' && $payload['status'] === 'completed' && (! $existing || $existing->status !== 'completed' || (int) $existing->value('card') !== $card->id);
        if ($charging) {
            if ($card->status !== 'active') {
                $errors['data.card'] = 'Card '.$card->value('card_number').' is '.$card->status.'.';
            } elseif ($amount > $this->balance($card)) {
                $errors['amount'] = 'Only '.$this->money($this->balance($card)).' is left on the card.';
            } elseif (($limit = (float) $card->value('daily_limit')) > 0) {
                $day = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
                $spent = $this->linked('sales', 'card', $card)->where('status', 'completed')->where('data->payment', 'card')->whereDate('occurs_on', $day)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->sum('amount');
                if ($spent + $amount > $limit) {
                    $errors['amount'] = 'This takes the card past its daily limit of '.$this->money($limit).' ('.$this->money($spent).' spent today).';
                }
            }
        }

        return $errors;
    }

    protected function balance(Record $card): float
    {
        $in = $this->linked('topups', 'card', $card)->where('status', 'received')->sum('amount');
        $out = $this->linked('sales', 'card', $card)->where('status', 'completed')->where('data->payment', 'card')->sum('amount');

        return round((float) $in - (float) $out, 2);
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'cards') {
            $balance = $record->exists ? $this->balance($record) : 0.0;
            $spentToday = $record->exists ? (float) $this->linked('sales', 'card', $record)->where('status', 'completed')->where('data->payment', 'card')->whereDate('occurs_on', today())->sum('amount') : 0.0;
            $this->put($record, [
                'card_number' => strtoupper(trim((string) $record->value('card_number'))),
                'balance' => $balance,
                '_spent_today' => $spentToday,
                '_low' => $balance < self::LOW_BALANCE,
            ]);

            return;
        }

        $record->occurs_on ??= today();
        if ($record->entity === 'sales' && blank($record->value('payment'))) {
            $this->put($record, ['payment' => $record->value('card') ? 'card' : 'cash']);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'cards') {
            $this->recalculate($this->parent($record, 'card'));
            $this->recalculate($this->previousParent($record, 'card'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity !== 'cards') {
            $this->recalculate($this->parent($record, 'card'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'cards') {
            $methods = $this->app->entities['topups']->field('method')?->options ?? [];

            return match ($record->status) {
                'active' => [
                    'top_up' => ['label' => 'Top up', 'icon' => 'plus-circle', 'fields' => [
                        ['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => ''],
                        ['name' => 'method', 'label' => 'Method', 'type' => 'select', 'options' => $methods, 'value' => 'cash'],
                        ['name' => 'reference', 'label' => 'Reference', 'type' => 'text'],
                    ]],
                    'block' => ['label' => 'Block', 'icon' => 'ban', 'confirm' => 'Block this card? It cannot spend until unblocked.'],
                    'report_lost' => ['label' => 'Report lost', 'icon' => 'alert-triangle', 'confirm' => 'Mark this card lost? The balance stays for a replacement card.'],
                ],
                'blocked' => ['unblock' => ['label' => 'Unblock', 'icon' => 'check']],
                default => [],
            };
        }

        if ($record->entity === 'topups') {
            return $record->status === 'received' ? ['reverse' => ['label' => 'Reverse', 'icon' => 'undo', 'confirm' => 'Reverse this top-up and take it off the card?']] : [];
        }

        return $record->status === 'completed' ? ['refund' => ['label' => 'Refund', 'icon' => 'undo', 'confirm' => 'Refund this sale?']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'top_up':
                $input = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'method' => ['nullable', 'in:cash,mobile_money,bank'], 'reference' => ['nullable', 'string']]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'branch_id' => $record->branch_id, 'blueprint' => $record->blueprint, 'entity' => 'topups',
                    'title' => ($input['reference'] ?? null) ?: 'Top-up '.today()->format('d M'), 'status' => 'received', 'amount' => (float) $input['amount'], 'currency' => $record->currency, 'occurs_on' => today(),
                    'data' => ['card' => $record->id, 'method' => $input['method'] ?? 'cash'],
                ]);

                return 'Topped up '.$this->money($input['amount']).'. Balance is now '.$this->money($this->number($record->fresh(), 'balance')).'.';
            case 'block':
                $record->update(['status' => 'blocked']);

                return 'Card '.$record->value('card_number').' is blocked.';
            case 'unblock':
                $record->update(['status' => 'active']);

                return 'Card '.$record->value('card_number').' is active again.';
            case 'report_lost':
                $record->update(['status' => 'lost']);

                return 'Card '.$record->value('card_number').' reported lost with '.$this->money($this->number($record, 'balance')).' on it.';
            case 'reverse':
                $record->update(['status' => 'reversed']);

                return 'Top-up reversed.';
        }
        if ($record->value('payment') === 'card' && ! $this->parent($record, 'card')) {
            throw ValidationException::withMessages(['data.card' => 'The card for this sale no longer exists.']);
        }
        $record->update(['status' => 'refunded']);

        return 'Sale refunded'.($record->value('payment') === 'card' ? ' to the card.' : ' in cash.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'cards') {
            return [];
        }

        $sales = $this->linked('sales', 'card', $record)->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $limit = (float) $record->value('daily_limit');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Card', 'icon' => 'credit-card', 'stats' => [
                ['label' => 'Balance', 'value' => $this->money($this->number($record, 'balance')), 'tone' => $record->value('_low') ? 'warning' : 'success'],
                ['label' => 'Spent today', 'value' => $this->money($this->number($record, '_spent_today'))],
                ['label' => 'Daily limit', 'value' => $limit > 0 ? $this->money($limit) : 'None'],
                ['label' => 'Left today', 'value' => $limit > 0 ? $this->money(max(0, $limit - $this->number($record, '_spent_today'))) : '—'],
                ['label' => 'Allergies', 'value' => $record->value('allergies') ?: 'None', 'tone' => filled($record->value('allergies')) ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Recent sales', 'icon' => 'shopping-basket', 'empty' => 'Nothing bought yet.',
                'rows' => $sales->take(10)->map(fn (Record $sale) => [
                    'label' => $sale->title, 'sub' => $sale->occurs_on?->format('d M Y'), 'value' => $this->money($sale->amount), 'href' => $sale->url(), 'tone' => $sale->status === 'refunded' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $cards = $this->records('cards')->get();
        $sales = $this->records('sales')->get();
        $today = $sales->filter(fn (Record $sale) => $sale->status === 'completed' && $sale->occurs_on?->isToday());
        $month = $sales->filter(fn (Record $sale) => $sale->status === 'completed' && $sale->occurs_on?->isCurrentMonth());
        $low = $cards->where('status', 'active')->filter(fn (Record $card) => $card->value('_low'))->sortBy(fn (Record $card) => (float) $card->value('balance'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Canteen', 'icon' => 'utensils', 'stats' => [
                ['label' => 'Sales today', 'value' => $this->money($today->sum('amount'))],
                ['label' => 'Sales this month', 'value' => $this->money($month->sum('amount'))],
                ['label' => 'Card sales', 'value' => $month->isEmpty() ? '—' : round($month->where('data.payment', 'card')->count() / $month->count() * 100).'%'],
                ['label' => 'Active cards', 'value' => (string) $cards->where('status', 'active')->count()],
                ['label' => 'On cards', 'value' => $this->money($cards->where('status', '!=', 'lost')->sum(fn (Record $card) => (float) $card->value('balance')))],
                ['label' => 'Low balance', 'value' => (string) $low->count(), 'tone' => $low->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Cards running low', 'icon' => 'credit-card', 'empty' => 'Every card has money on it.',
                'rows' => $low->take(10)->map(fn (Record $card) => [
                    'label' => $card->title, 'sub' => $card->value('card_number'), 'value' => $this->money($this->number($card, 'balance')), 'href' => $card->url(), 'tone' => 'warning',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sales = $this->dated('sales', $from, $to)->get();
        $topups = $this->dated('topups', $from, $to)->get();
        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($sales, $topups) {
            $own = $sales->filter(fn (Record $sale) => $sale->status === 'completed' && $sale->occurs_on?->format('Y-m') === $month);
            $in = $topups->filter(fn (Record $topup) => $topup->status === 'received' && $topup->occurs_on?->format('Y-m') === $month);

            return [$label, $own->count(), $this->money($own->where('data.payment', 'card')->sum('amount')), $this->money($own->where('data.payment', 'cash')->sum('amount')), $this->money($own->sum('amount')), $this->money($in->sum('amount'))];
        })->values()->all();

        $cards = $this->records('cards')->with('contact')->get();
        $byCard = $cards->sortBy('title')->map(fn (Record $card) => [
            $card->title, $card->value('card_number'), ucfirst($card->status), $this->money($this->number($card, 'balance')),
            $this->money($sales->filter(fn (Record $sale) => $sale->status === 'completed' && (int) $sale->value('card') === $card->id)->sum('amount')),
            $this->money($topups->filter(fn (Record $topup) => $topup->status === 'received' && (int) $topup->value('card') === $card->id)->sum('amount')),
        ])->values()->all();

        $methods = $this->app->entities['topups']->field('method')?->options ?? [];
        $byMethod = collect($methods)->map(fn (string $label, string $method) => [
            $label, $topups->where('status', 'received')->where('data.method', $method)->count(), $this->money($topups->where('status', 'received')->where('data.method', $method)->sum('amount')), $this->money($topups->where('status', 'reversed')->where('data.method', $method)->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Sales by month', 'columns' => ['Month', 'Sales', 'Card', 'Cash', 'Total', 'Top-ups'], 'rows' => $byMonth],
            ['title' => 'Cards', 'columns' => ['Student', 'Card', 'Status', 'Balance', 'Spent', 'Topped up'], 'rows' => $byCard],
            ['title' => 'Top-ups by method', 'columns' => ['Method', 'Top-ups', 'Received', 'Reversed'], 'rows' => $byMethod],
        ];
    }
}
