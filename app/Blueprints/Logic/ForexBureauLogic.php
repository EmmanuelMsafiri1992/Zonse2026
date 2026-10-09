<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Forex bureau and money transfer: the board's sell rate must be at least its buy rate and a
 * new current rate retires the old one for the same pair; a deal works out its local amount
 * and the margin earned against the board mid-rate, and large deals need the customer's ID;
 * transfers get a collection code and cannot be paid out once cancelled.
 */
class ForexBureauLogic extends AppLogic
{
    /** Deals at or above this local amount need the customer's ID number. */
    public const ID_THRESHOLD = 1000;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'rates' && filled($data['buy_rate'] ?? null) && filled($data['sell_rate'] ?? null) && (float) $data['sell_rate'] < (float) $data['buy_rate']) {
            return ['data.sell_rate' => 'The sell rate cannot be lower than the buy rate.'];
        }

        if ($entity->key === 'deals') {
            $local = (float) ($data['foreign_amount'] ?? 0) * (float) ($data['rate'] ?? 0);
            if ($local >= self::ID_THRESHOLD && blank($data['id_number'] ?? null)) {
                return ['data.id_number' => 'Deals of '.$this->money(self::ID_THRESHOLD).' or more need the customer\'s ID number.'];
            }
        }

        if ($entity->key === 'transfers' && $existing) {
            if ($existing->status === 'cancelled' && $payload['status'] !== 'cancelled') {
                return ['status' => 'This transfer was cancelled. Send a new one instead.'];
            }
            if ($existing->status === 'paid_out' && $payload['status'] !== 'paid_out') {
                return ['status' => 'This transfer has already been paid out.'];
            }
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'deals') {
            $record->amount = round($this->number($record, 'foreign_amount') * $this->number($record, 'rate'), 2);
            $board = $this->boardRate((string) $record->value('currency'));
            $margin = null;
            if ($board) {
                $mid = ($this->number($board, 'buy_rate') + $this->number($board, 'sell_rate')) / 2;
                $spread = $record->value('direction') === 'buy' ? $mid - $this->number($record, 'rate') : $this->number($record, 'rate') - $mid;
                $margin = round($spread * $this->number($record, 'foreign_amount'), 2);
            }
            $this->put($record, ['_margin' => $margin, '_board' => $board?->title]);
        }

        if ($record->entity === 'transfers') {
            if (blank($record->value('secret_code'))) {
                $this->put($record, ['secret_code' => (string) random_int(100000, 999999)]);
            }
            $this->put($record, ['_total' => round((float) $record->amount + $this->number($record, 'fee'), 2)]);
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'rates' && $record->status === 'current') {
            $this->records('rates')->where('status', 'current')->whereKeyNot($record->id)->get()
                ->filter(fn (Record $rate) => $this->pair($rate->title) === $this->pair($record->title))
                ->each(fn (Record $rate) => $rate->update(['status' => 'superseded']));
        }
    }

    /** The current board rate whose pair starts with this currency (USD matches "USD/ZWG"). */
    public function boardRate(string $currency): ?Record
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return null;
        }

        return $this->records('rates')->where('status', 'current')->latest('id')->get()
            ->first(fn (Record $rate) => Str::before($this->pair($rate->title), '/') === $currency);
    }

    protected function pair(string $title): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $title));
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'deals') {
            $margin = $record->value('_margin');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Deal', 'icon' => 'circle-dollar-sign', 'stats' => [
                ['label' => $record->value('direction') === 'buy' ? 'We pay out' : 'We receive', 'value' => $this->money($record->amount)],
                ['label' => 'Board rate', 'value' => (string) ($record->value('_board') ?? 'None set')],
                ['label' => 'Margin', 'value' => $margin === null ? '—' : $this->money($margin), 'tone' => $margin !== null && $margin < 0 ? 'danger' : 'success'],
            ]]]];
        }

        if ($record->entity === 'transfers') {
            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Transfer', 'icon' => 'send', 'stats' => [
                ['label' => 'Amount to pay out', 'value' => $this->money($record->amount)],
                ['label' => 'Fee', 'value' => $this->money($record->value('fee'))],
                ['label' => 'Sender pays', 'value' => $this->money($record->value('_total'))],
                ['label' => 'Collection code', 'value' => (string) $record->value('secret_code')],
            ]]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $rates = $this->records('rates')->where('status', 'current')->orderBy('title')->get();
        $today = $this->records('deals')->where('status', 'completed')->whereDate('occurs_on', today())->get();
        $waiting = $this->records('transfers')->whereIn('status', ['sent', 'ready_for_collection'])->count();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Board rates', 'icon' => 'trending-up', 'empty' => 'No current rates. Add today\'s rates.',
                'rows' => $rates->map(fn (Record $rate) => ['label' => $rate->title, 'value' => 'Buy '.$rate->value('buy_rate').' · Sell '.$rate->value('sell_rate'), 'href' => $rate->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'circle-dollar-sign', 'stats' => [
                ['label' => 'Deals', 'value' => (string) $today->count()],
                ['label' => 'Margin', 'value' => $this->money($today->sum(fn (Record $deal) => $this->number($deal, '_margin')))],
                ['label' => 'Transfers to pay out', 'value' => (string) $waiting, 'tone' => $waiting ? 'warning' : null],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deals = $this->dated('deals', $from, $to)->where('status', 'completed')->get();
        $position = $deals->groupBy(fn (Record $deal) => strtoupper((string) $deal->value('currency')))->sortKeys()->map(function ($group, $currency) {
            $bought = $group->where('data.direction', 'buy');
            $sold = $group->where('data.direction', 'sell');

            return [$currency, number_format($bought->sum(fn (Record $deal) => $this->number($deal, 'foreign_amount')), 2), number_format($sold->sum(fn (Record $deal) => $this->number($deal, 'foreign_amount')), 2),
                number_format($bought->sum(fn (Record $deal) => $this->number($deal, 'foreign_amount')) - $sold->sum(fn (Record $deal) => $this->number($deal, 'foreign_amount')), 2),
                $this->money($group->sum(fn (Record $deal) => $this->number($deal, '_margin')))];
        })->values()->all();

        $transfers = $this->dated('transfers', $from, $to)->whereNot('status', 'cancelled')->get();
        $remittances = $transfers->groupBy(fn (Record $transfer) => (string) ($transfer->value('destination') ?: 'Not given'))->sortKeys()
            ->map(fn ($group, $destination) => [$destination, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $transfer) => $this->number($transfer, 'fee')))])
            ->values()->all();

        return [
            ['title' => 'Currency position', 'columns' => ['Currency', 'Bought', 'Sold', 'Net position', 'Margin'], 'rows' => $position],
            ['title' => 'Transfers by destination', 'columns' => ['Destination', 'Transfers', 'Sent', 'Fees'], 'rows' => $remittances],
        ];
    }
}
