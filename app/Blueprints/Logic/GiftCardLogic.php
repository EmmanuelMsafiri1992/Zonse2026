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
 * Gift cards: card codes are kept in capitals and used once. Gift cards and vouchers expire a year after
 * they are issued unless a date is given, while store credit never expires. A card's balance is its face
 * value less the redemptions posted against it, so it becomes redeemed once used up. A redemption needs an
 * active card and can't take more than the balance; reversing one puts the money back. Cards past their
 * date expire each night.
 */
class GiftCardLogic extends AppLogic
{
    public const VALID_MONTHS = 12;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $errors = [];
        if ($entity->key === 'cards') {
            $code = mb_strtoupper(trim((string) $payload['title']));
            if ($code !== '' && $this->records('cards')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $card) => mb_strtoupper(trim($card->title)) === $code)) {
                $errors['title'] = 'Card code '.$code.' is already in use.';
            }
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give the face value.';
            }

            return $errors;
        }
        $data = $payload['data'];
        if ($payload['status'] !== 'posted' || blank($data['card'] ?? null) || ! ($card = $this->records('cards')->find($data['card']))) {
            return $errors;
        }
        $available = $this->number($card, 'balance') + ($existing && $existing->status === 'posted' && (int) $existing->value('card') === $card->id ? (float) $existing->amount : 0);
        if ($card->status !== 'active' && ! ($card->status === 'redeemed' && $available > 0)) {
            $errors['data.card'] = 'Card '.$card->title.' is '.$card->status.'.';
        } elseif ($card->due_on && $card->due_on->lt(today())) {
            $errors['data.card'] = 'Card '.$card->title.' expired on '.$card->due_on->format('d M Y').'.';
        } elseif ((float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Give the amount used.';
        } elseif ((float) $payload['amount'] > $available + 0.001) {
            $errors['amount'] = 'Card '.$card->title.' only has '.$this->money($available).' left.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'redemptions') {
            return;
        }
        $record->title = mb_strtoupper(trim($record->title));
        if ($record->value('type') === 'store_credit') {
            $record->due_on = null;
        } else {
            $record->due_on ??= $record->occurs_on->copy()->addMonthsNoOverflow(self::VALID_MONTHS);
        }
        $used = $record->exists ? (float) $this->linked('redemptions', 'card', $record)->where('status', 'posted')->sum('amount') : 0;
        $balance = round(max(0, (float) $record->amount - $used), 2);
        $this->put($record, ['balance' => $balance]);
        if ($record->status === 'cancelled') {
            return;
        }
        $record->status = match (true) {
            $balance <= 0 => 'redeemed',
            $record->due_on && $record->due_on->lt(today()) => 'expired',
            default => 'active',
        };
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'redemptions') {
            return;
        }
        $this->parent($record, 'card')?->save();
        $this->previousParent($record, 'card')?->save();
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'redemptions') {
            $this->parent($record, 'card')?->save();
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('cards')->where('status', 'active')->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $card) => $card->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'cards' && $record->status === 'active' => [
                'redeem' => ['label' => 'Redeem', 'icon' => 'ticket', 'fields' => [['name' => 'amount', 'label' => 'Amount', 'type' => 'number', 'value' => $this->number($record, 'balance')]]],
                'cancel' => ['label' => 'Cancel card', 'icon' => 'ban'],
            ],
            $record->entity === 'redemptions' && $record->status === 'posted' => ['reverse' => ['label' => 'Reverse', 'icon' => 'undo-2']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'redeem':
                $amount = (float) $request->validate(['amount' => ['required', 'numeric', 'gt:0']], ['amount.required' => 'Give the amount used.'])['amount'];
                if ($amount > $this->number($record, 'balance') + 0.001) {
                    throw ValidationException::withMessages(['amount' => 'Card '.$record->title.' only has '.$this->money($this->number($record, 'balance')).' left.']);
                }
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'redemptions',
                    'title' => $record->title.' '.today()->format('d M'), 'status' => 'posted', 'amount' => $amount, 'occurs_on' => today(), 'data' => ['card' => $record->id],
                ]);
                $record->refresh();

                return $this->money($amount).' taken from '.$record->title.'; '.$this->money($this->number($record, 'balance')).' left.';
            case 'cancel':
                $unused = $this->number($record, 'balance');
                $record->update(['status' => 'cancelled']);

                return 'Card '.$record->title.' cancelled with '.$this->money($unused).' unused.';
            default:
                $record->update(['status' => 'reversed']);
                $card = $this->parent($record, 'card');

                return 'Redemption reversed; '.($card ? 'card '.$card->title.' is back to '.$this->money($this->number($card->fresh(), 'balance')) : 'no card linked').'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'cards') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Balance '.$this->money($this->number($record, 'balance')).' of '.$this->money($record->amount), 'icon' => 'ticket', 'empty' => 'Not used yet.',
            'rows' => $this->linked('redemptions', 'card', $record)->orderByDesc('occurs_on')->get()
                ->map(fn (Record $redemption) => ['label' => $redemption->occurs_on->format('d M Y'), 'sub' => ($redemption->value('branch_name') ?: '—').($redemption->status === 'reversed' ? ' · reversed' : ''), 'value' => $this->money($redemption->amount), 'href' => $redemption->url(), 'tone' => null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $active = $this->records('cards')->where('status', 'active')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Gift cards', 'icon' => 'gift', 'stats' => [
                ['label' => 'Cards in use', 'value' => $active->count()],
                ['label' => 'Owed to holders', 'value' => $this->money($active->sum(fn (Record $card) => $this->number($card, 'balance')))],
                ['label' => 'Redeemed this month', 'value' => $this->money($this->records('redemptions')->where('status', 'posted')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Expiring in 30 days', 'icon' => 'hourglass', 'empty' => 'No cards expire in the next 30 days.',
                'rows' => $active->filter(fn (Record $card) => $card->due_on && $card->due_on->lte(today()->addDays(30)))->sortBy('due_on')
                    ->map(fn (Record $card) => ['label' => $card->title, 'sub' => $this->money($this->number($card, 'balance')).' left', 'value' => $card->due_on->format('d M Y'), 'href' => $card->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $cards = $this->dated('cards', $from, $to)->get();
        $redemptions = $this->dated('redemptions', $from, $to)->where('status', 'posted')->get();

        return [['title' => 'Gift cards by month', 'columns' => ['Month', 'Cards issued', 'Value issued', 'Redeemed', 'Left on expired cards'], 'rows' => collect($this->months($from, $to))
            ->map(function (string $label, string $month) use ($cards, $redemptions) {
                $issued = $cards->filter(fn (Record $card) => $card->occurs_on?->format('Y-m') === $month);

                return [$label, $issued->count(), $this->money($issued->sum('amount')), $this->money($redemptions->filter(fn (Record $redemption) => $redemption->occurs_on?->format('Y-m') === $month)->sum('amount')), $this->money($issued->where('status', 'expired')->sum(fn (Record $card) => $this->number($card, 'balance')))];
            })->values()->all()]];
    }
}
