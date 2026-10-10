<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Service marketplace (handymen): providers are vetted, then made active. A request only goes to an active
 * provider of the right trade; matching picks the best-rated active provider with the fewest open jobs. A
 * request moves new → matched → quoted → booked → completed. Completing a job needs its value, takes the
 * provider's commission, and its customer rating (1–5) feeds the provider's average rating.
 */
class ServiceMarketplaceLogic extends AppLogic
{
    /**
     * Request statuses that still have work to do.
     *
     * @var list<string>
     */
    public const OPEN = ['matched', 'quoted', 'booked'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'providers') {
            if (filled($data['rating'] ?? null) && ((float) $data['rating'] < 0 || (float) $data['rating'] > 5)) {
                $errors['data.rating'] = 'Ratings run from 0 to 5.';
            }
            if ((float) ($data['commission_percent'] ?? 0) < 0 || (float) ($data['commission_percent'] ?? 0) > 50) {
                $errors['data.commission_percent'] = 'Commission must be between 0 and 50%.';
            }

            return $errors;
        }
        $provider = filled($data['provider'] ?? null) ? $this->records('providers')->find($data['provider']) : null;
        if ($provider && (int) $provider->id !== (int) $existing?->value('provider')) {
            if ($provider->status !== 'active') {
                $errors['data.provider'] = $provider->title.' is '.$provider->status.', not active.';
            } elseif ($provider->value('trade') !== ($data['trade'] ?? null)) {
                $errors['data.provider'] = $provider->title.' works as '.$this->withArticle((string) $provider->value('trade')).', not '.$this->withArticle((string) ($data['trade'] ?? 'tradesperson')).'.';
            }
        }
        if (! $provider && in_array($payload['status'], [...self::OPEN, 'completed'], true)) {
            $errors['data.provider'] = 'Assign a provider first.';
        }
        if ($payload['status'] === 'completed' && (float) ($payload['amount'] ?? 0) <= 0) {
            $errors['amount'] = 'Give the job value.';
        }
        if (filled($data['customer_rating'] ?? null)) {
            if ($payload['status'] !== 'completed') {
                $errors['data.customer_rating'] = 'Only completed jobs can be rated.';
            } elseif ((float) $data['customer_rating'] < 1 || (float) $data['customer_rating'] > 5) {
                $errors['data.customer_rating'] = 'Rate the job from 1 to 5.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'providers') {
            if ($record->exists) {
                $ratings = $this->linked('requests', 'provider', $record)->where('status', 'completed')->get()
                    ->map(fn (Record $request) => $request->value('customer_rating'))->filter(fn ($rating) => filled($rating));
                if ($ratings->isNotEmpty()) {
                    $this->put($record, ['rating' => round($ratings->avg(), 1), '_ratings' => $ratings->count()]);
                }
            }

            return;
        }
        $record->occurs_on ??= today();
        if ($record->status === 'new' && $record->value('provider')) {
            $record->status = 'matched';
        }
        $commission = $record->status === 'completed' ? $this->number($this->parent($record, 'provider') ?? new Record, 'commission_percent') : 0;
        $this->put($record, ['_commission' => round((float) $record->amount * $commission / 100, 2)]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'requests') {
            $this->parent($record, 'provider')?->save();
            $this->previousParent($record, 'provider')?->save();
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'providers') {
            return match ($record->status) {
                'applied' => ['vet' => ['label' => 'Vetted', 'icon' => 'shield-check']],
                'vetted', 'suspended' => ['activate' => ['label' => 'Activate', 'icon' => 'check']],
                'active' => ['suspend' => ['label' => 'Suspend', 'icon' => 'ban']],
                default => [],
            };
        }
        $complete = ['complete' => ['label' => 'Completed', 'icon' => 'check', 'fields' => [
            ['name' => 'amount', 'label' => 'Job value', 'type' => 'number', 'value' => $record->amount],
            ['name' => 'customer_rating', 'label' => 'Customer rating (1–5)', 'type' => 'number', 'value' => $record->value('customer_rating')],
        ]]];
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x']];

        return match ($record->status) {
            'new' => ['match' => ['label' => 'Find a provider', 'icon' => 'user-search'], ...$cancel],
            'matched' => ['quote' => ['label' => 'Quoted', 'icon' => 'receipt', 'fields' => [['name' => 'amount', 'label' => 'Quote', 'type' => 'number', 'value' => $record->amount]]], ...$complete, ...$cancel],
            'quoted' => ['book' => ['label' => 'Booked', 'icon' => 'calendar-check'], ...$complete, ...$cancel],
            'booked' => [...$complete, ...$cancel],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'vet':
            case 'activate':
            case 'suspend':
                $record->update(['status' => ['vet' => 'vetted', 'activate' => 'active', 'suspend' => 'suspended'][$action]]);

                return $record->title.' is '.$record->status.'.';
            case 'match':
                $provider = $this->bestProvider((string) $record->value('trade'));
                if (! $provider) {
                    throw ValidationException::withMessages(['data.provider' => 'No active '.$record->value('trade').' is available; vet or activate one.']);
                }
                $record->update(['status' => 'matched', 'data' => [...$record->data, 'provider' => $provider->id]]);

                return 'Matched with '.$provider->title.($provider->value('rating') ? ' (rated '.$provider->value('rating').')' : '').'.';
            case 'quote':
                $amount = (float) ($request->validate(['amount' => ['required', 'numeric', 'gt:0']])['amount']);
                $record->update(['status' => 'quoted', 'amount' => $amount]);

                return 'Quoted '.$this->money($amount).'.';
            case 'book':
                $record->update(['status' => 'booked']);

                return 'Booked'.($record->due_on ? ' for '.$record->due_on->format('d M') : '').'.';
            case 'complete':
                $input = $request->validate(['amount' => ['nullable', 'numeric', 'min:0'], 'customer_rating' => ['nullable', 'integer', 'between:1,5']]);
                $amount = (float) ($input['amount'] ?? 0) ?: (float) $record->amount;
                if ($amount <= 0) {
                    throw ValidationException::withMessages(['amount' => 'Give the job value.']);
                }
                $record->update(['status' => 'completed', 'amount' => $amount, 'data' => [...$record->data, 'customer_rating' => $input['customer_rating'] ?? $record->value('customer_rating')]]);

                return 'Job done for '.$this->money($amount).'; commission '.$this->money($this->number($record, '_commission')).'.';
            default:
                $record->update(['status' => 'cancelled']);

                return 'Request cancelled.';
        }
    }

