<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Dairy collection: a farmer delivers once per session a day, fat must be a believable
 * percentage, a delivery without a value is priced at the farmer's last price per litre,
 * rejected milk is worth nothing, and each farmer gets a monthly payout statement.
 */
class DairyLogic extends AppLogic
{
    public const MAX_FAT = 15;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if (filled($data['fat_percent'] ?? null) && ((float) $data['fat_percent'] <= 0 || (float) $data['fat_percent'] > self::MAX_FAT)) {
            $errors['data.fat_percent'] = 'Butterfat is between 0 and '.self::MAX_FAT.'%.';
        }
        if ((float) ($data['litres'] ?? 0) <= 0) {
            $errors['data.litres'] = 'Enter the litres delivered.';
        }

        if (filled($payload['occurs_on'] ?? null) && filled($data['session'] ?? null)) {
            $duplicate = $this->records('deliveries')->whereDate('occurs_on', $payload['occurs_on'])->where('data->session', $data['session'])
                ->when($payload['contact_id'] ?? null, fn ($query, $contact) => $query->where('contact_id', $contact), fn ($query) => $query->where('title', $payload['title']))
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
            if ($duplicate) {
                $errors['data.session'] = $payload['title'].' already delivered that '.$data['session'].'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'deliveries') {
            return;
        }

        $litres = $this->number($record, 'litres');
        if ($record->status === 'rejected') {
            $record->amount = 0;
        } elseif ((float) $record->amount <= 0 && $litres > 0 && ($price = $this->lastPrice($record))) {
            $record->amount = round($litres * $price, 2);
        }

        $this->put($record, ['_price_per_litre' => $litres > 0 && (float) $record->amount > 0 ? round((float) $record->amount / $litres, 4) : null]);
    }

    /** The price per litre on the same farmer's latest priced delivery. */
    public function lastPrice(Record $delivery): ?float
    {
        $previous = $this->farmerDeliveries($delivery)->where('status', 'accepted')->where('amount', '>', 0)
            ->when($delivery->exists, fn ($query) => $query->whereKeyNot($delivery->id))->latest('occurs_on')->latest('id')->first();

        return $previous?->value('_price_per_litre') ? (float) $previous->value('_price_per_litre') : null;
    }

    protected function farmerDeliveries(Record $delivery)
    {
        return $delivery->contact_id ? $this->records('deliveries')->where('contact_id', $delivery->contact_id) : $this->records('deliveries')->where('title', $delivery->title);
    }

    public function documents(Record $record): array
    {
        return ['payout' => 'Monthly payout statement'];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'payout') {
            return null;
        }

        $month = ($record->occurs_on ?? $record->created_at)->copy()->startOfMonth();
        $deliveries = $this->farmerDeliveries($record)->whereBetween('occurs_on', [$month, $month->copy()->endOfMonth()])->orderBy('occurs_on')->orderBy('id')->get();
        $accepted = $deliveries->where('status', 'accepted');
        $litres = $accepted->sum(fn (Record $delivery) => $this->number($delivery, 'litres'));
        $fat = $accepted->filter(fn (Record $delivery) => filled($delivery->value('fat_percent')));

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Milk payout — '.$month->format('F Y'),
            'meta' => array_filter(['Farmer' => $record->contact?->name ?? $record->title, 'Statement date' => today()->format('d M Y')]),
            'columns' => ['Date', 'Session', 'Litres', 'Fat %', 'Status', 'Value'],
            'rows' => $deliveries->map(fn (Record $delivery) => [$delivery->occurs_on?->format('d M') ?? '—', ucfirst((string) $delivery->value('session')), $this->number($delivery, 'litres'),
                $delivery->value('fat_percent') ?? '—', ucfirst($delivery->status), $this->money($delivery->amount)])->values()->all(),
            'totals' => [
                'Litres accepted' => number_format($litres, 1),
                'Average fat' => $fat->count() ? round($fat->avg(fn (Record $delivery) => $this->number($delivery, 'fat_percent')), 2).'%' : '—',
                'Amount payable' => $this->money($accepted->sum('amount')),
            ],
        ]];
    }

    public function homeCards(): array
    {
        $today = $this->records('deliveries')->whereDate('occurs_on', today())->where('status', 'accepted')->get();
        $month = $this->records('deliveries')->whereBetween('occurs_on', [today()->startOfMonth(), today()->endOfMonth()])->where('status', 'accepted')->get();
        $litres = fn ($deliveries) => number_format($deliveries->sum(fn (Record $delivery) => $this->number($delivery, 'litres')), 1).' L';

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Milk intake', 'icon' => 'milk', 'stats' => [
            ['label' => 'Morning', 'value' => $litres($today->where('data.session', 'morning'))],
            ['label' => 'Evening', 'value' => $litres($today->where('data.session', 'evening'))],
            ['label' => 'This month', 'value' => $litres($month)],
            ['label' => 'Owed to farmers', 'value' => $this->money($month->sum('amount'))],
        ]]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deliveries = $this->dated('deliveries', $from, $to)->with('contact')->get();
        $accepted = $deliveries->where('status', 'accepted');

        $farmers = $accepted->groupBy(fn (Record $delivery) => $delivery->contact?->name ?? $delivery->title)->sortKeys()->map(function ($group, $farmer) {
            $fat = $group->filter(fn (Record $delivery) => filled($delivery->value('fat_percent')));

            return [$farmer, $group->count(), number_format($group->sum(fn (Record $delivery) => $this->number($delivery, 'litres')), 1),
                $fat->count() ? round($fat->avg(fn (Record $delivery) => $this->number($delivery, 'fat_percent')), 2) : '—', $this->money($group->sum('amount'))];
        })->values()->all();

        $centres = $deliveries->groupBy(fn (Record $delivery) => (string) ($delivery->value('centre') ?: 'Not given'))->sortKeys()->map(fn ($group, $centre) => [
            $centre, number_format($group->where('status', 'accepted')->sum(fn (Record $delivery) => $this->number($delivery, 'litres')), 1),
            number_format($group->where('status', 'rejected')->sum(fn (Record $delivery) => $this->number($delivery, 'litres')), 1),
        ])->values()->all();

        return [
            ['title' => 'Farmer payouts', 'columns' => ['Farmer', 'Deliveries', 'Litres', 'Average fat %', 'Payable'], 'rows' => $farmers],
            ['title' => 'Litres by centre', 'columns' => ['Centre', 'Accepted', 'Rejected'], 'rows' => $centres],
        ];
    }
}
