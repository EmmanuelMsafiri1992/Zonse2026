<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Property listings & agents: a listing goes on the market only with an asking price, a sole mandate has
 * an expiry date, a sale ends as sold and a rental as let. Viewings are booked only for listings on the
 * market or under offer, and one agent-time slot per listing. Each listing shows its viewings, and the
 * home page warns of mandates about to expire.
 */
class PropertyListingsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'listings') {
            if (in_array($payload['status'], ['on_market', 'under_offer'], true) && (float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Set the asking price before the listing goes on the market.';
            }
            if (($data['mandate'] ?? null) === 'sole' && blank($payload['due_on'] ?? null)) {
                $errors['due_on'] = 'A sole mandate needs an expiry date.';
            }
            if ($payload['status'] === 'sold' && ($data['deal'] ?? null) === 'rent' || $payload['status'] === 'let' && ($data['deal'] ?? null) === 'sale') {
                $errors['status'] = ($data['deal'] ?? null) === 'rent' ? 'A rental is let, not sold.' : 'A sale is sold, not let.';
            }
        }
        if ($entity->key === 'viewings' && filled($data['listing'] ?? null)) {
            $listing = $this->records('listings')->find($data['listing']);
            if ($listing && ! $existing && ! in_array($listing->status, ['on_market', 'under_offer'], true)) {
                $errors['data.listing'] = $listing->title.' is not on the market.';
            }
            $clash = filled($data['time'] ?? null) && filled($payload['occurs_on'] ?? null) ? $this->linked('viewings', 'listing', (int) $data['listing'])
                ->whereIn('status', ['booked'])->whereDate('occurs_on', Carbon::parse($payload['occurs_on'])->toDateString())->where('data->time', $data['time'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first() : null;
            if ($clash) {
                $errors['data.time'] = $clash->title.' is already viewing at '.$data['time'].'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'listings') {
            $size = $this->number($record, 'size_m2');
            $this->put($record, ['_per_m2' => $size > 0 && (float) $record->amount > 0 ? round((float) $record->amount / $size, 2) : null]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'viewings') {
            return $record->status === 'booked' ? ['done' => ['label' => 'Viewed', 'icon' => 'check', 'fields' => [['name' => 'feedback', 'label' => 'Feedback', 'type' => 'textarea']]], 'no_show' => ['label' => 'No show', 'icon' => 'user-x']] : [];
        }
        $close = $record->value('deal') === 'rent' ? ['let' => ['label' => 'Let', 'icon' => 'key']] : ['sold' => ['label' => 'Sold', 'icon' => 'badge-check']];

        return match ($record->status) {
            'draft' => ['publish' => ['label' => 'Put on the market', 'icon' => 'megaphone']],
            'on_market' => ['offer' => ['label' => 'Under offer', 'icon' => 'handshake'], ...$close, 'withdraw' => ['label' => 'Withdraw', 'icon' => 'x']],
            'under_offer' => [...$close, 'publish' => ['label' => 'Back on the market', 'icon' => 'megaphone']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'viewings') {
            $feedback = trim((string) ($request->validate(['feedback' => ['nullable', 'string', 'max:5000']])['feedback'] ?? ''));
            $record->update(['status' => $action === 'done' ? 'done' : 'no_show', 'data' => [...$record->data, 'feedback' => $feedback ?: $record->value('feedback')]]);

            return $action === 'done' ? $record->title.' viewed '.($this->parent($record, 'listing')?->title ?? 'the listing').'.' : $record->title.' did not show.';
        }
        $status = ['publish' => 'on_market', 'offer' => 'under_offer', 'sold' => 'sold', 'let' => 'let', 'withdraw' => 'withdrawn'][$action];
        if ($status === 'on_market' && (float) $record->amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Set the asking price before the listing goes on the market.']);
        }
        $record->update(['status' => $status]);
        if (in_array($status, ['sold', 'let', 'withdrawn'], true)) {
            $this->linked('viewings', 'listing', $record)->where('status', 'booked')->get()->each(fn (Record $viewing) => $viewing->update(['status' => 'cancelled']));
        }

        return $record->title.' is '.str_replace('_', ' ', $status).'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'listings') {
            return [];
        }
        $viewings = $this->linked('viewings', 'listing', $record)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Viewings', 'icon' => 'eye', 'stats' => [
            ['label' => 'Booked', 'value' => $viewings->where('status', 'booked')->count()],
            ['label' => 'Viewed', 'value' => $viewings->where('status', 'done')->count()],
            ['label' => 'No shows', 'value' => $viewings->where('status', 'no_show')->count()],
            ['label' => 'Days on market', 'value' => $record->occurs_on ? (int) $record->occurs_on->diffInDays(today()) : '—'],
            ['label' => 'Per m²', 'value' => $record->value('_per_m2') ? $this->money($this->number($record, '_per_m2')) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $expiring = $this->records('listings')->whereIn('status', ['draft', 'on_market', 'under_offer'])->whereNotNull('due_on')->whereDate('due_on', '<=', today()->addDays(30)->toDateString())->orderBy('due_on')->get();
        $viewings = $this->records('viewings')->where('status', 'booked')->whereDate('occurs_on', today()->toDateString())->get()->sortBy(fn (Record $viewing) => (string) $viewing->value('time'));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Viewings today', 'icon' => 'eye', 'empty' => 'No viewings today.',
                'rows' => $viewings->map(fn (Record $viewing) => ['label' => $viewing->title, 'sub' => $this->parent($viewing, 'listing')?->title, 'value' => $viewing->value('time'), 'href' => $viewing->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Mandates expiring', 'icon' => 'file-clock', 'empty' => 'No mandates expire in the next 30 days.',
                'rows' => $expiring->map(fn (Record $listing) => ['label' => $listing->title, 'sub' => ucfirst((string) $listing->value('mandate')), 'value' => $listing->due_on->format('d M'), 'href' => $listing->url(), 'tone' => $listing->due_on->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $listings = $this->dated('listings', $from, $to)->get();
        $viewings = $this->records('viewings')->get()->groupBy(fn (Record $viewing) => (int) $viewing->value('listing'));

        return [['title' => 'Listings', 'columns' => ['Listing', 'Deal', 'Status', 'Asking', 'Viewings', 'Days on market'], 'rows' => $listings->sortBy('title')
            ->map(fn (Record $listing) => [$listing->title, ucfirst((string) $listing->value('deal')), ucfirst(str_replace('_', ' ', $listing->status)), $this->money($listing->amount),
                ($viewings[$listing->id] ?? collect())->where('status', 'done')->count(), $listing->occurs_on ? (int) $listing->occurs_on->diffInDays(today()) : '—'])
            ->values()->all()]];
    }
}
