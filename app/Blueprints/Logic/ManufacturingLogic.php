<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Manufacturing: a bill of materials lists one component per line ("2.5 x Steel sheet @ 40") and its
 * unit cost is the components' cost divided by the output quantity. Work orders use active bills only
 * and list the materials they need, scaled to the quantity. They move forward from planned to
 * completed, can't make more than ordered, and are completed only after a passed quality check.
 * A check passes at up to 2.5% defects, fails above that unless marked for rework, and a failed
 * check sends the work order back into production.
 */
class ManufacturingLogic extends AppLogic
{
    /**
     * Work order steps in order.
     */
    protected const STEPS = ['planned', 'in_progress', 'qc', 'completed'];

    /**
     * Highest defect rate (%) a quality check passes at.
     */
    protected const ACCEPTABLE_DEFECTS = 2.5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $status = $payload['status'];
        $errors = [];

        if ($entity->key === 'boms') {
            if ((float) ($data['output_quantity'] ?? 0) <= 0) {
                $errors['data.output_quantity'] = 'Enter how many the components make.';
            }
            [, $problems] = $this->components((string) ($data['components'] ?? ''));
            if ($problems) {
                $errors['data.components'] = implode(' ', $problems);
            }
            if ($existing && $status !== 'active' && $existing->status === 'active' && ($open = $this->openOrders($existing))) {
                $errors['status'] = $open.' open work '.str('order')->plural($open).' still use this bill.';
            }

            return $errors;
        }

        if ($entity->key === 'work_orders') {
            if ($existing && $existing->status === 'cancelled' && $status !== 'cancelled') {
                $errors['status'] = 'This work order was cancelled.';
            }
            if ($existing && in_array($status, self::STEPS, true) && in_array($existing->status, self::STEPS, true) && array_search($status, self::STEPS, true) < array_search($existing->status, self::STEPS, true)) {
                $errors['status'] = 'A work order cannot go back to '.str_replace('_', ' ', $status).'; fail a quality check to send it back.';
            }
            if ($existing?->status === 'completed' && $status === 'cancelled') {
                $errors['status'] = 'A completed work order cannot be cancelled.';
            }
            if ((float) ($data['quantity'] ?? 0) <= 0) {
                $errors['data.quantity'] = 'Enter how many to make.';
            }
            if ((float) ($data['quantity_made'] ?? 0) > (float) ($data['quantity'] ?? 0)) {
                $errors['data.quantity_made'] = 'More cannot be made than the work order is for.';
            }
            $bom = filled($data['bom'] ?? null) ? $this->records('boms')->find($data['bom']) : null;
            if ($bom && $bom->status !== 'active' && (! $existing || (int) $existing->value('bom') !== $bom->id)) {
                $errors['data.bom'] = $bom->title.'\'s bill of materials is '.$bom->status.'.';
            }
            if ($status === 'completed' && $existing?->status !== 'completed' && ! $this->passed($existing)) {
                $errors['status'] = 'A work order is completed once a quality check passes.';
            }
            if (filled($payload['due_on'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['due_on'])->lt(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The due date cannot be before the start date.';
            }

            return $errors;
        }

        if ((int) ($data['defects'] ?? 0) < 0) {
            $errors['data.defects'] = 'Defects cannot be negative.';
        }
        if ((int) ($data['sample_size'] ?? 0) < 1) {
            $errors['data.sample_size'] = 'Enter how many were checked.';
        } elseif ((int) ($data['defects'] ?? 0) > (int) $data['sample_size']) {
            $errors['data.defects'] = 'There cannot be more defects than items checked.';
        }
        $order = filled($data['work_order'] ?? null) ? $this->records('work_orders')->find($data['work_order']) : null;
        if (! $existing && $order && ! in_array($order->status, ['in_progress', 'qc'], true)) {
            $errors['data.work_order'] = $order->title.' is '.str_replace('_', ' ', $order->status).'.';
        }

        return $errors;
    }

    /**
     * Read component lines like "2.5 x Steel sheet @ 40" (the cost is optional).
     *
     * @return array{0: list<array{item: string, quantity: float, cost: float|null}>, 1: list<string>}
     */
    public function components(string $components): array
    {
        $lines = [];
        $problems = [];
        foreach (preg_split('/\R/', $components) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! preg_match('/^\s*(\d+(?:\.\d+)?)\s*(?:[x×*]|kg|g|l|m|pcs)?\s+(.+?)(?:\s*@\s*(\d+(?:\.\d+)?))?\s*$/iu', $line, $match)) {
                $problems[] = 'Start "'.trim($line).'" with a quantity, like "2 x '.trim($line).'".';

                continue;
            }
            $lines[] = ['item' => trim($match[2]), 'quantity' => (float) $match[1], 'cost' => isset($match[3]) && $match[3] !== '' ? (float) $match[3] : null];
        }

        return [$lines, $problems];
    }

