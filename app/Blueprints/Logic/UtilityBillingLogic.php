<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Water & electricity billing: each meter number is used once. Readings are only taken on active meters, start
 * from the meter's last reading and can't go below it. Consumption is the difference, and post-paid meters are
 * billed at the rate in their tariff with the bill due 14 days after the reading. A reading more than three times
 * the meter's recent average is flagged as high usage. Bills can be disputed and re-issued, and the home page
 * lists active meters not yet read this month.
 */
class UtilityBillingLogic extends AppLogic
{
    /**
     * Days from reading to the bill's due date.
     */
    public const DUE_DAYS = 14;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'meters') {
            $number = strtoupper(trim((string) $payload['title']));
            if ($this->records('meters')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $meter) => strtoupper(trim($meter->title)) === $number)) {
                $errors['title'] = 'Meter '.$number.' is already registered.';
            }

            return $errors;
        }
        $meter = filled($data['meter'] ?? null) ? $this->records('meters')->find($data['meter']) : null;
        if (! $meter) {
            return $errors;
        }
        if (! $existing && $meter->status !== 'active') {
            $errors['data.meter'] = 'Meter '.$meter->title.' is '.$meter->status.'.';
        }
        $previous = filled($data['previous'] ?? null) ? (float) $data['previous'] : $this->lastReading($meter, $existing);
        if (filled($data['current'] ?? null) && $previous !== null && (float) $data['current'] < $previous) {
            $errors['data.current'] = 'The reading is below the previous reading of '.$this->figure($previous).'.';
        }

        return $errors;
    }

    /**
     * The meter's latest reading before this one.
     */
    protected function lastReading(Record $meter, ?Record $existing): ?float
    {
        $last = $this->linked('readings', 'meter', $meter)->when($existing, fn ($query) => $query->whereKeyNot($existing->id)->where('id', '<', $existing->id))
            ->latest('occurs_on')->latest('id')->first();

        return $last ? $this->number($last, 'current') : null;
    }

    /**
     * The rate per unit written in a tariff, such as "K2.35 per kWh".
     */
    protected function rate(Record $meter): ?float
    {
        return preg_match('/\d+(?:\.\d+)?/', (string) $meter->value('tariff'), $match) ? (float) $match[0] : null;
    }

    /**
     * A meter figure without trailing zeros.
     */
    protected function figure(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'meters') {
            $record->title = strtoupper(trim($record->title));

            return;
        }
        $record->occurs_on ??= today();
        if (! ($meter = $this->parent($record, 'meter'))) {
            return;
        }
        if (blank($record->value('previous'))) {
            $this->put($record, ['previous' => $this->lastReading($meter, $record->exists ? $record : null) ?? 0]);
        }
        $consumption = round(max(0, $this->number($record, 'current') - $this->number($record, 'previous')), 2);
        $history = $this->linked('readings', 'meter', $meter)->when($record->exists, fn ($query) => $query->whereKeyNot($record->id))->latest('occurs_on')->latest('id')->limit(3)->get();
        $average = $history->count() ? $history->avg(fn (Record $reading) => $this->number($reading, 'consumption')) : 0;
        $this->put($record, ['consumption' => $consumption, '_high_usage' => $average > 0 && $consumption > $average * 3]);
        if (! $meter->value('prepaid') && ($rate = $this->rate($meter)) !== null) {
            $record->amount = round($consumption * $rate, 2);
            $record->due_on ??= $record->occurs_on->copy()->addDays(self::DUE_DAYS);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'meters') {
            return match ($record->status) {
                'active' => ['disconnect' => ['label' => 'Disconnect', 'icon' => 'plug-zap'], 'faulty' => ['label' => 'Report faulty', 'icon' => 'triangle-alert']],
                default => ['reconnect' => ['label' => 'Reconnect', 'icon' => 'plug']],
            };
        }

        return match ($record->status) {
            'read', 'estimated' => ['bill' => ['label' => 'Issue bill', 'icon' => 'receipt']],
            'billed' => ['dispute' => ['label' => 'Disputed', 'icon' => 'message-circle-warning']],
            'disputed' => ['bill' => ['label' => 'Re-issue bill', 'icon' => 'receipt', 'fields' => [['name' => 'current', 'label' => 'Corrected reading', 'type' => 'number', 'value' => $record->value('current')]]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'disconnect':
                $record->update(['status' => 'disconnected']);

                return 'Meter '.$record->title.' disconnected.';
            case 'faulty':
                $record->update(['status' => 'faulty']);

                return 'Meter '.$record->title.' reported faulty.';
            case 'reconnect':
                $record->update(['status' => 'active']);

                return 'Meter '.$record->title.' reconnected.';
            case 'dispute':
                $record->update(['status' => 'disputed']);

                return $record->title.'\'s bill is disputed.';
            default:
                $current = $request->validate(['current' => ['nullable', 'numeric', 'min:'.$this->number($record, 'previous')]])['current'] ?? null;
                $record->update(['status' => 'billed', 'data' => [...$record->data, ...($current !== null ? ['current' => (float) $current] : [])]]);

                return $record->title.' billed for '.$this->figure($this->number($record, 'consumption')).' units, '.$this->money($record->amount).'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'readings') {
            return $record->value('_high_usage') ? [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'High usage', 'icon' => 'triangle-alert', 'stats' => [
                ['label' => 'Consumption', 'value' => $this->figure($this->number($record, 'consumption'))],
                ['label' => 'Check', 'value' => 'More than 3× the recent average'],
            ]]]] : [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Recent readings', 'icon' => 'scan', 'empty' => 'No readings yet.',
            'rows' => $this->linked('readings', 'meter', $record)->latest('occurs_on')->latest('id')->limit(6)->get()
                ->map(fn (Record $reading) => ['label' => $reading->occurs_on->format('d M Y'), 'sub' => $this->figure($this->number($reading, 'current')).' · '.$reading->status, 'value' => $this->figure($this->number($reading, 'consumption')), 'href' => $reading->url(), 'tone' => $reading->value('_high_usage') ? 'warning' : null])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $readThisMonth = $this->records('readings')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->get()->map(fn (Record $reading) => (int) $reading->value('meter'))->all();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Not read this month', 'icon' => 'gauge', 'empty' => 'Every active meter has been read.',
                'rows' => $this->records('meters')->where('status', 'active')->orderBy('title')->get()->reject(fn (Record $meter) => in_array($meter->id, $readThisMonth, true))
                    ->map(fn (Record $meter) => ['label' => $meter->title, 'sub' => $meter->value('account_holder'), 'value' => ucfirst((string) $meter->value('type')), 'href' => $meter->url()])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Billing', 'icon' => 'receipt', 'stats' => [
                ['label' => 'Billed this month', 'value' => $this->money($this->records('readings')->where('status', 'billed')->whereDate('occurs_on', '>=', today()->startOfMonth()->toDateString())->sum('amount'))],
                ['label' => 'Disputed', 'value' => $this->records('readings')->where('status', 'disputed')->count()],
                ['label' => 'Meters faulty', 'value' => $this->records('meters')->where('status', 'faulty')->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $readings = $this->dated('readings', $from, $to)->get();
        $meters = $this->records('meters')->get()->keyBy('id');

        return [['title' => 'Consumption by type', 'columns' => ['Type', 'Readings', 'Consumption', 'Billed', 'High usage'], 'rows' => $readings
            ->groupBy(fn (Record $reading) => ucfirst((string) $meters->get((int) $reading->value('meter'))?->value('type')))->sortKeys()
            ->map(fn ($group, string $type) => [$type, $group->count(), $this->figure($group->sum(fn (Record $reading) => $this->number($reading, 'consumption'))), $this->money($group->where('status', 'billed')->sum('amount')), $group->filter(fn (Record $reading) => $reading->value('_high_usage'))->count()])
            ->values()->all()]];
    }
}
