<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Borehole & water tanker delivery: an order is for more than 0 and at most 40,000 litres, and is dispatched
 * with a tanker and driver; a tanker can't be out on two deliveries at once. Delivering records the day, and
 * payment follows delivery. Borehole jobs move forward through their stages and can't go back: casing needs
 * the depth drilled, the pump needs the yield and pump, and completion needs the water quality result.
 */
class WaterTankerLogic extends AppLogic
{
    /**
     * The most a single tanker load can carry.
     */
    public const MAX_LITRES = 40000;

    /**
     * Borehole stages in order.
     *
     * @var list<string>
     */
    public const STAGES = ['survey', 'quoted', 'drilling', 'cased', 'pump_installed', 'completed'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        if ($entity->key === 'boreholes') {
            $errors = $this->missingForStage($payload['status'], $data);
            if ($existing && array_search($payload['status'], self::STAGES, true) < array_search($existing->status, self::STAGES, true)) {
                $errors['status'] = 'A borehole job can\'t go back to '.str_replace('_', ' ', $payload['status']).'.';
            }

            return $errors;
        }
        $errors = [];
        $litres = (float) ($data['litres'] ?? 0);
        if (filled($data['litres'] ?? null) && ($litres <= 0 || $litres > self::MAX_LITRES)) {
            $errors['data.litres'] = 'An order is for 1 to '.number_format(self::MAX_LITRES).' litres; split bigger orders.';
        }
        if ($payload['status'] === 'dispatched') {
            foreach (['tanker' => 'Say which tanker is going.', 'driver' => 'Give the driver.'] as $field => $message) {
                if (blank($data[$field] ?? null)) {
                    $errors['data.'.$field] = $message;
                }
            }
            if (! isset($errors['data.tanker']) && ($busy = $this->busyWith((string) $data['tanker'], $existing))) {
                $errors['data.tanker'] = 'Tanker '.trim((string) $data['tanker']).' is already out delivering to '.$busy->title.'.';
            }
        }

        return $errors;
    }

