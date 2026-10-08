<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Models\Record;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Salon: a visit is priced from the service menu, the stylist's commission is worked out
 * as the visit is saved, and the client card shows what the client has spent.
 */
class SalonLogic extends AppLogic
{
    public function saving(Record $record): void
    {
        if ($record->entity !== 'visits') {
            return;
        }

        $service = $record->value('service') ? $this->records('services')->find($record->value('service')) : null;
        if ($service && ($record->amount === null || $record->amount === '') && $service->amount !== null) {
            $record->amount = $service->amount;
        }

        $data = (array) $record->data;
        $percent = (float) ($service?->value('commission_percent') ?? 0);
        $data['_commission'] = Money::round((float) $record->amount * $percent / 100);
        $record->data = $data;
    }

    public function recordCards(Record $record): array
    {
        $client = match ($record->entity) {
            'client_cards' => $record,
            'visits' => $record->related('client'),
            default => null,
        };

        $cards = [];
        if ($client && filled($client->value('allergies'))) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['title' => 'Allergies & sensitivities', 'body' => $client->value('allergies')]];
        }

        if ($record->entity === 'client_cards') {
            $visits = $this->linked('visits', 'client', $record)->where('status', 'completed')->get();
            $favourite = $visits->countBy(fn (Record $visit) => $visit->value('service'))->sortDesc()->keys()->first();
            $last = $visits->sortByDesc(fn (Record $visit) => ($visit->occurs_on ?? $visit->created_at)->timestamp)->first();

            $cards[] = ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Client spend', 'icon' => 'wallet', 'stats' => [
                ['label' => 'Visits', 'value' => (string) $visits->count()],
                ['label' => 'Total spent', 'value' => $this->money($visits->sum('amount') + $visits->sum(fn ($visit) => (float) $visit->value('tip')))],
                ['label' => 'Last visit', 'value' => $last ? ($last->occurs_on ?? $last->created_at)->format('d M Y') : '—'],
                ['label' => 'Favourite service', 'value' => $favourite ? ($this->records('services')->find($favourite)?->title ?? '—') : '—'],
            ]]];
        }

        if ($record->entity === 'visits' && $record->assignee_id) {
            $cards[] = ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Stylist earnings on this visit', 'icon' => 'scissors', 'stats' => [
                ['label' => 'Commission', 'value' => $this->money($record->value('_commission'))],
                ['label' => 'Tip', 'value' => $this->money($record->value('tip'))],
            ]]];
        }

        return $cards;
    }

    public function homeCards(): array
    {
        $today = $this->records('visits')->whereDate('occurs_on', today())->whereNot('status', 'cancelled')->with('assignee')->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Today', 'icon' => 'calendar-check', 'stats' => [
                ['label' => 'Visits', 'value' => (string) $today->where('status', 'completed')->count()],
                ['label' => 'Takings', 'value' => $this->money($today->where('status', 'completed')->sum('amount'))],
                ['label' => 'Tips', 'value' => $this->money($today->sum(fn ($visit) => (float) $visit->value('tip')))],
                ['label' => 'No-shows', 'value' => (string) $today->where('status', 'no_show')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->where('status', 'completed')->with('assignee')->get();
        $services = $this->records('services')->pluck('title', 'id');

        $stylists = $visits->groupBy(fn (Record $visit) => $visit->assignee?->name ?? 'Unassigned')
            ->map(fn ($group, $name) => [
                $name, $group->count(), $this->money($group->sum('amount')),
                $this->money($group->sum(fn ($visit) => (float) $visit->value('_commission'))),
                $this->money($group->sum(fn ($visit) => (float) $visit->value('tip'))),
            ])->sortBy(0)->values()->all();

        $topServices = $visits->groupBy(fn (Record $visit) => $services[$visit->value('service')] ?? 'Other')
            ->map(fn ($group, $name) => [$name, $group->count(), $this->money($group->sum('amount'))])
            ->sortByDesc(1)->take(10)->values()->all();

        return [
            ['title' => 'Commission by stylist', 'columns' => ['Stylist', 'Visits', 'Takings', 'Commission', 'Tips'], 'rows' => $stylists,
                'note' => 'Commission is the visit total times the service\'s commission %, worked out when the visit was saved.'],
            ['title' => 'Top services', 'columns' => ['Service', 'Visits', 'Takings'], 'rows' => $topServices],
        ];
    }
}
