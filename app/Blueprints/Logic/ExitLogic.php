<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Exit / offboarding & clearance: the last working day can't come before notice was given, and the
 * notice period is kept. An exit is cleared only once company assets are back, IT access is removed and
 * finance has signed off; ticking off the last item clears it. Final pay is recorded once cleared.
 */
class ExitLogic extends AppLogic
{
    /**
     * The clearance checklist, by field.
     *
     * @var array<string, string>
     */
    public const CHECKLIST = ['assets_returned' => 'company assets', 'it_access_removed' => 'IT access', 'finance_cleared' => 'finance'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (filled($data['last_day'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['last_day'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['data.last_day'] = 'The last day cannot be before notice was given.';
        }
        if (in_array($payload['status'], ['cleared', 'final_pay_done'], true) && ($outstanding = $this->outstanding($data)) !== []) {
            $errors['status'] = 'Still outstanding: '.implode(', ', $outstanding).'.';
        }
        if ((float) ($payload['amount'] ?? 0) < 0) {
            $errors['amount'] = 'Final pay cannot be negative.';
        }

        return $errors;
    }

    /**
     * Checklist items not yet done.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    protected function outstanding(array $data): array
    {
        return array_values(array_filter(self::CHECKLIST, fn (string $label, string $field) => ! ($data[$field] ?? false), ARRAY_FILTER_USE_BOTH));
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if (filled($record->value('last_day'))) {
            $this->put($record, ['_notice_days' => (int) $record->occurs_on->diffInDays(Carbon::parse($record->value('last_day')))]);
        }
        if ($record->status === 'clearing' && $this->outstanding((array) $record->data) === []) {
            $record->status = 'cleared';
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'notice_given', 'clearing' => ['checklist' => ['label' => 'Clearance', 'icon' => 'list-checks', 'fields' => collect(self::CHECKLIST)
                ->map(fn (string $label, string $field) => ['name' => $field, 'label' => ucfirst($label).' done', 'type' => 'checkbox', 'value' => (bool) $record->value($field)])->values()->all()]],
            'cleared' => ['final_pay' => ['label' => 'Final pay done', 'icon' => 'banknote', 'fields' => [['name' => 'amount', 'label' => 'Final pay', 'type' => 'number', 'value' => $record->amount]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'final_pay') {
            $amount = $request->validate(['amount' => ['nullable', 'numeric', 'min:0']])['amount'] ?? $record->amount;
            if ($amount === null) {
                throw ValidationException::withMessages(['amount' => 'Give the final pay amount.']);
            }
            $record->update(['status' => 'final_pay_done', 'amount' => $amount]);

            return $record->title.'\'s final pay of '.$this->money($amount).' is done.';
        }
        $ticked = collect(self::CHECKLIST)->mapWithKeys(fn (string $label, string $field) => [$field => $request->boolean($field) || (bool) $record->value($field)])->all();
        $record->update(['status' => 'clearing', 'data' => [...$record->data, ...$ticked]]);
        $outstanding = $this->outstanding((array) $record->data);

        return $outstanding === []
            ? $record->title.' is cleared.'
            : 'Clearance for '.$record->title.': '.(count(self::CHECKLIST) - count($outstanding)).' of '.count(self::CHECKLIST).' done; still waiting on '.implode(', ', $outstanding).'.';
    }

    public function homeCards(): array
    {
        $leaving = $this->records('exits')->whereIn('status', ['notice_given', 'clearing'])->get()->sortBy(fn (Record $exit) => (string) $exit->value('last_day'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Leaving', 'icon' => 'log-out', 'empty' => 'Nobody is working notice.',
            'rows' => $leaving->map(function (Record $exit) {
                $outstanding = $this->outstanding((array) $exit->data);
                $lastDay = filled($exit->value('last_day')) ? Carbon::parse($exit->value('last_day')) : null;

                return ['label' => $exit->title, 'sub' => $outstanding === [] ? 'Cleared' : 'Waiting on '.implode(', ', $outstanding),
                    'value' => $lastDay ? 'last day '.$lastDay->format('d M') : '—', 'href' => $exit->url(), 'tone' => $lastDay && $lastDay->lt(today()) && $outstanding !== [] ? 'danger' : null];
            })->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $exits = $this->dated('exits', $from, $to)->get();

        return [['title' => 'Exits by reason', 'columns' => ['Reason', 'Exits', 'Still clearing', 'Average notice (days)', 'Final pay'], 'rows' => $exits
            ->groupBy(fn (Record $exit) => ucfirst(str_replace('_', ' ', (string) $exit->value('reason'))))->sortKeys()
            ->map(fn ($group, string $reason) => [
                $reason, $group->count(), $group->whereIn('status', ['notice_given', 'clearing'])->count(),
                number_format($group->avg(fn (Record $exit) => (int) $exit->value('_notice_days')), 1), $this->money($group->sum(fn (Record $exit) => (float) $exit->amount)),
            ])->values()->all()]];
    }
}
