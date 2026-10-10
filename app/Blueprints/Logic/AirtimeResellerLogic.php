<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Airtime & bundle reseller: each provider's float is the top-ups received less the successful sales, and a
 * sale can't be more than the float left. Airtime and data go to a valid phone number, and an electricity token
 * sale keeps the token number. Commission is worked out at the product's usual rate unless entered, and failed
 * or reversed sales earn none. A sale can be reversed on the day it was made, which returns its float.
 */
class AirtimeResellerLogic extends AppLogic
{
    /**
     * Usual commission rates by product.
     *
     * @var array<string, float>
     */
    public const COMMISSION_RATES = ['airtime' => 0.03, 'data_bundle' => 0.04, 'electricity_token' => 0.015, 'tv_payment' => 0.02, 'voucher' => 0.03];

    /**
     * Float balance below which a provider is flagged for a top-up.
     */
    public const LOW_FLOAT = 500;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key !== 'sales') {
            return [];
        }
        $data = $payload['data'];
        $errors = [];
        $product = $data['product'] ?? null;
        if (in_array($product, ['airtime', 'data_bundle'], true) && ! preg_match('/^\+?\d{9,15}$/', preg_replace('/[\s-]+/', '', (string) $payload['title']))) {
            $errors['title'] = 'Give a valid phone number.';
        }
        if ($payload['status'] === 'successful') {
            if ($product === 'electricity_token' && blank($data['token_reference'] ?? null)) {
                $errors['data.token_reference'] = 'Give the token number for the electricity sale.';
            }
            if (! $existing && filled($data['network'] ?? null) && (float) ($payload['amount'] ?? 0) > ($left = $this->floatLeft((string) $data['network']))) {
                $errors['amount'] = 'Not enough '.trim((string) $data['network']).' float: '.$this->money($left).' left.';
            }
        }

        return $errors;
    }

    /**
     * A provider name for matching.
     */
    protected function provider(?string $name): string
    {
        return strtolower(trim((string) $name));
    }

    /**
     * The float left with a provider.
     */
    protected function floatLeft(string $provider): float
    {
        $provider = $this->provider($provider);
        $received = $this->records('float')->where('status', 'received')->get()->filter(fn (Record $topUp) => $this->provider($topUp->title) === $provider)->sum('amount');
        $sold = $this->records('sales')->where('status', 'successful')->get()->filter(fn (Record $sale) => $this->provider($sale->value('network')) === $provider)->sum('amount');

        return round((float) $received - (float) $sold, 2);
    }

    /**
     * Every provider with float or sales, keyed by its matching name.
     *
     * @return Collection<string, string>
     */
    protected function providers(): Collection
    {
        return $this->records('float')->pluck('title')->merge($this->records('sales')->get()->map(fn (Record $sale) => (string) $sale->value('network')))
            ->map(fn (?string $name) => trim((string) $name))->filter()->unique(fn (string $name) => strtolower($name))
            ->keyBy(fn (string $name) => strtolower($name))->sortKeys();
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'float') {
            $record->title = trim($record->title);

            return;
        }
        $this->put($record, ['network' => trim((string) $record->value('network'))]);
        if ($record->status !== 'successful') {
            $this->put($record, ['commission' => 0]);
        } elseif (blank($record->value('commission'))) {
            $this->put($record, ['commission' => round((float) $record->amount * (self::COMMISSION_RATES[$record->value('product')] ?? 0), 2)]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'float') {
            return $record->status === 'requested' ? ['receive' => ['label' => 'Received', 'icon' => 'check']] : [];
        }

        return $record->status === 'successful' && $record->occurs_on?->isToday() ? ['reverse' => ['label' => 'Reverse', 'icon' => 'undo-2']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'receive') {
            $record->update(['status' => 'received']);

            return $record->title.' float of '.$this->money($record->amount).' received; '.$this->money($this->floatLeft($record->title)).' now available.';
        }
        $record->update(['status' => 'reversed']);

        return 'Sale to '.$record->title.' reversed; '.$this->money($record->amount).' back on '.$record->value('network').' float.';
    }

    public function homeCards(): array
    {
        $today = $this->records('sales')->where('status', 'successful')->whereDate('occurs_on', today()->toDateString())->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Float balances', 'icon' => 'wallet', 'empty' => 'No float topped up yet.',
                'rows' => $this->providers()->map(fn (string $name) => ['label' => $name, 'value' => $this->money($left = $this->floatLeft($name)), 'tone' => $left < self::LOW_FLOAT ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Sales', 'icon' => 'smartphone', 'stats' => [
                ['label' => 'Sales today', 'value' => $today->count()],
                ['label' => 'Sold today', 'value' => $this->money($today->sum('amount'))],
                ['label' => 'Commission this month', 'value' => $this->money($this->records('sales')->where('status', 'successful')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get()->sum(fn (Record $sale) => $this->number($sale, 'commission')))],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sales = $this->dated('sales', $from, $to)->where('status', 'successful')->get();
        $topUps = $this->dated('float', $from, $to)->where('status', 'received')->get();

        return [
            ['title' => 'Sales by product', 'columns' => ['Product', 'Sales', 'Value', 'Commission'], 'rows' => $sales
                ->groupBy(fn (Record $sale) => ucfirst(str_replace('_', ' ', (string) $sale->value('product'))))->sortKeys()
                ->map(fn ($group, string $product) => [$product, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $sale) => $this->number($sale, 'commission')))])
                ->values()->all()],
            ['title' => 'Float by provider', 'columns' => ['Provider', 'Topped up', 'Sold', 'Balance now'], 'rows' => $this->providers()
                ->map(fn (string $name, string $key) => [$name, $this->money($topUps->filter(fn (Record $topUp) => $this->provider($topUp->title) === $key)->sum('amount')), $this->money($sales->filter(fn (Record $sale) => $this->provider($sale->value('network')) === $key)->sum('amount')), $this->money($this->floatLeft($name))])
                ->values()->all()],
        ];
    }
}
