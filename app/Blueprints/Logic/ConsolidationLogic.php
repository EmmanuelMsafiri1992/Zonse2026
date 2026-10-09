<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Multi-entity consolidation: inter-company transactions run between two different group
 * companies; a consolidation run eliminates every matched transaction up to its period end,
 * and cannot be made final while anything in the period is still unmatched.
 */
class ConsolidationLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'entities' && filled($data['ownership_percent'] ?? null) && ((float) $data['ownership_percent'] < 0 || (float) $data['ownership_percent'] > 100)) {
            return ['data.ownership_percent' => 'Ownership is a percentage between 0 and 100.'];
        }

        if ($entity->key === 'intercompany' && ! empty($data['from_entity']) && (int) $data['from_entity'] === (int) ($data['to_entity'] ?? 0)) {
            return ['data.to_entity' => 'Pick two different group companies.'];
        }

        if ($entity->key === 'consolidations' && $payload['status'] === 'final') {
            $unmatched = $this->upTo(isset($payload['occurs_on']) ? Carbon::parse($payload['occurs_on']) : today())->where('status', 'recorded')->count();
            if ($unmatched > 0) {
                return ['status' => $unmatched.' inter-company transaction(s) in the period are still unmatched. Match them with the other company before finalising.'];
            }
        }

        return [];
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'consolidations' && $record->status !== 'final') {
            return ['eliminate' => ['label' => 'Eliminate matched transactions', 'icon' => 'calculator', 'confirm' => 'Eliminate every matched inter-company transaction up to the period end?']];
        }

        return [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $count = $this->eliminate($record);

        return $count ? $count.' transaction(s) eliminated.' : 'Nothing matched to eliminate.';
    }

    /** Mark matched transactions up to the period end eliminated by this run. */
    public function eliminate(Record $consolidation): int
    {
        $matched = $this->upTo($consolidation->occurs_on ?? today())->where('status', 'matched')->get();
        $matched->each(fn (Record $line) => $line->update(['status' => 'eliminated', 'data' => array_merge((array) $line->data, ['_consolidation' => $consolidation->id])]));

        $eliminated = $this->records('intercompany')->where('data->_consolidation', $consolidation->id)->get();
        $consolidation->update(['data' => array_merge((array) $consolidation->data, ['_eliminated_count' => $eliminated->count(), '_eliminated_total' => round((float) $eliminated->sum('amount'), 2)])]);

        return $matched->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'consolidations') {
            $lines = $this->upTo($record->occurs_on ?? today())->get();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Inter-company to '.($record->occurs_on ?? today())->format('d M Y'), 'icon' => 'calculator', 'stats' => [
                ['label' => 'Unmatched', 'value' => $lines->where('status', 'recorded')->count().' · '.$this->money($lines->where('status', 'recorded')->sum('amount')), 'tone' => $lines->contains('status', 'recorded') ? 'warning' : null],
                ['label' => 'Matched, to eliminate', 'value' => $lines->where('status', 'matched')->count().' · '.$this->money($lines->where('status', 'matched')->sum('amount'))],
                ['label' => 'Eliminated by this run', 'value' => (int) $record->value('_eliminated_count').' · '.$this->money($record->value('_eliminated_total')), 'tone' => 'success'],
            ]]]];
        }

        if ($record->entity === 'entities') {
            $out = $this->linked('intercompany', 'from_entity', $record)->whereNot('status', 'eliminated')->sum('amount');
            $in = $this->linked('intercompany', 'to_entity', $record)->whereNot('status', 'eliminated')->sum('amount');

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Inter-company', 'icon' => 'arrow-left-right', 'stats' => [
                ['label' => 'To other companies', 'value' => $this->money($out)],
                ['label' => 'From other companies', 'value' => $this->money($in)],
                ['label' => 'Group share', 'value' => ($record->value('ownership_percent') ?? 100).'%'],
            ], 'note' => 'Transactions not yet eliminated.']]];
        }

        return [];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $names = $this->records('entities')->pluck('title', 'id');
        $pairs = $this->upTo($to)->whereNot('status', 'eliminated')->get()
            ->groupBy(fn (Record $line) => ($names[$line->value('from_entity')] ?? '—').' → '.($names[$line->value('to_entity')] ?? '—'))
            ->sortKeys()
            ->map(fn ($group, $pair) => [$pair, $group->where('status', 'recorded')->count(), $this->money($group->where('status', 'recorded')->sum('amount')), $group->where('status', 'matched')->count(), $this->money($group->where('status', 'matched')->sum('amount'))])
            ->values()->all();

        $minority = $this->records('entities')->where('status', 'active')->get()
            ->filter(fn (Record $entity) => filled($entity->value('ownership_percent')) && $this->number($entity, 'ownership_percent') < 100)
            ->map(fn (Record $entity) => [$entity->title, $this->number($entity, 'ownership_percent').'%', (100 - $this->number($entity, 'ownership_percent')).'%'])->values()->all();

        return [
            ['title' => 'Open inter-company balances at '.$to->format('d M Y'), 'columns' => ['From → to', 'Unmatched', 'Unmatched amount', 'Matched', 'Matched amount'], 'rows' => $pairs],
            ['title' => 'Minority interests', 'columns' => ['Company', 'Group share', 'Minority share'], 'rows' => $minority],
        ];
    }

    /** @return Builder<Record> */
    protected function upTo(Carbon $date)
    {
        return $this->records('intercompany')->where(fn ($query) => $query->whereDate('occurs_on', '<=', $date)->orWhereNull('occurs_on'));
    }
}