    /**
     * What a borehole job still needs before it can be at a stage.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function missingForStage(string $stage, array $data): array
    {
        $reached = array_search($stage, self::STAGES, true);
        $errors = [];
        if ($reached >= array_search('cased', self::STAGES, true) && (float) ($data['depth'] ?? 0) <= 0) {
            $errors['data.depth'] = 'Give the depth drilled before casing.';
        }
        if ($reached >= array_search('pump_installed', self::STAGES, true)) {
            if ((float) ($data['yield'] ?? 0) <= 0) {
                $errors['data.yield'] = 'Give the test-pumped yield before installing the pump.';
            }
            if (blank($data['pump'] ?? null)) {
                $errors['data.pump'] = 'Say which pump was installed.';
            }
        }
        if ($stage === 'completed' && blank($data['water_quality'] ?? null)) {
            $errors['data.water_quality'] = 'Give the water quality result before completing.';
        }

        return $errors;
    }

    /**
     * Another delivery the tanker is out on.
     */
    protected function busyWith(string $tanker, ?Record $existing): ?Record
    {
        $tanker = strtolower(trim($tanker));

        return $this->records('deliveries')->where('status', 'dispatched')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $delivery) => strtolower(trim((string) $delivery->value('tanker'))) === $tanker);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if ($record->entity === 'deliveries' && $record->status === 'delivered' && blank($record->value('_delivered_on'))) {
            $this->put($record, ['_delivered_on' => today()->toDateString()]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'boreholes') {
            $next = self::STAGES[array_search($record->status, self::STAGES, true) + 1] ?? null;
            if (! $next) {
                return [];
            }
            $fields = match ($next) {
                'cased' => [['name' => 'depth', 'label' => 'Depth (m)', 'type' => 'number', 'value' => $record->value('depth')]],
                'pump_installed' => [['name' => 'yield', 'label' => 'Yield (L/hour)', 'type' => 'number', 'value' => $record->value('yield')], ['name' => 'pump', 'label' => 'Pump', 'type' => 'text', 'value' => $record->value('pump')]],
                'completed' => [['name' => 'water_quality', 'label' => 'Water quality', 'type' => 'text', 'value' => $record->value('water_quality')]],
                default => [],
            };

            return ['advance' => ['label' => 'Move to '.str_replace('_', ' ', $next), 'icon' => 'arrow-right', 'fields' => $fields]];
        }
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x']];

        return match ($record->status) {
            'ordered' => ['dispatch' => ['label' => 'Dispatch', 'icon' => 'truck', 'fields' => [
                ['name' => 'tanker', 'label' => 'Tanker', 'type' => 'text', 'value' => $record->value('tanker')],
                ['name' => 'driver', 'label' => 'Driver', 'type' => 'text', 'value' => $record->value('driver')],
            ]], ...$cancel],
            'dispatched' => ['deliver' => ['label' => 'Delivered', 'icon' => 'check'], ...$cancel],
            'delivered' => ['pay' => ['label' => 'Paid', 'icon' => 'banknote']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'advance':
                $next = self::STAGES[array_search($record->status, self::STAGES, true) + 1];
                $input = array_filter($request->only(['depth', 'yield', 'pump', 'water_quality']), fn ($value) => filled($value));
                $data = [...$record->data, ...$input];
                if ($errors = $this->missingForStage($next, $data)) {
                    throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $key) => [str_replace('data.', '', $key) => $message])->all());
                }
                $record->update(['status' => $next, 'data' => $data]);

                return $record->title.' moved to '.str_replace('_', ' ', $next).'.';
            case 'dispatch':
                $input = $request->validate(['tanker' => ['required', 'string', 'max:100'], 'driver' => ['required', 'string', 'max:100']]);
                if ($busy = $this->busyWith($input['tanker'], $record)) {
                    throw ValidationException::withMessages(['tanker' => 'Tanker '.trim($input['tanker']).' is already out delivering to '.$busy->title.'.']);
                }
                $record->update(['status' => 'dispatched', 'data' => [...$record->data, ...$input]]);

                return $record->title.'\'s water dispatched on '.trim($input['tanker']).'.';
            case 'deliver':
                $record->update(['status' => 'delivered']);

                return number_format($this->number($record, 'litres')).' L delivered to '.$record->title.'.';
            case 'pay':
                $record->update(['status' => 'paid']);

                return $record->title.' paid '.$this->money($record->amount).'.';
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.'\'s order cancelled.';
        }
    }

    public function homeCards(): array
    {
        $deliveries = $this->records('deliveries')->whereIn('status', ['ordered', 'dispatched'])->whereDate('occurs_on', '<=', today()->toDateString())->orderBy('occurs_on')->get();
        $month = $this->records('deliveries')->whereIn('status', ['delivered', 'paid'])->get()->filter(fn (Record $delivery) => Carbon::parse($delivery->value('_delivered_on') ?? $delivery->occurs_on)->gte(today()->startOfMonth()));

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'To deliver', 'icon' => 'droplet', 'empty' => 'No deliveries waiting.',
                'rows' => $deliveries->map(fn (Record $delivery) => ['label' => $delivery->title, 'sub' => number_format($this->number($delivery, 'litres')).' L'.($delivery->value('tanker') ? ' · '.$delivery->value('tanker') : ''), 'value' => $delivery->status, 'href' => $delivery->url(), 'tone' => $delivery->occurs_on->lt(today()) ? 'danger' : null])->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'This month', 'icon' => 'truck', 'stats' => [
                ['label' => 'Litres delivered', 'value' => number_format($month->sum(fn (Record $delivery) => $this->number($delivery, 'litres')))],
                ['label' => 'Unpaid deliveries', 'value' => $this->money($this->records('deliveries')->where('status', 'delivered')->sum('amount'))],
                ['label' => 'Boreholes in progress', 'value' => $this->records('boreholes')->whereNotIn('status', ['survey', 'quoted', 'completed'])->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $deliveries = $this->dated('deliveries', $from, $to)->whereIn('status', ['delivered', 'paid'])->get();
        $boreholes = $this->records('boreholes')->where('status', 'completed')->get();

        return [
            ['title' => 'Deliveries by tanker', 'columns' => ['Tanker', 'Deliveries', 'Litres', 'Value'], 'rows' => $deliveries
                ->groupBy(fn (Record $delivery) => trim((string) $delivery->value('tanker')) ?: '—')->sortKeys()
                ->map(fn ($group, string $tanker) => [$tanker, $group->count(), number_format($group->sum(fn (Record $delivery) => $this->number($delivery, 'litres'))), $this->money($group->sum('amount'))])
                ->values()->all()],
            ['title' => 'Completed boreholes', 'columns' => ['Site', 'Depth (m)', 'Yield (L/hour)', 'Water quality'], 'rows' => $boreholes->sortBy('title')
                ->map(fn (Record $borehole) => [$borehole->title, $this->number($borehole, 'depth'), $this->number($borehole, 'yield'), $borehole->value('water_quality')])
                ->values()->all()],
        ];
    }
}
