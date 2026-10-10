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
 * Event marketing & promo codes: a promotion runs from its start date to its end date, going live
 * and ending on its own each morning. A code is one word in capitals however it was typed, is unique,
 * can't outlive its promotion, and takes 1–100% or a fixed amount off. Redeeming a code works out the
 * discount on the order, counts the use, adds the sale to the promotion's revenue, and uses the code
 * up once it reaches its limit. Codes stop working when they or their promotion expire.
 */
class PromoCodeLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        return $entity->key === 'promotions' ? $this->validatePromotion($payload) : $this->validateCode($payload, $existing);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validatePromotion(array $payload): array
    {
        $starts = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : null;
        $ends = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : null;
        $errors = [];
        if ($starts && $ends && $ends->lt($starts)) {
            $errors['due_on'] = 'The promotion cannot end before it starts.';
        }
        if ($payload['status'] === 'live') {
            if ($starts && $starts->gt(today())) {
                $errors['status'] = 'It starts on '.$starts->format('d M Y').'.';
            } elseif ($ends && $ends->lt(today())) {
                $errors['status'] = 'It ended on '.$ends->format('d M Y').'; move the end date to run it again.';
            }
        }
        if ((float) ($payload['data']['budget'] ?? 0) < 0) {
            $errors['data.budget'] = 'The budget cannot be negative.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    protected function validateCode(array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $code = $this->code((string) $payload['title']);
        if ($code === '') {
            $errors['title'] = 'Use letters or numbers in the code.';
        } elseif ($this->records('codes')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $other) => $this->code((string) $other->title) === $code)) {
            $errors['title'] = $code.' is already a code.';
        }
        $value = (float) ($data['discount_value'] ?? 0);
        $type = $data['discount_type'] ?? null;
        if ($type === 'percent' && ($value <= 0 || $value > 100)) {
            $errors['data.discount_value'] = 'A percentage discount is between 1 and 100.';
        } elseif ($type === 'fixed_amount' && $value <= 0) {
            $errors['data.discount_value'] = 'Give the amount to take off.';
        }
        $max = filled($data['max_uses'] ?? null) ? (int) $data['max_uses'] : null;
        if ($max !== null && $max < 1) {
            $errors['data.max_uses'] = 'Allow at least one use, or leave it blank for no limit.';
        } elseif ($max !== null && (int) ($data['times_used'] ?? 0) > $max) {
            $errors['data.times_used'] = 'It has been used more than the '.$max.' times allowed.';
        }
        $promotion = filled($data['promotion'] ?? null) ? $this->records('promotions')->find($data['promotion']) : null;
        if ($promotion?->due_on && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->gt($promotion->due_on)) {
            $errors['due_on'] = $promotion->title.' ends on '.$promotion->due_on->format('d M Y').'.';
        }
        if ($payload['status'] === 'active' && $promotion?->status === 'ended') {
            $errors['status'] = $promotion->title.' has ended.';
        }

        return $errors;
    }

    /**
     * A promo code in one form however it was typed.
     */
    protected function code(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'codes') {
            return;
        }
        $record->title = $this->code((string) $record->title);
        if (! $record->due_on && ($promotion = $this->parent($record, 'promotion')) && $promotion->due_on) {
            $record->due_on = $promotion->due_on;
        }
        $max = $record->value('max_uses');
        if ($record->status === 'active' && filled($max) && (int) $record->value('times_used') >= (int) $max) {
            $record->status = 'exhausted';
        }
        if ($record->status === 'exhausted' && filled($max) && (int) $record->value('times_used') < (int) $max) {
            $record->status = 'active';
        }
        if ($record->status === 'active' && $record->due_on && $record->due_on->lt(today())) {
            $record->status = 'expired';
        }
    }

    /**
     * The discount a code takes off an order of this value.
     */
    public function discount(Record $code, float $order): float
    {
        $value = $this->number($code, 'discount_value');

        return match ($code->value('discount_type')) {
            'percent' => round($order * min(100, $value) / 100, 2),
            'fixed_amount' => round(min($value, $order), 2),
            default => 0.0,
        };
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'codes') {
            return $record->status === 'active' ? ['redeem' => ['label' => 'Redeem', 'icon' => 'ticket', 'fields' => [['name' => 'order_value', 'label' => 'Order value', 'type' => 'number']]]] : [];
        }

        return match ($record->status) {
            'planned' => ['launch' => ['label' => 'Go live', 'icon' => 'rocket']],
            'live' => ['end' => ['label' => 'End now', 'icon' => 'square']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'redeem':
                $order = (float) $request->validate(['order_value' => ['required', 'numeric', 'gt:0']])['order_value'];
                if ($record->due_on && $record->due_on->lt(today())) {
                    $record->update(['status' => 'expired']);
                    throw ValidationException::withMessages(['order_value' => $record->title.' expired on '.$record->due_on->format('d M Y').'.']);
                }
                $promotion = $this->parent($record, 'promotion');
                if ($promotion && $promotion->status !== 'live') {
                    throw ValidationException::withMessages(['order_value' => $promotion->title.' is not live.']);
                }
                $discount = $this->discount($record, $order);
                $record->update(['data' => [...$record->data,
                    'times_used' => (int) $record->value('times_used') + 1,
                    '_sales' => round($this->number($record, '_sales') + $order, 2),
                    '_discount_given' => round($this->number($record, '_discount_given') + $discount, 2),
                ]]);
                $promotion?->update(['amount' => round((float) $promotion->amount + $order - $discount, 2)]);
                $applied = $record->value('discount_type') === 'free_item' ? $record->title.' applied: free item with '.$this->money($order).'.' : $record->title.' applied: '.$this->money($discount).' off '.$this->money($order).'.';

                return $applied.($record->status === 'exhausted' ? ' That was the last use.' : '');
            case 'launch':
                if ($record->due_on && $record->due_on->lt(today())) {
                    throw ValidationException::withMessages(['due_on' => 'It ended on '.$record->due_on->format('d M Y').'; move the end date to run it again.']);
                }
                $record->update(['status' => 'live', 'occurs_on' => $record->occurs_on && $record->occurs_on->lte(today()) ? $record->occurs_on : today()]);

                return $record->title.' is live.';
            default:
                $this->end($record, today());

                return $record->title.' ended; its codes no longer work.';
        }
    }

    /**
     * End a promotion and expire its live codes.
     */
    protected function end(Record $promotion, Carbon $on): void
    {
        $promotion->update(['status' => 'ended', 'due_on' => $promotion->due_on && $promotion->due_on->lt($on) ? $promotion->due_on : $on]);
        $this->linked('codes', 'promotion', $promotion)->where('status', 'active')->get()
            ->each(fn (Record $code) => $code->update(['status' => 'expired']));
    }

    public function daily(Workspace $workspace): int
    {
        $changed = 0;
        foreach ($this->records('promotions')->where('status', 'planned')->whereDate('occurs_on', '<=', today()->toDateString())->get() as $promotion) {
            if (! $promotion->due_on || $promotion->due_on->gte(today())) {
                $promotion->update(['status' => 'live']);
                $changed++;
            }
        }
        foreach ($this->records('promotions')->whereIn('status', ['planned', 'live'])->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get() as $promotion) {
            $this->end($promotion, $promotion->due_on);
            $changed++;
        }
        foreach ($this->records('codes')->where('status', 'active')->whereNotNull('due_on')->whereDate('due_on', '<', today()->toDateString())->get() as $code) {
            $code->update(['status' => 'expired']);
            $changed++;
        }

        return $changed;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'promotions') {
            return [];
        }
        $codes = $this->linked('codes', 'promotion', $record)->get();
        $budget = $this->number($record, 'budget');

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Results', 'icon' => 'badge-percent', 'stats' => [
            ['label' => 'Codes redeemed', 'value' => $codes->sum(fn (Record $code) => (int) $code->value('times_used'))],
            ['label' => 'Discount given', 'value' => $this->money($codes->sum(fn (Record $code) => $this->number($code, '_discount_given')))],
            ['label' => 'Revenue', 'value' => $this->money($record->amount)],
            ['label' => 'Return on budget', 'value' => $budget > 0 ? number_format((float) $record->amount / $budget, 1).'×' : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $live = $this->records('promotions')->where('status', 'live')->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Live promotions', 'icon' => 'megaphone', 'empty' => 'Nothing running.',
            'rows' => $live->map(fn (Record $promotion) => [
                'label' => $promotion->title, 'sub' => $this->money($promotion->amount).' revenue',
                'value' => $promotion->due_on ? ((int) today()->diffInDays($promotion->due_on)).' days left' : 'No end date', 'href' => $promotion->url(),
                'tone' => $promotion->due_on && $promotion->due_on->lte(today()->addDays(2)) ? 'warning' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $promotions = $this->dated('promotions', $from, $to)->orderBy('occurs_on')->get();
        $codes = $this->records('codes')->orderBy('title')->get();
        $titles = $this->records('promotions')->pluck('title', 'id');

        return [
            ['title' => 'Promotions', 'columns' => ['Promotion', 'Status', 'Budget', 'Revenue', 'Return on budget'], 'rows' => $promotions->map(fn (Record $promotion) => [
                $promotion->title, ucfirst($promotion->status), $this->money($this->number($promotion, 'budget')), $this->money($promotion->amount),
                $this->number($promotion, 'budget') > 0 ? number_format((float) $promotion->amount / $this->number($promotion, 'budget'), 1).'×' : '—',
            ])->values()->all()],
            ['title' => 'Promo codes', 'columns' => ['Code', 'Promotion', 'Status', 'Uses', 'Limit', 'Sales', 'Discount given'], 'rows' => $codes->map(fn (Record $code) => [
                $code->title, $titles[$code->value('promotion')] ?? '—', ucfirst($code->status), (int) $code->value('times_used'), $code->value('max_uses') ?: 'None',
                $this->money($this->number($code, '_sales')), $this->money($this->number($code, '_discount_given')),
            ])->values()->all()],
        ];
    }
}
