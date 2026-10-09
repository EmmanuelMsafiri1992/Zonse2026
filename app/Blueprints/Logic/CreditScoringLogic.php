<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Credit scoring: an applicant is only verified once their ID and proof of address are seen;
 * an assessment puts the score into a risk band and, unless a limit is entered, recommends
 * one from the applicant's monthly income. Nobody unverified or with an AML hit is approved.
 */
class CreditScoringLogic extends AppLogic
{
    /** Lowest score for each band, best first, with how many months of income the band may borrow. */
    public const BANDS = [
        'excellent' => ['from' => 750, 'label' => 'Excellent', 'months' => 3],
        'good' => ['from' => 650, 'label' => 'Good', 'months' => 2],
        'fair' => ['from' => 550, 'label' => 'Fair', 'months' => 1],
        'poor' => ['from' => 0, 'label' => 'Poor', 'months' => 0],
    ];

    public const MAX_SCORE = 1000;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if ($entity->key === 'applicants' && $payload['status'] === 'verified' && (empty($data['id_verified']) || empty($data['proof_of_address']))) {
            return ['status' => 'Tick "ID verified" and "Proof of address seen" before marking the applicant verified.'];
        }

        if ($entity->key !== 'assessments') {
            return [];
        }

        $errors = [];
        if (filled($data['score'] ?? null) && ((float) $data['score'] < 0 || (float) $data['score'] > self::MAX_SCORE)) {
            $errors['data.score'] = 'A credit score runs from 0 to '.self::MAX_SCORE.'.';
        }

        if ($payload['status'] === 'approved') {
            $applicant = ! empty($data['applicant']) ? $this->records('applicants')->find($data['applicant']) : null;
            if ($applicant && $applicant->status !== 'verified') {
                $errors['data.applicant'] = $applicant->title.' has not been verified yet. Check their ID and address first.';
            }
            if (($data['aml_result'] ?? null) === 'match') {
                $errors['data.aml_result'] = 'An AML or sanctions match cannot be approved. Decline it.';
            } elseif (($data['aml_result'] ?? null) === 'possible_match') {
                $errors['data.aml_result'] = 'A possible AML match must be cleared first. Refer it for review.';
            } elseif (blank($data['aml_result'] ?? null)) {
                $errors['data.aml_result'] = 'Record the AML / sanctions result before approving.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'assessments' || $record->value('score') === null || $record->value('score') === '') {
            return;
        }

        $band = $this->band($this->number($record, 'score'));
        $this->put($record, ['_band' => $band]);

        if ((float) $record->amount <= 0 && ($applicant = $this->parent($record, 'applicant'))) {
            $record->amount = round($this->number($applicant, 'monthly_income') * self::BANDS[$band]['months'], 2);
        }
    }

    public function band(float $score): string
    {
        foreach (self::BANDS as $band => $rule) {
            if ($score >= $rule['from']) {
                return $band;
            }
        }

        return 'poor';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'assessments' && $record->value('_band')) {
            $band = self::BANDS[$record->value('_band')];

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Risk', 'icon' => 'gauge', 'stats' => [
                ['label' => 'Score', 'value' => (string) $record->value('score')],
                ['label' => 'Band', 'value' => $band['label'], 'tone' => match ($record->value('_band')) {
                    'excellent', 'good' => 'success', 'fair' => 'warning', default => 'danger',
                }],
                ['label' => 'Recommended limit', 'value' => $this->money($record->amount)],
            ], 'note' => 'Limits are '.implode(', ', array_map(fn (array $rule) => $rule['label'].' '.$rule['months'].'×', self::BANDS)).' monthly income unless you enter one.']]];
        }

        if ($record->entity === 'applicants') {
            $assessments = $this->linked('assessments', 'applicant', $record)->latest('id')->get();
            $checks = [
                ['label' => 'ID verified', 'value' => $record->value('id_verified') ? 'Yes' : 'No', 'tone' => $record->value('id_verified') ? 'success' : 'warning'],
                ['label' => 'Proof of address', 'value' => $record->value('proof_of_address') ? 'Seen' : 'Not seen', 'tone' => $record->value('proof_of_address') ? 'success' : 'warning'],
            ];
            if ($latest = $assessments->first()) {
                $checks[] = ['label' => 'Latest score', 'value' => $latest->value('score').' ('.(self::BANDS[$latest->value('_band')]['label'] ?? '—').')'];
                $checks[] = ['label' => 'Latest decision', 'value' => ucfirst(str_replace('_', ' ', $latest->status))];
            }

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Know your customer', 'icon' => 'user-check', 'stats' => $checks]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $waiting = $this->records('assessments')->whereIn('status', ['in_progress', 'referred'])->orderBy('id')->get();
        $names = $this->records('applicants')->pluck('title', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Awaiting a decision', 'icon' => 'gauge', 'empty' => 'No assessments waiting.',
            'rows' => $waiting->take(10)->map(fn (Record $assessment) => [
                'label' => $names[$assessment->value('applicant')] ?? $assessment->title,
                'sub' => $assessment->number.' · score '.$assessment->value('score'),
                'value' => ucfirst(str_replace('_', ' ', $assessment->status)), 'href' => $assessment->url(),
                'tone' => $assessment->status === 'referred' ? 'warning' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $assessments = $this->dated('assessments', $from, $to)->get();
        $rows = [];
        foreach (self::BANDS as $band => $rule) {
            $inBand = $assessments->filter(fn (Record $assessment) => $assessment->value('_band') === $band);
            $decided = $inBand->whereIn('status', ['approved', 'declined']);
            $rows[] = [$rule['label'].' ('.$rule['from'].'+)', $inBand->count(), $inBand->where('status', 'approved')->count(), $inBand->where('status', 'declined')->count(),
                $decided->count() ? round($inBand->where('status', 'approved')->count() / $decided->count() * 100).'%' : '—', $this->money($inBand->where('status', 'approved')->sum('amount'))];
        }

        $aml = $assessments->groupBy(fn (Record $assessment) => (string) ($assessment->value('aml_result') ?: 'not recorded'))
            ->map(fn ($group, $result) => [ucfirst(str_replace('_', ' ', $result)), $group->count()])->values()->all();

        return [
            ['title' => 'Decisions by risk band', 'columns' => ['Band', 'Assessed', 'Approved', 'Declined', 'Approval rate', 'Limits approved'], 'rows' => $rows],
            ['title' => 'AML screening results', 'columns' => ['Result', 'Assessments'], 'rows' => $aml],
        ];
    }
}