    /**
     * A trade with "a" or "an" in front of it.
     */
    protected function withArticle(string $trade): string
    {
        return (preg_match('/^[aeiou]/i', $trade) ? 'an ' : 'a ').$trade;
    }

    /**
     * The best-rated active provider of a trade, with the fewest open jobs breaking ties.
     */
    protected function bestProvider(string $trade): ?Record
    {
        $open = $this->records('requests')->whereIn('status', self::OPEN)->get()->countBy(fn (Record $request) => (int) $request->value('provider'));

        return $this->records('providers')->where('status', 'active')->get()
            ->filter(fn (Record $provider) => $provider->value('trade') === $trade)
            ->sortBy([fn (Record $a, Record $b) => $this->number($b, 'rating') <=> $this->number($a, 'rating'), fn (Record $a, Record $b) => ($open[$a->id] ?? 0) <=> ($open[$b->id] ?? 0)])
            ->first();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'providers') {
            return [];
        }
        $jobs = $this->linked('requests', 'provider', $record)->orderByDesc('occurs_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => $jobs->where('status', 'completed')->count().' jobs done · '.$this->money($jobs->sum(fn (Record $job) => $this->number($job, '_commission'))).' commission', 'icon' => 'hammer', 'empty' => 'No jobs yet.',
            'rows' => $jobs->map(fn (Record $job) => ['label' => $job->title, 'sub' => ucfirst($job->status).($job->value('customer_rating') ? ' · rated '.$job->value('customer_rating') : ''), 'value' => $this->money($job->amount), 'href' => $job->url()])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $new = $this->records('requests')->where('status', 'new')->oldest('occurs_on')->get();
        $month = $this->records('requests')->where('status', 'completed')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Marketplace', 'icon' => 'hammer', 'stats' => [
                ['label' => 'Waiting for a provider', 'value' => $new->count()],
                ['label' => 'Jobs in progress', 'value' => $this->records('requests')->whereIn('status', self::OPEN)->count()],
                ['label' => 'Commission this month', 'value' => $this->money($month->sum(fn (Record $job) => $this->number($job, '_commission')))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Requests to match', 'icon' => 'user-search', 'empty' => 'Every request has a provider.',
                'rows' => $new->map(fn (Record $job) => ['label' => $job->title, 'sub' => ucfirst((string) $job->value('trade')), 'value' => $job->due_on ? 'needed by '.$job->due_on->format('d M') : '', 'href' => $job->url(), 'tone' => $job->due_on && $job->due_on->lte(today()) ? 'danger' : null])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Completed jobs by trade', 'columns' => ['Trade', 'Jobs', 'Job value', 'Commission', 'Average rating'], 'rows' => $this->dated('requests', $from, $to)->where('status', 'completed')->get()
            ->groupBy(fn (Record $job) => ucfirst((string) $job->value('trade')))->sortKeys()
            ->map(function ($group, string $trade) {
                $ratings = $group->map(fn (Record $job) => $job->value('customer_rating'))->filter(fn ($rating) => filled($rating));

                return [$trade, $group->count(), $this->money($group->sum('amount')), $this->money($group->sum(fn (Record $job) => $this->number($job, '_commission'))), $ratings->isNotEmpty() ? number_format($ratings->avg(), 1) : '—'];
            })->values()->all()]];
    }
}
