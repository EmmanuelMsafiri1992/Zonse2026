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
 * Lab testing: a result is passed or failed automatically when its value can be checked against the
 * specification ("≤ 10", "< 0.5", "max 10", "min 5", "6.5 - 8.5", "Absent"). The sample moves to testing
 * with its first result and to results ready once every result is decided. A certificate of analysis is
 * numbered on issue and needs every result decided; after that, and after a rejection (which needs a
 * reason), its results are locked. Results are due five days after receipt unless a date is given.
 */
class LabLogic extends AppLogic
{
    /**
     * Days from receipt to results when no due date is given.
     */
    protected const TURNAROUND_DAYS = 5;

    /**
     * Sample statuses whose results can no longer change.
     */
    protected const CLOSED = ['certificate_issued', 'rejected'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'samples') {
            $number = trim((string) ($data['sample_number'] ?? ''));
            $taken = $this->records('samples')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $sample) => strcasecmp(trim((string) $sample->value('sample_number')), $number) === 0);
            if ($number !== '' && $taken) {
                $errors['data.sample_number'] = 'Sample number '.$number.' is already used by '.$taken->number.'.';
            }
            if ($existing && in_array($existing->status, self::CLOSED, true) && $payload['status'] !== $existing->status) {
                $errors['status'] = 'This sample is '.str_replace('_', ' ', $existing->status).'.';
            }
            if ($payload['status'] === 'certificate_issued' && $existing?->status !== 'certificate_issued' && ($problem = $this->notReady($existing))) {
                $errors['status'] = $problem;
            }
            if ($payload['status'] === 'rejected' && $existing?->status !== 'rejected') {
                $errors['status'] = 'Use "Reject" so the reason is recorded.';
            }

            return $errors;
        }

        $sample = filled($data['sample'] ?? null) ? $this->records('samples')->find($data['sample']) : null;
        if ($sample && in_array($sample->status, self::CLOSED, true)) {
            $errors['data.sample'] = $sample->number.' is '.str_replace('_', ' ', $sample->status).'; its results are locked.';
        }
        if ($existing && ($old = $this->parent($existing, 'sample')) && $old->isNot($sample) && in_array($old->status, self::CLOSED, true)) {
            $errors['data.sample'] = $old->number.' is '.str_replace('_', ' ', $old->status).'; its results are locked.';
        }

        return $errors;
    }

    /**
     * Why a certificate can't be issued for the sample yet.
     */
    protected function notReady(?Record $sample): ?string
    {
        $results = $sample ? $this->linked('results', 'sample', $sample)->get() : collect();
        if ($results->isEmpty()) {
            return 'Record the results before issuing a certificate.';
        }
        $pending = $results->where('status', 'pending');

        return $pending->isEmpty() ? null : 'Still pending: '.$pending->pluck('title')->implode(', ').'.';
    }

    /**
     * Check a result against its specification: pass, fail or null when it can't be checked.
     */
    public function judge(string $value, string $specification): ?string
    {
        $specification = trim(str_replace(['≤', '≥', '–', '—'], ['<=', '>=', '-', '-'], $specification));
        $value = trim($value);
        if ($specification === '' || $value === '') {
            return null;
        }
        $reading = $this->numeric($value);

        if (preg_match('/^(<=|>=|<|>|max(?:imum)?|min(?:imum)?|nmt|nlt)\s*:?\s*(-?[\d.,]+)/i', $specification, $match)) {
            if ($reading === null) {
                $upperLimit = preg_match('/^(<|max|nmt)/i', $match[1]) === 1;

                return $upperLimit && preg_match('/^(<|nd|not detected|absent|bdl)/i', $value) ? 'pass' : null;
            }
            $limit = (float) str_replace(',', '', $match[2]);

            return match (strtolower($match[1])) {
                '<' => $reading < $limit,
                '>' => $reading > $limit,
                '>=', 'min', 'minimum', 'nlt' => $reading >= $limit,
                default => $reading <= $limit,
            } ? 'pass' : 'fail';
        }
        if (preg_match('/^(-?[\d.,]+)\s*(?:-|to)\s*(-?[\d.,]+)/i', $specification, $match)) {
            if ($reading === null) {
                return null;
            }

            return $reading >= (float) str_replace(',', '', $match[1]) && $reading <= (float) str_replace(',', '', $match[2]) ? 'pass' : 'fail';
        }
        if (preg_match('/^(absent|not detected|nd|negative|none)\b/i', $specification)) {
            if (preg_match('/^(absent|not detected|nd|negative|none|<)/i', $value) || $reading === 0.0) {
                return 'pass';
            }

            return preg_match('/^(present|detected|positive)/i', $value) || ($reading !== null && $reading > 0) ? 'fail' : null;
        }

        return null;
    }

    /**
     * The number in a result value ("12.5 mg/L" is 12.5), or null when it has none.
     */
    protected function numeric(string $value): ?float
    {
        return preg_match('/^-?[\d,]*\.?\d+/', str_replace(' ', '', $value), $match) ? (float) str_replace(',', '', $match[0]) : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'samples') {
            $record->occurs_on ??= today();
            $record->due_on ??= $record->occurs_on->copy()->addDays(self::TURNAROUND_DAYS);
            if ($record->status === 'certificate_issued' && ! $record->value('_certificate_no')) {
                $this->put($record, ['_certificate_no' => 'COA-'.$record->occurs_on->format('Y').'-'.str_pad((string) ($this->records('samples')->get()->filter(fn (Record $sample) => $sample->value('_certificate_no'))->count() + 1), 4, '0', STR_PAD_LEFT), '_issued_on' => today()->toDateString()]);
            }

            return;
        }

        $record->occurs_on ??= today();
        if ($record->status !== 'inconclusive' && ($verdict = $this->judge((string) $record->value('value'), (string) $record->value('specification')))) {
            $record->status = $verdict;
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'results') {
            return;
        }
        $this->progress($this->parent($record, 'sample'));
        $this->progress($this->previousParent($record, 'sample'));
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'results') {
            $this->progress($this->parent($record, 'sample'));
        }
    }

    /**
     * Move an open sample between received, testing and results ready from its results.
     */
    protected function progress(?Record $sample): void
    {
        if (! $sample || in_array($sample->status, self::CLOSED, true)) {
            return;
        }
        $results = $this->linked('results', 'sample', $sample)->get();
        $status = match (true) {
            $results->isEmpty() => 'received',
            $results->contains('status', 'pending') => 'testing',
            default => 'results_ready',
        };
        if ($sample->status !== $status) {
            $sample->update(['status' => $status]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'samples') {
            return [];
        }

        return [
            ...($record->status === 'results_ready' ? ['certificate' => ['label' => 'Issue certificate', 'icon' => 'file-check']] : []),
            ...(in_array($record->status, ['received', 'testing'], true) ? ['reject' => ['label' => 'Reject sample', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]] : []),
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'reject') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $record->update(['status' => 'rejected', 'data' => [...$record->data, '_reject_reason' => $reason]]);

            return $record->number.' rejected: '.$reason.'.';
        }

        if ($problem = $this->notReady($record)) {
            throw ValidationException::withMessages(['status' => $problem]);
        }
        $record->update(['status' => 'certificate_issued']);
        $failed = $this->linked('results', 'sample', $record)->where('status', 'fail')->count();

        return 'Certificate '.$record->value('_certificate_no').' issued'.($failed ? ' with '.$failed.' '.str('result')->plural($failed).' out of specification.' : '; all results within specification.');
    }

    public function documents(Record $record): array
    {
        return $record->entity === 'samples' && $record->status === 'certificate_issued' ? ['certificate' => 'Certificate of analysis'] : [];
    }

    public function document(string $name, Record $record): ?array
    {
        if ($name !== 'certificate' || $record->entity !== 'samples' || $record->status !== 'certificate_issued') {
            return null;
        }
        $results = $this->linked('results', 'sample', $record)->orderBy('id')->get();
        $failed = $results->where('status', 'fail')->count();

        return ['view' => 'apps.logic.document', 'data' => [
            'heading' => 'Certificate of analysis',
            'meta' => array_filter([
                'Certificate' => $record->value('_certificate_no'),
                'Client' => $record->contact?->name,
                'Sample' => $record->title.' ('.$record->value('sample_number').')',
                'Matrix' => ucfirst((string) $record->value('matrix')),
                'Batch' => $record->value('batch_number'),
                'Received' => $record->occurs_on?->format('d M Y'),
                'Issued' => $record->value('_issued_on') ? Carbon::parse($record->value('_issued_on'))->format('d M Y') : null,
            ]),
            'columns' => ['Parameter', 'Method', 'Result', 'Specification', 'Outcome'],
            'rows' => $results->map(fn (Record $result) => [$result->title, $result->value('method') ?: '—', trim($result->value('value').' '.$result->value('unit')), $result->value('specification') ?: '—', ucfirst($result->status)])->values()->all(),
            'totals' => ['Conclusion' => $failed ? $failed.' of '.$results->count().' results out of specification' : 'Complies with specification'],
        ]];
    }

    public function homeCards(): array
    {
        $overdue = $this->records('samples')->whereNotIn('status', [...self::CLOSED, 'results_ready'])->where('due_on', '<', today()->startOfDay())->orderBy('due_on')->get();
        $ready = $this->records('samples')->where('status', 'results_ready')->orderBy('due_on')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Overdue samples', 'icon' => 'clock', 'empty' => 'Nothing overdue.',
                'rows' => $overdue->map(fn (Record $sample) => ['label' => $sample->value('sample_number').' · '.$sample->title, 'sub' => $sample->contact?->name, 'value' => 'Due '.$sample->due_on->format('d M'), 'href' => $sample->url(), 'tone' => 'danger'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Ready for certificate', 'icon' => 'file-check', 'empty' => 'No samples waiting.',
                'rows' => $ready->map(fn (Record $sample) => ['label' => $sample->value('sample_number').' · '.$sample->title, 'sub' => $sample->contact?->name, 'value' => $sample->due_on?->format('d M'), 'href' => $sample->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $samples = $this->dated('samples', $from, $to)->get();
        $matrix = fn (Record $sample) => ucfirst((string) $sample->value('matrix')) ?: '—';

        $turnaround = $samples->where('status', 'certificate_issued')->groupBy($matrix)->sortKeys()
            ->map(function (Collection $group, string $name) {
                $days = $group->map(fn (Record $sample) => $sample->occurs_on->diffInDays(Carbon::parse($sample->value('_issued_on') ?? $sample->updated_at)));
                $onTime = $group->filter(fn (Record $sample) => ! $sample->due_on || Carbon::parse($sample->value('_issued_on') ?? $sample->updated_at)->lte($sample->due_on))->count();

                return [$name, $group->count(), round($days->avg(), 1).' days', round($onTime / $group->count() * 100).'%'];
            })->values()->all();

        $matrices = $samples->mapWithKeys(fn (Record $sample) => [$sample->id => $matrix($sample)]);
        $results = $this->records('results')->get()->filter(fn (Record $result) => $matrices->has((int) $result->value('sample')) && $result->status !== 'pending');
        $passRate = $results->groupBy(fn (Record $result) => $matrices[(int) $result->value('sample')])->sortKeys()
            ->map(fn (Collection $group, string $name) => [$name, $group->count(), $group->where('status', 'pass')->count(), $group->where('status', 'fail')->count(), round($group->where('status', 'pass')->count() / $group->count() * 100).'%'])
            ->values()->all();

        $rejected = $samples->where('status', 'rejected')->map(fn (Record $sample) => [$sample->value('sample_number'), $sample->title, $sample->contact?->name ?? '—', $sample->value('_reject_reason') ?? '—'])->values()->all();

        return [
            ['title' => 'Turnaround by matrix', 'columns' => ['Matrix', 'Certificates', 'Average turnaround', 'On time'], 'rows' => $turnaround],
            ['title' => 'Pass rate by matrix', 'columns' => ['Matrix', 'Results', 'Pass', 'Fail', 'Pass rate'], 'rows' => $passRate],
            ['title' => 'Rejected samples', 'columns' => ['Sample', 'Description', 'Client', 'Reason'], 'rows' => $rejected],
        ];
    }
}
