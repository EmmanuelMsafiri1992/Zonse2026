<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Mortgage / home-loan origination: the loan defaults to the purchase price less the deposit and can't be
 * more than that. Each application works out the monthly repayment, the loan-to-value and how much of
 * the household income the repayment takes. An application is submitted only with a rate and term, and
 * approved only when the repayment is at most 30% of income. It moves forward one stage at a time.
 */
class HomeLoanLogic extends AppLogic
{
    public const MAX_REPAYMENT_SHARE = 30;

    /**
     * @var array<string, string>
     */
    public const NEXT_STAGE = ['enquiry' => 'documents', 'documents' => 'submitted', 'submitted' => 'valuation', 'valuation' => 'approved', 'approved' => 'registered'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $price = (float) ($data['purchase_price'] ?? 0);
        $deposit = (float) ($data['deposit'] ?? 0);
        $loan = (float) ($payload['amount'] ?? 0) ?: max(0, $price - $deposit);
        if ($price > 0 && $deposit > $price) {
            $errors['data.deposit'] = 'The deposit cannot be more than the purchase price.';
        } elseif ($price > 0 && $loan > $price - $deposit) {
            $errors['amount'] = 'The loan cannot be more than the price less the deposit ('.$this->money($price - $deposit).').';
        }
        $later = ['submitted', 'valuation', 'approved', 'registered'];
        if (in_array($payload['status'], $later, true) && ((float) ($data['interest_rate'] ?? 0) <= 0 || (float) ($data['term_years'] ?? 0) <= 0)) {
            $errors['data.interest_rate'] = 'Give the interest rate and term before submitting.';
        }
        if (in_array($payload['status'], ['approved', 'registered'], true) && ! isset($errors['data.interest_rate'])) {
            $share = $this->share($this->repayment($loan, (float) $data['interest_rate'], (float) $data['term_years']), (float) ($data['household_income'] ?? 0));
            if ($share === null || $share > self::MAX_REPAYMENT_SHARE) {
                $errors['data.household_income'] = $share === null ? 'Give the household income.' : 'The repayment takes '.$share.'% of income; the most allowed is '.self::MAX_REPAYMENT_SHARE.'%.';
            }
        }

        return $errors;
    }

    /**
     * The monthly repayment on an amortising loan.
     */
    public function repayment(float $loan, float $ratePercent, float $years): float
    {
        $months = (int) round($years * 12);
        if ($loan <= 0 || $months <= 0) {
            return 0;
        }
        $rate = $ratePercent / 100 / 12;

        return round($rate > 0 ? $loan * $rate / (1 - (1 + $rate) ** -$months) : $loan / $months, 2);
    }

    protected function share(float $repayment, float $income): ?float
    {
        return $income > 0 ? round($repayment / $income * 100, 1) : null;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $price = $this->number($record, 'purchase_price');
        if ((float) $record->amount <= 0 && $price > 0) {
            $record->amount = max(0, $price - $this->number($record, 'deposit'));
        }
        $repayment = $this->repayment((float) $record->amount, $this->number($record, 'interest_rate'), $this->number($record, 'term_years'));
        $this->put($record, [
            '_repayment' => $repayment ?: null,
            '_ltv' => $price > 0 ? round((float) $record->amount / $price * 100, 1) : null,
            '_share' => $repayment ? $this->share($repayment, $this->number($record, 'household_income')) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        $next = self::NEXT_STAGE[$record->status] ?? null;

        return $next ? array_filter([
            'advance' => ['label' => 'Move to '.$next, 'icon' => 'arrow-right'],
            'decline' => $record->status !== 'approved' ? ['label' => 'Declined', 'icon' => 'x'] : null,
        ]) : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'decline') {
            $record->update(['status' => 'declined']);

            return $record->title.'\'s application declined.';
        }
        $next = self::NEXT_STAGE[$record->status];
        $errors = $this->validate($this->app()->entity($record->entity), ['status' => $next, 'amount' => $record->amount, 'data' => (array) $record->data], $record);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $record->update(['status' => $next]);

        return $record->title.'\'s application is now at '.$next.'.';
    }

    public function recordCards(Record $record): array
    {
        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Affordability', 'icon' => 'landmark', 'stats' => [
            ['label' => 'Monthly repayment', 'value' => $record->value('_repayment') ? $this->money($this->number($record, '_repayment')) : '—'],
            ['label' => 'Loan to value', 'value' => $record->value('_ltv') !== null ? $record->value('_ltv').'%' : '—'],
            ['label' => 'Share of income', 'value' => $record->value('_share') !== null ? $record->value('_share').'%' : '—', 'tone' => $record->value('_share') > self::MAX_REPAYMENT_SHARE ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $applications = $this->records('applications')->whereNotIn('status', ['declined', 'registered'])->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Pipeline ('.$this->money($applications->sum(fn (Record $application) => (float) $application->amount)).')', 'icon' => 'landmark', 'empty' => 'No applications in progress.',
            'rows' => collect(array_keys(self::NEXT_STAGE))->map(fn (string $stage) => ['label' => ucfirst($stage), 'value' => $applications->where('status', $stage)->count().' · '.$this->money($applications->where('status', $stage)->sum(fn (Record $application) => (float) $application->amount))])->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $applications = $this->dated('applications', $from, $to)->get();

        return [['title' => 'Applications by lender', 'columns' => ['Lender', 'Applications', 'Approved or registered', 'Declined', 'Approval rate', 'Value approved'], 'rows' => $applications
            ->groupBy(fn (Record $application) => (string) ($application->value('lender') ?: 'Not chosen'))->sortKeys()
            ->map(function ($group, string $lender) {
                $approved = $group->whereIn('status', ['approved', 'registered']);
                $decided = $approved->count() + $group->where('status', 'declined')->count();

                return [$lender, $group->count(), $approved->count(), $group->where('status', 'declined')->count(), $decided > 0 ? round($approved->count() / $decided * 100).'%' : '—', $this->money($approved->sum(fn (Record $application) => (float) $application->amount))];
            })->values()->all()]];
    }
}