    /**
     * How many open work orders use the bill.
     */
    protected function openOrders(Record $bom): int
    {
        return $this->linked('work_orders', 'bom', $bom)->whereIn('status', ['planned', 'in_progress', 'qc'])->count();
    }

    /**
     * Whether the work order's latest quality check passed.
     */
    protected function passed(?Record $order): bool
    {
        return $order && $this->linked('quality_checks', 'work_order', $order)->orderByDesc('id')->first()?->status === 'passed';
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'boms') {
            [$lines] = $this->components((string) $record->value('components'));
            $costed = collect($lines)->filter(fn (array $line) => $line['cost'] !== null);
            $output = max(1, $this->number($record, 'output_quantity'));
            $record->amount = $costed->isEmpty() ? $record->amount : round($costed->sum(fn (array $line) => $line['quantity'] * $line['cost']) / $output, 2);
            $this->put($record, ['_lines' => $lines]);

            return;
        }

        $record->occurs_on ??= today();
        if ($record->entity === 'work_orders') {
            $bom = $this->parent($record, 'bom');
            $made = $this->number($record, 'quantity_made');
            $scrap = $this->number($record, 'scrap');
            $this->put($record, [
                '_unit_cost' => $bom?->amount,
                '_cost' => $bom?->amount !== null ? round((float) $bom->amount * ($made + $scrap), 2) : null,
                '_scrap_rate' => $made + $scrap > 0 ? round($scrap / ($made + $scrap) * 100, 1) : null,
            ]);

            return;
        }

        $sample = max(1, (int) $record->value('sample_size'));
        $rate = round((int) $record->value('defects') / $sample * 100, 1);
        $this->put($record, ['_defect_rate' => $rate]);
        if ($rate <= self::ACCEPTABLE_DEFECTS) {
            $record->status = 'passed';
        } elseif ($record->status === 'passed') {
            $record->status = 'failed';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'quality_checks' || ! ($record->wasRecentlyCreated || $record->wasChanged('status'))) {
            return;
        }
        $order = $this->parent($record, 'work_order');
        if ($order && $order->status === 'qc' && $record->status !== 'passed') {
            $order->update(['status' => 'in_progress', 'data' => [...$order->data, '_sent_back' => (int) $order->value('_sent_back') + 1]]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'work_orders') {
            return [];
        }
        $cancel = ['cancel' => ['label' => 'Cancel', 'icon' => 'x']];

        return match ($record->status) {
            'planned' => ['start' => ['label' => 'Start production', 'icon' => 'play'], ...$cancel],
            'in_progress' => ['to_qc' => ['label' => 'Send to quality check', 'icon' => 'badge-check', 'fields' => [
                ['name' => 'quantity_made', 'label' => 'Quantity made', 'type' => 'number', 'value' => $record->value('quantity_made')],
                ['name' => 'scrap', 'label' => 'Scrap', 'type' => 'number', 'value' => $record->value('scrap')],
            ]], ...$cancel],
            'qc' => $this->passed($record) ? ['complete' => ['label' => 'Complete', 'icon' => 'check']] : [],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'start':
                $bom = $this->parent($record, 'bom');
                if ($bom && $bom->status !== 'active') {
                    throw ValidationException::withMessages(['bom' => $bom->title.'\'s bill of materials is '.$bom->status.'.']);
                }
                $record->update(['status' => 'in_progress', 'data' => [...$record->data, '_started_at' => now()->toDateTimeString()]]);

                return $record->title.' started.';
            case 'to_qc':
                $values = $request->validate(['quantity_made' => ['required', 'numeric', 'gt:0'], 'scrap' => ['nullable', 'numeric', 'min:0']]);
                if ((float) $values['quantity_made'] > $this->number($record, 'quantity')) {
                    throw ValidationException::withMessages(['quantity_made' => 'More cannot be made than the work order is for.']);
                }
                $record->update(['status' => 'qc', 'data' => [...$record->data, 'quantity_made' => (float) $values['quantity_made'], 'scrap' => (float) ($values['scrap'] ?? 0)]]);

                return $record->title.' sent to quality check: '.$this->quantity($this->number($record, 'quantity_made')).' made.';
            case 'complete':
                if (! $this->passed($record)) {
                    throw ValidationException::withMessages(['status' => 'A work order is completed once a quality check passes.']);
                }
                $record->update(['status' => 'completed', 'data' => [...$record->data, '_completed_on' => today()->toDateString()]]);

                return $record->title.' completed: '.$this->quantity($this->number($record, 'quantity_made')).' of '.$this->quantity($this->number($record, 'quantity')).' made'.($record->value('_cost') !== null ? ' at '.$this->money($record->value('_cost')).'.' : '.');
            default:
                $record->update(['status' => 'cancelled']);

                return $record->title.' cancelled.';
        }
    }

