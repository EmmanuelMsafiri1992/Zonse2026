<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tailoring & alterations: a new garment needs a current measurement card, and a new card for a customer
 * marks their older cards outdated. The deposit can't be more than the price, and the fitting must fall on or
 * before the ready-by date. An order moves from cutting and sewing to fitting, ready and collected, and the
 * balance is shown when it is collected.
 */
class TailoringLogic extends AppLogic
{
    /**
     * The next stage of an order, by its current stage.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const NEXT = ['received' => ['cutting', 'Start cutting'], 'cutting' => ['sewing', 'Start sewing'], 'sewing' => ['fitting', 'Ready for fitting'], 'fitting' => ['ready', 'Ready']];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'orders') {
            return $errors;
        }
        $card = filled($data['measurements'] ?? null) ? $this->records('measurements')->find($data['measurements']) : null;
        if (($data['type'] ?? null) === 'new_garment' && ! $card) {
            $errors['data.measurements'] = 'A new garment needs a measurement card.';
        } elseif (! $existing && $card && $card->status !== 'current') {
            $errors['data.measurements'] = $card->title.'\'s measurements are outdated; take new ones first.';
        }
        if ((float) ($data['deposit'] ?? 0) > (float) ($payload['amount'] ?? 0) && (float) ($payload['amount'] ?? 0) > 0) {
            $errors['data.deposit'] = 'The deposit can\'t be more than the price.';
        }
        if (filled($data['fitting_date'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($data['fitting_date'])->gt(Carbon::parse($payload['due_on']))) {
            $errors['data.fitting_date'] = 'The fitting must be on or before the ready-by date.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'measurements' || ! $record->wasRecentlyCreated || $record->status !== 'current') {
            return;
        }
        $this->records('measurements')->where('status', 'current')->whereKeyNot($record->id)
            ->when($record->contact_id, fn ($query) => $query->where('contact_id', $record->contact_id), fn ($query) => $query->where('title', $record->title))
            ->get()->each(fn (Record $card) => $card->update(['status' => 'outdated']));
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'orders') {
            return [];
        }
        if (isset(self::NEXT[$record->status])) {
            return ['next' => ['label' => self::NEXT[$record->status][1], 'icon' => 'arrow-right']];
        }

        return $record->status === 'ready' ? ['collect' => ['label' => 'Collected', 'icon' => 'hand']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'collect') {
            $balance = max(0, (float) $record->amount - $this->number($record, 'deposit'));
            $record->update(['status' => 'collected']);

            return $record->title.' collected'.($balance > 0 ? '; '.$this->money($balance).' balance taken' : '').'.';
        }
        $status = self::NEXT[$record->status][0];
        $record->update(['status' => $status]);

        return $record->title.' is '.match ($status) {
            'cutting' => 'being cut',
            'sewing' => 'being sewn',
            'fitting' => 'ready for fitting'.(filled($record->value('fitting_date')) ? ' on '.Carbon::parse($record->value('fitting_date'))->format('d M Y') : ''),
            default => 'ready for collection',
        }.'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'orders') {
            $deposit = $this->number($record, 'deposit');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Money', 'icon' => 'banknote', 'stats' => [
                ['label' => 'Price', 'value' => $this->money($record->amount)],
                ['label' => 'Deposit', 'value' => $this->money($deposit)],
                ['label' => 'Balance', 'value' => $this->money(max(0, (float) $record->amount - $deposit))],
            ]]]];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Orders on this card', 'icon' => 'scissors', 'empty' => 'No orders yet.',
            'rows' => $this->linked('orders', 'measurements', $record)->orderByDesc('occurs_on')->get()
                ->map(fn (Record $order) => ['label' => $order->title, 'sub' => str_replace('_', ' ', $order->status), 'value' => $this->money($order->amount), 'href' => $order->url()])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('orders')->whereNotIn('status', ['ready', 'collected'])->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Fittings in the next 7 days', 'icon' => 'shirt', 'empty' => 'No fittings coming up.',
                'rows' => $open->filter(fn (Record $order) => filled($order->value('fitting_date')) && Carbon::parse($order->value('fitting_date'))->between(today(), today()->addDays(7)))
                    ->sortBy(fn (Record $order) => $order->value('fitting_date'))
                    ->map(fn (Record $order) => ['label' => $order->title, 'sub' => str_replace('_', ' ', $order->status), 'value' => Carbon::parse($order->value('fitting_date'))->format('d M'), 'href' => $order->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Late orders', 'icon' => 'alarm-clock', 'empty' => 'Nothing late.',
                'rows' => $open->filter(fn (Record $order) => $order->due_on && $order->due_on->lt(today()))->sortBy('due_on')
                    ->map(fn (Record $order) => ['label' => $order->title, 'sub' => str_replace('_', ' ', $order->status), 'value' => 'Due '.$order->due_on->format('d M'), 'href' => $order->url(), 'tone' => 'danger'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Orders by type', 'columns' => ['Type', 'Orders', 'Collected', 'Value', 'Deposits taken'], 'rows' => $this->dated('orders', $from, $to)->get()
            ->groupBy(fn (Record $order) => ucfirst(str_replace('_', ' ', (string) ($order->value('type') ?: 'other'))))->sortKeys()
            ->map(fn ($group, string $type) => [$type, $group->count(), $group->where('status', 'collected')->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $order) => $this->number($order, 'deposit')))])
            ->values()->all()]];
    }
}
