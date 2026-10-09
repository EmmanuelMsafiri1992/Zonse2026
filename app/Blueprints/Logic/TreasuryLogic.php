<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Treasury: investments work out their expected return at simple interest to maturity and
 * turn matured on the day; FX positions carry their local equivalent at the booked rate.
 */
class TreasuryLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'investments' && ! empty($payload['occurs_on']) && ! empty($payload['due_on'])
            && ! Carbon::parse($payload['due_on'])->gt(Carbon::parse($payload['occurs_on']))) {
            return ['due_on' => 'The maturity date must be after the date it was placed.'];
        }

        if ($entity->key === 'fx_positions' && filled($payload['data']['rate'] ?? null) && (float) $payload['data']['rate'] <= 0) {
            return ['data.rate' => 'The exchange rate must be more than zero.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'investments' && $record->occurs_on && $record->due_on && $this->number($record, 'interest_rate') > 0) {
            $days = (int) $record->occurs_on->diffInDays($record->due_on);
            $this->put($record, ['expected_return' => round((float) $record->amount * $this->number($record, 'interest_rate') / 100 * $days / 365, 2)]);
        }

        if ($record->entity === 'investments' && $record->status === 'active' && $record->due_on && $record->due_on->lte(today())) {
            $record->status = 'matured';
        }

        if ($record->entity === 'fx_positions' && $this->number($record, 'rate') > 0) {
            $record->amount = round($this->number($record, 'foreign_amount') * $this->number($record, 'rate'), 2);
        }
    }

    public function daily(Workspace $workspace): int
    {
        $due = $this->records('investments')->where('status', 'active')->whereDate('due_on', '<=', today())->get();
        $due->each(fn (Record $investment) => $investment->save());

        return $due->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'investments') {
            return [];
        }

        $return = $this->number($record, 'expected_return');
        $days = $record->due_on ? (int) today()->diffInDays($record->due_on, false) : null;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Investment', 'icon' => 'vault', 'stats' => [
            ['label' => 'Principal', 'value' => $this->money($record->amount)],
            ['label' => 'Expected return', 'value' => $this->money($return), 'tone' => 'success'],
            ['label' => 'At maturity', 'value' => $this->money((float) $record->amount + $return)],
            ['label' => 'Matures', 'value' => $days === null ? '—' : ($days > 0 ? 'in '.$days.' days' : ($days === 0 ? 'today' : $record->due_on->format('d M Y'))), 'tone' => $days !== null && $days <= 0 && $record->status !== 'redeemed' ? 'warning' : null],
        ], 'note' => 'Simple interest at '.$this->number($record, 'interest_rate').'% a year for the days to maturity.']]];
    }

    public function homeCards(): array
    {
        $active = $this->records('investments')->whereIn('status', ['active', 'matured'])->orderBy('due_on')->get();
        $soon = $active->filter(fn (Record $investment) => $investment->status === 'matured' || ($investment->due_on && $investment->due_on->lte(today()->addDays(30))));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Portfolio', 'icon' => 'vault', 'stats' => [
                ['label' => 'Invested', 'value' => $this->money($active->sum('amount'))],
                ['label' => 'Expected return', 'value' => $this->money($active->sum(fn (Record $investment) => $this->number($investment, 'expected_return')))],
                ['label' => 'Open FX positions', 'value' => $this->money($this->records('fx_positions')->where('status', 'open')->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Maturing within 30 days', 'icon' => 'calendar-clock', 'empty' => 'Nothing matures in the next 30 days.',
                'rows' => $soon->map(fn (Record $investment) => [
                    'label' => $investment->title, 'sub' => $investment->value('institution').' · '.($investment->status === 'matured' ? 'matured, awaiting redemption' : $investment->due_on->format('d M Y')),
                    'value' => $this->money((float) $investment->amount + $this->number($investment, 'expected_return')), 'href' => $investment->url(),
                    'tone' => $investment->status === 'matured' ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $portfolio = $this->records('investments')->whereIn('status', ['active', 'matured'])->orderBy('due_on')->get()->map(fn (Record $investment) => [
            $investment->title, str_replace('_', ' ', (string) $investment->value('type')), $investment->value('institution'), $this->money($investment->amount),
            $this->number($investment, 'interest_rate').'%', $investment->due_on?->format('d M Y') ?? '—', $this->money($investment->value('expected_return')), ucfirst($investment->status),
        ])->all();

        $exposure = $this->records('fx_positions')->where('status', 'open')->get()->groupBy(fn (Record $position) => strtoupper((string) $position->value('currency_code')))
            ->sortKeys()->map(fn ($group, $code) => [$code, number_format($group->sum(fn (Record $position) => $this->number($position, 'foreign_amount')), 2), $this->money($group->sum('amount'))])->values()->all();

        return [
            ['title' => 'Investment portfolio', 'columns' => ['Investment', 'Type', 'Institution', 'Principal', 'Rate', 'Matures', 'Expected return', 'Status'], 'rows' => $portfolio],
            ['title' => 'Open FX exposure', 'columns' => ['Currency', 'Foreign amount', 'Local equivalent'], 'rows' => $exposure],
        ];
    }
}