    /**
     * A quantity without trailing zeros.
     */
    protected function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'work_orders' || ! ($bom = $this->parent($record, 'bom'))) {
            return [];
        }
        $scale = $this->number($record, 'quantity') / max(1, $this->number($bom, 'output_quantity'));

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Materials needed', 'icon' => 'list-tree', 'empty' => 'The bill of materials has no components.',
            'rows' => collect($bom->value('_lines') ?? [])->map(fn (array $line) => ['label' => $line['item'], 'value' => $this->quantity($line['quantity'] * $scale), 'sub' => $line['cost'] !== null ? $this->money($line['quantity'] * $scale * $line['cost']) : null])->values()->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $open = $this->records('work_orders')->whereIn('status', ['planned', 'in_progress', 'qc'])->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Open work orders', 'icon' => 'factory', 'empty' => 'Nothing in production.',
            'rows' => $open->map(fn (Record $order) => [
                'label' => $order->title, 'sub' => $this->quantity($this->number($order, 'quantity_made')).' / '.$this->quantity($this->number($order, 'quantity')).' · '.str_replace('_', ' ', $order->status),
                'value' => $order->due_on ? 'Due '.$order->due_on->format('d M') : '—', 'href' => $order->url(),
                'tone' => $order->due_on?->lt(today()) ? 'danger' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $orders = $this->dated('work_orders', $from, $to)->where('status', 'completed')->get();
        $checks = $this->dated('quality_checks', $from, $to)->get();
        $boms = $this->records('boms')->pluck('title', 'id');

        $output = $orders->groupBy(fn (Record $order) => (int) $order->value('bom'))
            ->map(function (Collection $group, int $bom) use ($boms) {
                $planned = $group->sum(fn (Record $order) => $this->number($order, 'quantity'));
                $made = $group->sum(fn (Record $order) => $this->number($order, 'quantity_made'));
                $scrap = $group->sum(fn (Record $order) => $this->number($order, 'scrap'));
                $late = $group->filter(fn (Record $order) => $order->due_on && $order->value('_completed_on') && Carbon::parse($order->value('_completed_on'))->gt($order->due_on))->count();

                return [$boms[$bom] ?? '—', $group->count(), $this->quantity($planned), $this->quantity($made), $made + $scrap > 0 ? round($scrap / ($made + $scrap) * 100, 1).'%' : '—', $late, $this->money($group->sum(fn (Record $order) => $this->number($order, '_cost')))];
            })->sortBy(fn (array $row) => $row[0])->values()->all();

        $quality = $checks->groupBy('status')->sortKeys()
            ->map(fn (Collection $group, string $status) => [ucfirst($status), $group->count(), round($group->avg(fn (Record $check) => $this->number($check, '_defect_rate')), 1).'%'])->values()->all();

        return [
            ['title' => 'Output against plan', 'columns' => ['Product', 'Work orders', 'Planned', 'Made', 'Scrap rate', 'Late', 'Cost'], 'rows' => $output],
            ['title' => 'Quality checks', 'columns' => ['Result', 'Checks', 'Average defect rate'], 'rows' => $quality],
        ];
    }
}
