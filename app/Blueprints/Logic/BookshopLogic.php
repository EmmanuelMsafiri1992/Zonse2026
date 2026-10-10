<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Bookshop & stationery: a book's ISBN-10 or ISBN-13 must pass its check digit, and each ISBN or barcode
 * is used once. Stock status follows stock (low at 3 or fewer), and a title on order stays on order until
 * stock arrives. A special order is expected two weeks after ordering, its deposit cannot exceed the
 * total, and it moves ordered → arrived → collected.
 */
class BookshopLogic extends AppLogic
{
    public const LOW_STOCK = 3;

    /**
     * Strip spaces and dashes from an ISBN.
     */
    public static function clean(?string $isbn): string
    {
        return strtoupper(str_replace([' ', '-'], '', trim((string) $isbn)));
    }

    /**
     * Whether an ISBN-10 or ISBN-13 passes its check digit; other barcodes are not checked.
     */
    public static function validIsbn(string $isbn): bool
    {
        if (preg_match('/^\d{9}[\dX]$/', $isbn)) {
            $sum = 0;
            foreach (str_split($isbn) as $position => $character) {
                $sum += ($character === 'X' ? 10 : (int) $character) * (10 - $position);
            }

            return $sum % 11 === 0;
        }
        if (preg_match('/^97[89]\d{10}$/', $isbn)) {
            $sum = 0;
            foreach (str_split($isbn) as $position => $digit) {
                $sum += (int) $digit * ($position % 2 ? 3 : 1);
            }

            return $sum % 10 === 0;
        }

        return true;
    }

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'titles') {
            $isbn = self::clean($data['isbn'] ?? null);
            if ($isbn !== '' && ! self::validIsbn($isbn)) {
                $errors['data.isbn'] = $isbn.' is not a valid ISBN; check the number.';
            } elseif ($isbn !== '' && ($twin = $this->records('titles')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $title) => self::clean($title->value('isbn')) === $isbn))) {
                $errors['data.isbn'] = $twin->title.' already uses '.$isbn.'.';
            }

            return $errors;
        }
        if ((float) ($data['deposit'] ?? 0) > (float) ($payload['amount'] ?? 0)) {
            $errors['data.deposit'] = 'The deposit cannot be more than the total.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'titles') {
            $this->put($record, ['isbn' => self::clean($record->value('isbn')) ?: null]);
            $stock = $this->number($record, 'stock');
            if ($record->status === 'on_order' && $stock <= 0) {
                return;
            }
            $record->status = $stock <= 0 ? 'out_of_stock' : ($stock <= self::LOW_STOCK ? 'low_stock' : 'in_stock');

            return;
        }
        $record->occurs_on ??= today();
        $record->due_on ??= $record->occurs_on->copy()->addDays(14);
        $this->put($record, ['_balance' => round(max(0, (float) $record->amount - $this->number($record, 'deposit')), 2)]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'orders') {
            return [];
        }

        return match ($record->status) {
            'ordered' => ['arrived' => ['label' => 'Arrived', 'icon' => 'package'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'arrived' => ['collected' => ['label' => 'Collected', 'icon' => 'check'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $balance = $this->number($record, '_balance');
        $deposit = $this->number($record, 'deposit');

        return match ($action) {
            'arrived' => tap('Order for '.$record->title.' has arrived; '.$this->money($balance).' to pay on collection.', fn () => $record->update(['status' => 'arrived'])),
            'collected' => tap($record->title.' collected the order and paid '.$this->money($balance).'.', fn () => $record->update(['status' => 'collected'])),
            default => tap('Order for '.$record->title.' cancelled'.($deposit > 0 ? '; refund the '.$this->money($deposit).' deposit' : '').'.', fn () => $record->update(['status' => 'cancelled'])),
        };
    }

    public function homeCards(): array
    {
        $waiting = $this->records('orders')->where('status', 'arrived')->orderBy('due_on')->get();
        $late = $this->records('orders')->where('status', 'ordered')->whereDate('due_on', '<', today()->toDateString())->count();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Waiting to be collected'.($late ? ' · '.$late.' '.str('order')->plural($late).' late from suppliers' : ''), 'icon' => 'clipboard-list', 'empty' => 'No special orders are waiting.',
            'rows' => $waiting->map(fn (Record $order) => ['label' => $order->title, 'sub' => $order->value('school'), 'value' => $this->money($this->number($order, '_balance')).' to pay', 'href' => $order->url()])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Special orders by school', 'columns' => ['School', 'Orders', 'Collected', 'Value', 'Deposits held'], 'rows' => $this->dated('orders', $from, $to)->get()
            ->groupBy(fn (Record $order) => (string) ($order->value('school') ?: 'No school'))->sortKeys()
            ->map(fn ($group, string $school) => [$school, $group->count(), $group->where('status', 'collected')->count(), $this->money($group->where('status', '!=', 'cancelled')->sum('amount')),
                $this->money($group->whereIn('status', ['ordered', 'arrived'])->sum(fn (Record $order) => $this->number($order, 'deposit')))])
            ->values()->all()]];
    }
}
