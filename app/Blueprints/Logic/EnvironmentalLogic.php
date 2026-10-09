<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Environmental monitoring: a permit is active only with a unique permit number and an expiry
 * date, and expires by itself after that date. A sample with a result and a limit is judged
 * compliant or non-compliant by itself, a judged sample needs a result, and each permit tracks
 * its samples, exceedances and compliance rate.
 */
class EnvironmentalLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'permits') {
            $number = strtoupper(trim((string) ($data['permit_number'] ?? '')));
            if ($number !== '' && $this->records('permits')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->contains(fn (Record $permit) => strtoupper(trim((string) $permit->value('permit_number'))) === $number)) {
                $errors['data.permit_number'] = 'Permit '.$number.' already exists.';
            }
            if ($payload['status'] === 'active') {
                if ($number === '') {
                    $errors['data.permit_number'] = 'An active permit needs its number.';
                }
                if (blank($payload['due_on'] ?? null)) {
                    $errors['due_on'] = 'An active permit needs an expiry date.';
                }
            }
            if (filled($payload['occurs_on'] ?? null) && filled($payload['due_on'] ?? null) && Carbon::parse($payload['due_on'])->lte(Carbon::parse($payload['occurs_on']))) {
                $errors['due_on'] = 'The permit expires after it is issued.';
            }

