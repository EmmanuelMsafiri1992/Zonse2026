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
 * E-procurement: a tender is published with a closing date and a unique number, takes bids only
 * while it is open, and closes by itself after its closing date. Bids are checked for compliance
 * (tax compliance is required), scored out of 100 from their technical, price and preference
 * points, and the tender is awarded to the highest-scoring compliant bid, marking the others as
 * unsuccessful.
 */
class ProcurementLogic extends AppLogic
{
    public const SCORED = ['compliant', 'shortlisted', 'awarded', 'unsuccessful'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'tenders') {
            $number = strtoupper(trim((string) ($data['tender_number'] ?? '')));
            if ($number !== '' && $this->records('tenders')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $tender) => strtoupper(trim((string) $tender->value('tender_number'))) === $number)) {
                $errors['data.tender_number'] = 'Tender '.$number.' already exists.';
            }
            if ($payload['status'] === 'published' && blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'Set the closing date before publishing.';
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The tender closes after it is published.';
            }
            if ((float) ($data['budget'] ?? 0) < 0) {
                $errors['data.budget'] = 'The budget cannot be negative.';
            }

            return $errors;
        }

        $tender = ! empty($data['tender']) ? $this->records('tenders')->find($data['tender']) : null;
        if ($tender && ! $existing && $tender->status !== 'published') {
            $errors['data.tender'] = 'Tender '.$tender->value('tender_number').' is not open for bids.';
        }
        foreach (['technical_score' => 70, 'price_score' => 20, 'preference_points' => 10] as $field => $max) {
            $value = (float) ($data[$field] ?? 0);
            if ($value < 0 || $value > $max) {
                $errors['data.'.$field] = 'Score between 0 and '.$max.'.';
            }
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'The bid price cannot be negative.';
        }
        if (in_array($payload['status'], ['compliant', 'shortlisted', 'awarded'], true) && empty($data['tax_compliant'])) {
            $errors['data.tax_compliant'] = 'A bidder must be tax compliant.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'bids') {
            $total = (float) $record->value('technical_score') + (float) $record->value('price_score') + (float) $record->value('preference_points');
            $this->put($record, ['tax_compliant' => (bool) $record->value('tax_compliant'), '_total_score' => round($total, 2)]);

            return;
        }

        $bids = $record->exists ? $this->linked('bids', 'tender', $record)->get() : collect();
        $scored = $bids->whereIn('status', self::SCORED);
        $winner = $bids->firstWhere('status', 'awarded');
        $this->put($record, [
            'tender_number' => strtoupper(trim((string) $record->value('tender_number'))) ?: null,
            '_bids' => $bids->count(),
            '_compliant' => $scored->count(),
            '_lowest_price' => $scored->isEmpty() ? null : (float) $scored->min('amount'),
            '_top_score' => $scored->isEmpty() ? null : (float) $scored->max(fn (Record $bid) => (float) $bid->value('_total_score')),
            '_winner' => $winner?->title,
            '_award_value' => $winner ? (float) $winner->amount : null,
            '_days_left' => $record->status === 'published' && $record->due_on ? (int) today()->diffInDays($record->due_on, false) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'bids') {
            $this->recalculate($this->parent($record, 'tender'));
            $this->recalculate($this->previousParent($record, 'tender'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'bids') {
            $this->recalculate($this->parent($record, 'tender'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $closed = 0;
        foreach ($this->records('tenders')->where('status', 'published')->whereDate('due_on', '<', today())->get() as $tender) {
            $tender->update(['status' => 'closed']);
            $closed++;
        }

        return $closed;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'bids') {
            return $record->status === 'received'
                ? ['compliant' => ['label' => 'Compliant', 'icon' => 'check'], 'non_compliant' => ['label' => 'Non-compliant', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]]
                : ($record->status === 'compliant' ? ['shortlist' => ['label' => 'Shortlist', 'icon' => 'list-plus']] : []);
        }

        return match ($record->status) {
            'draft' => ['publish' => ['label' => 'Publish', 'icon' => 'megaphone', 'fields' => [['name' => 'due_on', 'label' => 'Closing date', 'type' => 'date', 'value' => today()->addDays(21)->toDateString()]]]],
            'published' => ['close' => ['label' => 'Close', 'icon' => 'lock'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this tender?']],
            'closed' => ['evaluate' => ['label' => 'Start evaluation', 'icon' => 'calculator'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this tender?']],
            'evaluating' => ['award' => ['label' => 'Award to top bid', 'icon' => 'trophy', 'confirm' => 'Award to the highest-scoring compliant bid?'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'confirm' => 'Cancel this tender?']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'compliant':
                if (! $record->value('tax_compliant')) {
                    throw ValidationException::withMessages(['status' => $record->title.' is not tax compliant.']);
                }
                $record->update(['status' => 'compliant']);

                return $record->title.' is compliant.';
            case 'non_compliant':
                $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
                $record->update(['status' => 'non_compliant', 'data' => [...$record->data, '_reason' => $reason]]);

                return $record->title.' is non-compliant: '.$reason.'.';
            case 'shortlist':
                $record->update(['status' => 'shortlisted']);

                return $record->title.' shortlisted.';
            case 'publish':
                $closing = Carbon::parse($request->validate(['due_on' => ['required', 'date', 'after:today']])['due_on']);
                $record->update(['status' => 'published', 'occurs_on' => today(), 'due_on' => $closing]);

                return 'Tender '.$record->value('tender_number').' published; closes '.$closing->format('d M Y').'.';
            case 'close':
                $record->update(['status' => 'closed']);

                return 'Tender '.$record->value('tender_number').' closed with '.(int) $record->fresh()->value('_bids').' bids.';
            case 'evaluate':
                $record->update(['status' => 'evaluating']);

                return 'Evaluating tender '.$record->value('tender_number').'.';
            case 'award':
                $bids = $this->linked('bids', 'tender', $record)->get();
                $winner = $bids->whereIn('status', ['compliant', 'shortlisted'])->filter(fn (Record $bid) => $bid->value('tax_compliant'))
                    ->sortBy([fn (Record $a, Record $b) => (float) $b->value('_total_score') <=> (float) $a->value('_total_score'), fn (Record $a, Record $b) => (float) $a->amount <=> (float) $b->amount])->first();
                if (! $winner) {
                    throw ValidationException::withMessages(['status' => 'No compliant bid to award.']);
                }
                foreach ($bids as $bid) {
                    if ($bid->id === $winner->id) {
                        $bid->update(['status' => 'awarded']);
                    } elseif (in_array($bid->status, ['received', 'compliant', 'shortlisted'], true)) {
                        $bid->update(['status' => 'unsuccessful']);
                    }
                }
                $record->update(['status' => 'awarded']);

                return 'Tender '.$record->value('tender_number').' awarded to '.$winner->title.' with '.rtrim(rtrim(number_format((float) $winner->value('_total_score'), 2, '.', ''), '0'), '.').' points.';
        }

        $record->update(['status' => 'cancelled']);

        return 'Tender '.$record->value('tender_number').' cancelled.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'tenders') {
            return [];
        }

        $bids = $this->linked('bids', 'tender', $record)->get()->sortByDesc(fn (Record $bid) => (float) $bid->value('_total_score'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Tender', 'icon' => 'file-search', 'stats' => [
                ['label' => 'Tender number', 'value' => $record->value('tender_number') ?: '—'],
                ['label' => 'Budget', 'value' => $this->money($this->number($record, 'budget'))],
                ['label' => 'Closes', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_days_left') !== null && (int) $record->value('_days_left') <= 3 ? 'warning' : null],
                ['label' => 'Bids', 'value' => (int) $record->value('_bids').' · '.(int) $record->value('_compliant').' compliant'],
                ['label' => 'Lowest compliant price', 'value' => $record->value('_lowest_price') === null ? '—' : $this->money((float) $record->value('_lowest_price'))],
                ['label' => 'Awarded to', 'value' => $record->value('_winner') ?: '—', 'tone' => $record->value('_award_value') !== null && (float) $record->value('_award_value') > $this->number($record, 'budget') && $this->number($record, 'budget') > 0 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Bid ranking', 'icon' => 'trophy', 'empty' => 'No bids received.',
                'rows' => $bids->take(20)->map(fn (Record $bid) => [
                    'label' => $bid->title, 'sub' => $this->money($bid->amount).' · '.ucfirst(str_replace('_', ' ', $bid->status)), 'value' => (float) $bid->value('_total_score').' pts', 'href' => $bid->url(), 'tone' => $bid->status === 'awarded' ? 'success' : ($bid->status === 'non_compliant' ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $tenders = $this->records('tenders')->get();
        $open = $tenders->where('status', 'published')->sortBy('due_on');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Procurement', 'icon' => 'file-search', 'stats' => [
                ['label' => 'Open tenders', 'value' => (string) $open->count()],
                ['label' => 'Evaluating', 'value' => (string) $tenders->where('status', 'evaluating')->count()],
                ['label' => 'Awarded this year', 'value' => (string) $tenders->filter(fn (Record $tender) => $tender->status === 'awarded' && $tender->occurs_on?->isCurrentYear())->count()],
                ['label' => 'Value awarded this year', 'value' => $this->money($tenders->filter(fn (Record $tender) => $tender->status === 'awarded' && $tender->occurs_on?->isCurrentYear())->sum(fn (Record $tender) => (float) $tender->value('_award_value')))],
                ['label' => 'Bids this month', 'value' => (string) $this->records('bids')->get()->filter(fn (Record $bid) => $bid->occurs_on?->isCurrentMonth())->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Closing soon', 'icon' => 'calendar-clock', 'empty' => 'No tenders are open.',
                'rows' => $open->take(10)->map(fn (Record $tender) => [
                    'label' => $tender->title, 'sub' => ($tender->value('tender_number') ?: '').' · '.(int) $tender->value('_bids').' bids', 'value' => 'Closes '.$tender->due_on?->format('d M Y'), 'href' => $tender->url(), 'tone' => $tender->value('_days_left') !== null && (int) $tender->value('_days_left') <= 3 ? 'warning' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $tenders = $this->dated('tenders', $from, $to)->get();
        $byCategory = $tenders->groupBy(fn (Record $tender) => $tender->value('category') ?: 'Uncategorised')->sortKeys()->map(fn ($group, $category) => [
            $category, $group->count(), $group->where('status', 'awarded')->count(), $this->money($group->sum(fn (Record $tender) => (float) $tender->value('budget'))), $this->money($group->sum(fn (Record $tender) => (float) $tender->value('_award_value'))),
        ])->values()->all();

        $awarded = $tenders->where('status', 'awarded')->map(fn (Record $tender) => [
            $tender->value('tender_number') ?: $tender->title, $tender->title, $tender->value('_winner') ?: '—', (int) $tender->value('_bids'), $this->money($this->number($tender, 'budget')), $this->money((float) $tender->value('_award_value')),
        ])->values()->all();

        $bids = $this->dated('bids', $from, $to)->get();
        $byBidder = $bids->groupBy('title')->sortKeys()->map(fn ($group, $bidder) => [$bidder, $group->count(), $group->where('status', 'awarded')->count(), $group->where('status', 'non_compliant')->count()])->values()->all();

        return [
            ['title' => 'Tenders by category', 'columns' => ['Category', 'Tenders', 'Awarded', 'Budget', 'Awarded value'], 'rows' => $byCategory],
            ['title' => 'Awards', 'columns' => ['Tender', 'Title', 'Awarded to', 'Bids', 'Budget', 'Award'], 'rows' => $awarded],
            ['title' => 'Bidders', 'columns' => ['Bidder', 'Bids', 'Won', 'Non-compliant'], 'rows' => $byBidder],
        ];
    }
}
