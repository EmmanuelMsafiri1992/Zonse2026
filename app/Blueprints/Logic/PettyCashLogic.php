<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Petty cash: the box can never go below zero, so a payout cannot be more than the cash on hand
 * on its date; every entry needs an amount, payout voucher numbers are unique, and a reconciled
 * entry is locked. A cash count reconciles the book and records any shortage or surplus.
 */
class PettyCashLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $amount = (float) ($payload['amount'] ?? 0);
        $errors = [];

        if ($amount <= 0) {
            $errors['amount'] = 'Enter the amount.';
        }
        if ($existing?->status === 'reconciled' && ((float) $existing->amount !== $amount || $existing->value('direction') !== ($data['direction'] ?? null)
            || $existing->occurs_on?->toDateString() !== ($payload['occurs_on'] ?? null))) {
            $errors['amount'] = 'This entry is reconciled and can no longer be changed.';
        }

        if (($data['direction'] ?? null) === 'out' && $amount > 0) {
            $date = Carbon::parse($payload['occurs_on'] ?? today());
            $available = $this->balance($date, $existing);
            if ($amount - $available > 0.004) {
                $errors['amount'] = 'Only '.$this->money($available).' is in the cash box on '.$date->format('d M Y').'.';
            }
            if (filled($data['voucher'] ?? null) && $this->records('entries')->where('data->voucher', $data['voucher'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.voucher'] = 'Voucher '.$data['voucher'].' is already used.';
            }
        }

        return $errors;
    }

    /** Cash in the box at the end of a day, leaving out one entry (the one being edited). */
    public function balance(?Carbon $asOf = null, ?Record $except = null): float
    {
        $entries = $this->records('entries')->when($asOf, fn ($query) => $query->where(fn ($query) => $query->whereNull('occurs_on')->orWhereDate('occurs_on', '<=', $asOf)))
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))->get();

        return round($entries->sum(fn (Record $entry) => ($entry->value('direction') === 'out' ? -1 : 1) * (float) $entry->amount), 2);
    }

    public function actions(Record $record): array
    {
        return ['count' => ['label' => 'Count the cash', 'icon' => 'calculator', 'fields' => [['name' => 'counted', 'label' => 'Cash counted in the box', 'type' => 'number']]]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $counted = (float) $request->validate(['counted' => ['required', 'numeric', 'min:0']])['counted'];
        $book = $this->balance();
        $difference = round($counted - $book, 2);

        if (abs($difference) > 0.004) {
            Record::create([
                'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'entries', 'title' => $difference < 0 ? 'Cash shortage' : 'Cash surplus', 'status' => 'recorded',
                'amount' => abs($difference), 'occurs_on' => today(), 'data' => ['direction' => $difference < 0 ? 'out' : 'in', 'category' => 'cash count difference'],
            ]);
        }
        $this->records('entries')->where('status', 'recorded')->whereDate('occurs_on', '<=', today())->update(['status' => 'reconciled']);

        return abs($difference) > 0.004
            ? 'Counted '.$this->money($counted).'; the book said '.$this->money($book).'. The '.($difference < 0 ? 'shortage' : 'surplus').' of '.$this->money(abs($difference)).' is recorded.'
            : 'The cash matches the book: '.$this->money($counted).'.';
    }

    public function homeCards(): array
    {
        $month = $this->records('entries')->whereBetween('occurs_on', [today()->startOfMonth(), today()->endOfMonth()])->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Cash box', 'icon' => 'wallet', 'stats' => [
            ['label' => 'Cash on hand', 'value' => $this->money($this->balance())],
            ['label' => 'In this month', 'value' => $this->money($month->where('data.direction', 'in')->sum('amount'))],
            ['label' => 'Out this month', 'value' => $this->money($month->where('data.direction', 'out')->sum('amount'))],
            ['label' => 'Not reconciled', 'value' => (string) $this->records('entries')->where('status', 'recorded')->count()],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $running = $this->balance($from->copy()->subDay());
        $rows = [['', 'Opening balance', '', '', $this->money($running)]];
        foreach ($this->dated('entries', $from, $to)->orderBy('occurs_on')->orderBy('id')->get() as $entry) {
            $in = $entry->value('direction') !== 'out';
            $running += ($in ? 1 : -1) * (float) $entry->amount;
            $rows[] = [$entry->occurs_on?->format('d M Y') ?? '—', $entry->title, $in ? $this->money($entry->amount) : '', $in ? '' : $this->money($entry->amount), $this->money($running)];
        }

        $categories = $this->dated('entries', $from, $to)->get()->where('data.direction', 'out')->groupBy(fn (Record $entry) => (string) ($entry->value('category') ?: 'Not given'))
            ->sortKeys()->map(fn ($group, $category) => [ucfirst($category), $group->count(), $this->money($group->sum('amount'))])->values()->all();

        return [
            ['title' => 'Cash book', 'columns' => ['Date', 'Description', 'In', 'Out', 'Balance'], 'rows' => $rows],
            ['title' => 'Payouts by category', 'columns' => ['Category', 'Entries', 'Amount'], 'rows' => $categories],
        ];
    }
}
