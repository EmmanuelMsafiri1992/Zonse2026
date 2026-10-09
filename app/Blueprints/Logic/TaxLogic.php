<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Support\Carbon;

/**
 * Tax & VAT: a return's payable is its output tax less its input tax, a filed return carries
 * its submission reference, and returns not filed by their deadline turn overdue overnight.
 * Withholding certificates work out the tax from the gross amount and the rate.
 */
class TaxLogic extends AppLogic
{
    public const OPEN = ['due', 'in_progress'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        if ($entity->key === 'returns' && in_array($payload['status'], ['filed', 'paid'], true) && blank($payload['data']['submission_reference'] ?? null)) {
            return ['data.submission_reference' => 'Enter the submission reference the tax authority gave you before marking the return filed.'];
        }

        if ($entity->key === 'withholdings' && ((float) ($payload['data']['rate'] ?? 0) < 0 || (float) ($payload['data']['rate'] ?? 0) > 100)) {
            return ['data.rate' => 'The rate is a percentage between 0 and 100.'];
        }

        return [];
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'returns' && ($record->value('output_tax') !== null || $record->value('input_tax') !== null)) {
            $record->amount = round($this->number($record, 'output_tax') - $this->number($record, 'input_tax'), 2);
        }

        if ($record->entity === 'withholdings' && $record->value('gross_amount') && $record->value('rate')) {
            $record->amount = round($this->number($record, 'gross_amount') * $this->number($record, 'rate') / 100, 2);
        }
    }

    public function daily(Workspace $workspace): int
    {
        $late = $this->records('returns')->whereIn('status', self::OPEN)->whereDate('due_on', '<', today())->get();
        $late->each(fn (Record $return) => $return->update(['status' => 'overdue']));

        return $late->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'returns') {
            return [];
        }

        $payable = (float) $record->amount;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tax for '.($record->value('period') ?: 'this period'), 'icon' => 'percent', 'stats' => [
            ['label' => 'Output tax', 'value' => $this->money($record->value('output_tax'))],
            ['label' => 'Input tax', 'value' => $this->money($record->value('input_tax'))],
            ['label' => $payable < 0 ? 'Refund due' : 'Tax payable', 'value' => $this->money(abs($payable)), 'tone' => $payable < 0 ? 'success' : null],
            ['label' => 'Deadline', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->status === 'overdue' ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $returns = $this->records('returns')->where(fn ($query) => $query->where('status', 'overdue')
            ->orWhere(fn ($query) => $query->whereIn('status', self::OPEN)->whereDate('due_on', '<=', today()->addDays(30))))
            ->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Filing deadlines', 'icon' => 'calendar-clock', 'empty' => 'Nothing due in the next 30 days.',
            'rows' => $returns->map(fn (Record $return) => [
                'label' => $return->title, 'sub' => $return->status === 'overdue' ? 'Overdue since '.$return->due_on?->format('d M Y') : 'Due '.$return->due_on?->format('d M Y'),
                'value' => $this->money($return->amount), 'href' => $return->url(), 'tone' => $return->status === 'overdue' ? 'danger' : 'warning',
            ])->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $returns = $this->records('returns')->whereBetween('due_on', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();
        $types = $this->app->entity('returns')?->field('tax_type')?->options ?? [];
        $byType = $returns->groupBy(fn (Record $return) => (string) $return->value('tax_type'))->map(fn ($group, $type) => [
            $types[$type] ?? ucfirst(str_replace('_', ' ', $type)), $group->count(),
            $this->money($group->sum(fn (Record $return) => $this->number($return, 'output_tax'))),
            $this->money($group->sum(fn (Record $return) => $this->number($return, 'input_tax'))),
            $this->money($group->sum('amount')), $group->where('status', 'overdue')->count(),
        ])->values()->all();

        $withholdings = $this->dated('withholdings', $from, $to)->get();
        $withheld = [];
        foreach (['deducted_by_us' => 'Deducted by us (pay over to the tax authority)', 'deducted_from_us' => 'Deducted from us (claim against our tax)'] as $direction => $label) {
            $group = $withholdings->where('data.direction', $direction);
            $withheld[] = [$label, $group->count(), $this->money($group->sum(fn (Record $line) => $this->number($line, 'gross_amount'))), $this->money($group->sum('amount'))];
        }

        return [
            ['title' => 'Returns by tax type', 'columns' => ['Tax', 'Returns', 'Output tax', 'Input tax', 'Payable', 'Overdue'], 'rows' => $byType, 'note' => 'Returns with a filing deadline in the dates chosen.'],
            ['title' => 'Withholding tax', 'columns' => ['Direction', 'Certificates', 'Gross', 'Tax withheld'], 'rows' => $withheld],
        ];
    }
}
