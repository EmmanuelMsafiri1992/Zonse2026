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
 * Gift vouchers: codes are kept in capitals and used once, and a voucher expires three years after it is
 * issued unless a date is given. Its balance is the value less everything redeemed against it, so it goes
 * from active to partly used to redeemed by itself. A redemption needs a live voucher and cannot take more
 * than the balance; reversing one puts the money back. Vouchers past their date expire each night.
 */
class GiftVoucherLogic extends AppLogic
{
    /**
     * Voucher statuses that can still be spent.
     *
     * @var list<string>
     */
    public const LIVE = ['active', 'partly_used'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'vouchers') {
            $code = mb_strtoupper(trim((string) $payload['title']));
            if ($code !== '' && $this->records('vouchers')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $voucher) => mb_strtoupper(trim($voucher->title)) === $code)) {
                $errors['title'] = 'Voucher code '.$code.' is already in use.';
            }
            if (! $existing && max((float) ($payload['amount'] ?? 0), (float) ($data['balance'] ?? 0)) <= 0) {
                $errors['amount'] = 'Give the voucher value.';
            }

            return $errors;
        }
        if ($payload['status'] === 'reversed' || ! filled($data['voucher'] ?? null) || ! ($voucher = $this->records('vouchers')->find($data['voucher']))) {
            return $errors;
        }
        if (! in_array($voucher->status, self::LIVE, true)) {
            $errors['data.voucher'] = 'Voucher '.$voucher->title.' is '.str_replace('_', ' ', $voucher->status).'.';
        } elseif ($voucher->due_on && $voucher->due_on->lt(today())) {
            $errors['data.voucher'] = 'Voucher '.$voucher->title.' expired on '.$voucher->due_on->format('d M Y').'.';
        } else {
            $available = $this->number($voucher, 'balance') + ($existing && $existing->status === 'redeemed' && (int) $existing->value('voucher') === $voucher->id ? (float) $existing->amount : 0);
            if ((float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give the amount used.';
            } elseif ((float) $payload['amount'] > $available + 0.001) {
                $errors['amount'] = 'Voucher '.$voucher->title.' only has '.$this->money($available).' left.';
            }
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
        $record->due_on ??= $record->occurs_on->copy()->addMonthsNoOverflow(36);
        if ((float) $record->amount <= 0) {
            $record->amount = $this->number($record, 'balance');
        }
        $used = $record->exists ? (float) $this->linked('redemptions', 'voucher', $record)->where('status', 'redeemed')->sum('amount') : 0;
        $balance = round(max(0, (float) $record->amount - $used), 2);
        $this->put($record, ['balance' => $balance]);
        if (in_array($record->status, ['void'], true)) {
            return;
        }
        $record->status = match (true) {
            $balance <= 0 => 'redeemed',
            $record->due_on->lt(today()) => 'expired',
            $used > 0 => 'partly_used',
            default => 'active',
        };
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'redemptions') {
            return;
        }
        $this->parent($record, 'voucher')?->save();
        $this->previousParent($record, 'voucher')?->save();
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'redemptions') {
            $this->parent($record, 'voucher')?->save();
        }
    }

    public function daily(Workspace $workspace): int
    {
        return $this->records('vouchers')->whereIn('status', self::LIVE)->whereDate('due_on', '<', today()->toDateString())->get()
            ->each(fn (Record $voucher) => $voucher->save())->count();
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'vouchers' && in_array($record->status, self::LIVE, true) => ['void' => ['label' => 'Void', 'icon' => 'ban']],
            $record->entity === 'redemptions' && $record->status === 'redeemed' => ['reverse' => ['label' => 'Reverse', 'icon' => 'undo-2']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'void') {
            $record->update(['status' => 'void']);

            return 'Voucher '.$record->title.' is void; '.$this->money($this->number($record, 'balance')).' can no longer be spent.';
        }
        $voucher = $this->parent($record, 'voucher');
        if ($voucher && $voucher->status === 'void') {
            throw ValidationException::withMessages(['status' => 'Voucher '.$voucher->title.' is void.']);
        }
        $record->update(['status' => 'reversed']);

        return $this->money($record->amount).' put back on voucher '.($voucher?->fresh()->title ?? '').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'vouchers') {
            return [];
        }
        $uses = $this->linked('redemptions', 'voucher', $record)->orderByDesc('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Used '.$this->money($uses->where('status', 'redeemed')->sum('amount')).' · '.$this->money($this->number($record, 'balance')).' left', 'icon' => 'receipt', 'empty' => 'Not used yet.',
            'rows' => $uses->map(fn (Record $use) => ['label' => $use->occurs_on?->format('d M Y') ?? $use->title, 'sub' => $use->value('till'), 'value' => $this->money($use->amount), 'href' => $use->url(), 'tone' => $use->status === 'reversed' ? 'muted' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $live = $this->records('vouchers')->whereIn('status', self::LIVE)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Vouchers', 'icon' => 'gift', 'stats' => [
            ['label' => 'Live vouchers', 'value' => $live->count()],
            ['label' => 'Owed to holders', 'value' => $this->money($live->sum(fn (Record $voucher) => $this->number($voucher, 'balance')))],
            ['label' => 'Expiring in 30 days', 'value' => $live->filter(fn (Record $voucher) => $voucher->due_on && $voucher->due_on->lte(today()->addDays(30)))->count()],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $issued = $this->dated('vouchers', $from, $to)->get();
        $used = $this->dated('redemptions', $from, $to)->where('status', 'redeemed')->get();
        $expired = $this->records('vouchers')->where('status', 'expired')->whereDate('due_on', '>=', $from->toDateString())->whereDate('due_on', '<=', $to->toDateString())->get();

        return [['title' => 'Voucher money', 'columns' => ['Item', 'Count', 'Amount'], 'rows' => [
            ['Issued', $issued->count(), $this->money($issued->sum('amount'))],
            ['Redeemed', $used->count(), $this->money($used->sum('amount'))],
            ['Expired unspent', $expired->count(), $this->money($expired->sum(fn (Record $voucher) => $this->number($voucher, 'balance')))],
            ['Still owed today', $this->records('vouchers')->whereIn('status', self::LIVE)->count(), $this->money($this->records('vouchers')->whereIn('status', self::LIVE)->get()->sum(fn (Record $voucher) => $this->number($voucher, 'balance')))],
        ]]];
    }
}