            return $errors;
        }

        if (in_array($payload['status'], ['compliant', 'non_compliant'], true) && blank($data['result'] ?? null)) {
            $errors['data.result'] = 'Record the result first.';
        }
        foreach (['result', 'limit'] as $field) {
            if (filled($data[$field] ?? null) && (float) $data[$field] < 0) {
                $errors['data.'.$field] = 'This cannot be negative.';
            }
        }
        if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->gt(today())) {
            $errors['occurs_on'] = 'A sample cannot be taken in the future.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();

        if ($record->entity === 'samples') {
            if (filled($record->value('result')) && filled($record->value('limit'))) {
                $record->status = (float) $record->value('result') <= (float) $record->value('limit') ? 'compliant' : 'non_compliant';
            }
            $this->put($record, ['_exceedance' => $record->status === 'non_compliant' && filled($record->value('limit')) ? round((float) $record->value('result') - (float) $record->value('limit'), 4) : null]);

            return;
        }

        if ($record->status === 'active' && $record->due_on?->lt(today())) {
            $record->status = 'expired';
        }
        $samples = $record->exists ? $this->linked('samples', 'permit', $record)->get() : collect();
        $judged = $samples->whereIn('status', ['compliant', 'non_compliant']);
        $last = $samples->sortByDesc('occurs_on')->first();
        $this->put($record, [
            'permit_number' => strtoupper(trim((string) $record->value('permit_number'))) ?: null,
            '_samples' => $samples->count(),
            '_exceedances' => $judged->where('status', 'non_compliant')->count(),
            '_compliance' => $judged->isEmpty() ? null : (int) round($judged->where('status', 'compliant')->count() / $judged->count() * 100),
            '_last_sample' => $last?->occurs_on?->toDateString(),
            '_days_to_expiry' => $record->due_on ? (int) today()->diffInDays($record->due_on, false) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'samples') {
            $this->recalculate($this->parent($record, 'permit'));
            $this->recalculate($this->previousParent($record, 'permit'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'samples') {
            $this->recalculate($this->parent($record, 'permit'));
        }
    }

    public function daily(Workspace $workspace): int
    {
        $expired = 0;
        foreach ($this->records('permits')->where('status', 'active')->whereDate('due_on', '<', today())->get() as $permit) {
            $permit->update(['status' => 'expired']);
            $expired++;
        }

        return $expired;
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'samples') {
            $result = ['label' => 'Record result', 'icon' => 'flask-conical', 'fields' => [
                ['name' => 'result', 'label' => 'Result', 'type' => 'number'],
                ['name' => 'limit', 'label' => 'Limit', 'type' => 'number', 'value' => $record->value('limit')],
            ]];

            return match ($record->status) {
                'collected' => ['send_to_lab' => ['label' => 'Send to lab', 'icon' => 'send', 'fields' => [['name' => 'lab_reference', 'label' => 'Lab reference', 'type' => 'text']]], 'record_result' => $result],
                'at_lab' => ['record_result' => $result],
                default => [],
            };
        }

        $renew = ['label' => 'Renew', 'icon' => 'refresh-cw', 'fields' => [['name' => 'due_on', 'label' => 'New expiry', 'type' => 'date', 'value' => ($record->due_on && $record->due_on->gt(today()) ? $record->due_on->copy() : today())->addYears(5)->toDateString()]]];

        return match ($record->status) {
            'applied' => ['issue' => ['label' => 'Issue', 'icon' => 'stamp', 'fields' => [
                ['name' => 'permit_number', 'label' => 'Permit number', 'type' => 'text', 'value' => $record->value('permit_number')],
                ['name' => 'due_on', 'label' => 'Expires on', 'type' => 'date', 'value' => today()->addYears(5)->toDateString()],
            ]]],
            'active' => ['suspend' => ['label' => 'Suspend', 'icon' => 'pause', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'textarea']]], 'renew' => $renew],
            'suspended' => ['reinstate' => ['label' => 'Reinstate', 'icon' => 'play']],
            default => ['renew' => $renew],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'send_to_lab':
                $reference = $request->validate(['lab_reference' => ['nullable', 'string']])['lab_reference'] ?? null;
                $record->update(['status' => 'at_lab', 'data' => [...$record->data, 'lab_reference' => $reference ?: $record->value('lab_reference')]]);

                return $record->value('parameter').' sample from '.$record->title.' sent to the lab.';
            case 'record_result':
                $input = $request->validate(['result' => ['required', 'numeric', 'min:0'], 'limit' => ['required', 'numeric', 'min:0']]);
                $record->update(['data' => [...$record->data, 'result' => (float) $input['result'], 'limit' => (float) $input['limit']]]);
                $parameter = $record->value('parameter').' at '.$record->title;

                return $record->fresh()->status === 'compliant'
                    ? $parameter.' is within its limit.'
                    : $parameter.' exceeds its limit of '.(float) $input['limit'].'.';
            case 'issue':
                $input = $request->validate(['permit_number' => ['required', 'string'], 'due_on' => ['required', 'date', 'after:today']]);
                $number = strtoupper(trim($input['permit_number']));
                if ($this->records('permits')->whereKeyNot($record->id)->get()->contains(fn (Record $permit) => $permit->value('permit_number') === $number)) {
                    throw ValidationException::withMessages(['permit_number' => 'Permit '.$number.' already exists.']);
                }
                $due = Carbon::parse($input['due_on']);
                $record->update(['status' => 'active', 'occurs_on' => today(), 'due_on' => $due, 'data' => [...$record->data, 'permit_number' => $number]]);

                return 'Permit '.$number.' issued, valid until '.$due->format('d M Y').'.';
            case 'suspend':
                $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
                $record->update(['status' => 'suspended', 'data' => [...$record->data, '_suspension_reason' => $reason, '_suspended_on' => today()->toDateString()]]);

                return 'Permit '.$record->value('permit_number').' suspended.';
            case 'reinstate':
                $record->update(['status' => 'active']);

                return 'Permit '.$record->value('permit_number').' reinstated.';
        }

        $due = Carbon::parse($request->validate(['due_on' => ['required', 'date', 'after:today']])['due_on']);
        $record->update(['status' => 'active', 'due_on' => $due]);

        return 'Permit '.$record->value('permit_number').' renewed until '.$due->format('d M Y').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'permits') {
            return [];
        }

        $samples = $this->linked('samples', 'permit', $record)->orderByDesc('occurs_on')->get();
        $compliance = $record->value('_compliance');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Permit', 'icon' => 'file-badge', 'stats' => [
                ['label' => 'Permit number', 'value' => $record->value('permit_number') ?: '—'],
                ['label' => 'Expires', 'value' => $record->due_on?->format('d M Y') ?? '—', 'tone' => $record->value('_days_to_expiry') !== null && (int) $record->value('_days_to_expiry') <= 90 ? 'warning' : null],
                ['label' => 'Samples', 'value' => (string) (int) $record->value('_samples')],
                ['label' => 'Exceedances', 'value' => (string) (int) $record->value('_exceedances'), 'tone' => (int) $record->value('_exceedances') > 0 ? 'danger' : null],
                ['label' => 'Compliance', 'value' => $compliance === null ? '—' : $compliance.'%', 'tone' => $compliance !== null && (int) $compliance < 90 ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Samples', 'icon' => 'test-tube', 'empty' => 'No samples taken.',
                'rows' => $samples->take(15)->map(fn (Record $sample) => [
                    'label' => $sample->value('parameter').' · '.$sample->title, 'sub' => $sample->occurs_on?->format('d M Y').' · '.ucfirst((string) $sample->value('medium')), 'value' => filled($sample->value('result')) ? (float) $sample->value('result').(filled($sample->value('limit')) ? ' / '.(float) $sample->value('limit') : '') : ucfirst(str_replace('_', ' ', $sample->status)), 'href' => $sample->url(), 'tone' => $sample->status === 'non_compliant' ? 'danger' : ($sample->status === 'compliant' ? 'success' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $permits = $this->records('permits')->get();
        $samples = $this->records('samples')->get();
        $exceedances = $samples->where('status', 'non_compliant')->sortByDesc('occurs_on');
        $thisMonth = $samples->filter(fn (Record $sample) => $sample->occurs_on?->isCurrentMonth());

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Environment', 'icon' => 'leaf', 'stats' => [
                ['label' => 'Active permits', 'value' => (string) $permits->where('status', 'active')->count()],
                ['label' => 'Expiring in 90 days', 'value' => (string) $permits->filter(fn (Record $permit) => $permit->status === 'active' && $permit->value('_days_to_expiry') !== null && (int) $permit->value('_days_to_expiry') <= 90)->count()],
                ['label' => 'Suspended', 'value' => (string) $permits->where('status', 'suspended')->count()],
                ['label' => 'Samples this month', 'value' => (string) $thisMonth->count()],
                ['label' => 'Exceedances this month', 'value' => (string) $thisMonth->where('status', 'non_compliant')->count(), 'tone' => $thisMonth->where('status', 'non_compliant')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Awaiting results', 'value' => (string) $samples->whereIn('status', ['collected', 'at_lab'])->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Latest exceedances', 'icon' => 'triangle-alert', 'empty' => 'No exceedances recorded.',
                'rows' => $exceedances->take(10)->map(fn (Record $sample) => [
                    'label' => $sample->value('parameter').' · '.$sample->title, 'sub' => $sample->occurs_on?->format('d M Y'), 'value' => (float) $sample->value('result').' / '.(float) $sample->value('limit'), 'href' => $sample->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $samples = $this->dated('samples', $from, $to)->get();
        $byParameter = $samples->groupBy(fn (Record $sample) => (string) $sample->value('parameter'))->sortKeys()->map(function ($group, $parameter) {
            $judged = $group->whereIn('status', ['compliant', 'non_compliant']);

            return [$parameter, ucfirst((string) $group->first()->value('medium')), $group->count(), $judged->where('status', 'non_compliant')->count(), $judged->isEmpty() ? '—' : (int) round($judged->where('status', 'compliant')->count() / $judged->count() * 100).'%', $judged->isEmpty() ? '—' : (string) round((float) $judged->max(fn (Record $sample) => (float) $sample->value('result')), 4)];
        })->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($samples) {
            $group = $samples->filter(fn (Record $sample) => $sample->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'compliant')->count(), $group->where('status', 'non_compliant')->count()];
        })->values()->all();

        $permits = $this->records('permits')->get();
        $types = $this->app->entities['permits']->field('type')?->options ?? [];
        $byType = collect($types)->map(fn (string $label, string $type) => [$label, $permits->where('data.type', $type)->where('status', 'active')->count(), $permits->where('data.type', $type)->where('status', 'suspended')->count(), $permits->where('data.type', $type)->where('status', 'expired')->count()])->values()->all();

        return [
            ['title' => 'Samples by parameter', 'columns' => ['Parameter', 'Medium', 'Samples', 'Exceedances', 'Compliance', 'Highest result'], 'rows' => $byParameter],
            ['title' => 'Exceedances by month', 'columns' => ['Month', 'Samples', 'Compliant', 'Non-compliant'], 'rows' => $byMonth],
            ['title' => 'Permits by type', 'columns' => ['Type', 'Active', 'Suspended', 'Expired'], 'rows' => $byType],
        ];
    }
}
