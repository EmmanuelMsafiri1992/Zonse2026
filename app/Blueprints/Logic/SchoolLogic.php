<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Models\Record;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Models\Invoice;

/**
 * School: a class is billed for a term in one go (a fee account and invoice per enrolled
 * student), and fee accounts follow their invoice as the guardian pays.
 */
class SchoolLogic extends AppLogic
{
    public const TERMS = ['term_1' => 'Term 1', 'term_2' => 'Term 2', 'term_3' => 'Term 3', 'annual' => 'Annual'];

    public function newContactDetails(Record $payer): array
    {
        return [
            'name' => (string) ($payer->value('guardian_name') ?: $payer->title),
            'phone' => $payer->value('guardian_phone'),
        ];
    }

    public function invoiceChanged(Record $record, Invoice $invoice): void
    {
        if ($record->entity !== 'fees' || $record->status === 'waived') {
            return;
        }

        $paid = Money::round($record->invoices()->whereNot('status', 'cancelled')->sum('amount_paid'));
        $status = match (true) {
            $paid > 0 && $paid >= Money::round($record->amount) => 'paid',
            $paid > 0 => 'part_paid',
            default => 'unpaid',
        };

        $record->update(['status' => $status, 'data' => array_merge((array) $record->data, ['paid_to_date' => $paid])]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'classes') {
            return [];
        }

        return ['bill_term' => [
            'label' => 'Bill class for a term', 'icon' => 'receipt',
            'confirm' => 'Create a fee account for every enrolled student in this class?',
            'fields' => [
                ['name' => 'term', 'label' => 'Term', 'type' => 'select', 'options' => self::TERMS, 'value' => 'term_1'],
                ['name' => 'description', 'label' => 'Description', 'type' => 'text', 'value' => 'Tuition fees'],
                ['name' => 'amount', 'label' => 'Amount per student', 'type' => 'number'],
                ['name' => 'due_on', 'label' => 'Due date', 'type' => 'date', 'value' => today()->addDays(14)->toDateString()],
            ],
        ]];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $validated = $request->validate([
            'term' => ['required', 'in:'.implode(',', array_keys(self::TERMS))],
            'description' => ['required', 'string', 'max:200'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_on' => ['required', 'date'],
        ]);

        return $this->billClass($record, $validated['term'], $validated['description'], (float) $validated['amount'], Carbon::parse($validated['due_on']));
    }

    /** Create the term's fee account (and invoice, when Invoicing is on) for each enrolled student who has not been billed yet. */
    public function billClass(Record $class, string $term, string $description, float $amount, Carbon $dueOn): string
    {
        $students = $this->linked('students', 'class', $class)->where('status', 'enrolled')->orderBy('title')->get();
        $billed = 0;
        $skipped = 0;

        foreach ($students as $student) {
            $exists = $this->linked('fees', 'student', $student)->where('data->term', $term)->where('title', $description)->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($student, $term, $description, $amount, $dueOn, $class) {
                $fee = Record::create([
                    'blueprint' => $this->app->key, 'entity' => 'fees', 'branch_id' => $student->branch_id,
                    'title' => $description, 'status' => 'unpaid', 'amount' => $amount, 'due_on' => $dueOn,
                    'currency' => $student->workspace?->currency_code,
                    'data' => ['student' => $student->id, 'term' => $term, 'paid_to_date' => 0.0, '_class' => $class->id],
                ]);

                if ($this->billing()->available()) {
                    $this->billing()->invoice($fee, [[
                        'description' => $description.' · '.self::TERMS[$term].' · '.$student->title,
                        'quantity' => 1, 'unit_price' => $amount,
                    ]], null, true, today(), $dueOn);
                }
            });
            $billed++;
        }

        if ($students->isEmpty()) {
            return 'No enrolled students in '.$class->title.' yet.';
        }

        return 'Billed '.$billed.' '.str('student')->plural($billed).' in '.$class->title.' for '.self::TERMS[$term]
            .($skipped ? ' ('.$skipped.' already billed)' : '').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'students') {
            $fees = $this->linked('fees', 'student', $record)->orderByDesc('id')->get();
            $open = $fees->whereIn('status', ['unpaid', 'part_paid']);
            $balance = $open->sum(fn (Record $fee) => (float) $fee->amount - (float) $fee->value('paid_to_date'));

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Fees', 'icon' => 'wallet', 'stats' => [
                ['label' => 'Billed', 'value' => $this->money($fees->whereNotIn('status', ['waived'])->sum('amount'))],
                ['label' => 'Paid', 'value' => $this->money($fees->sum(fn (Record $fee) => (float) $fee->value('paid_to_date')))],
                ['label' => 'Balance', 'value' => $this->money($balance), 'tone' => $balance > 0 ? 'danger' : 'success'],
            ]]]];
        }

        if ($record->entity === 'classes') {
            $students = $this->linked('students', 'class', $record)->where('status', 'enrolled')->get(['id']);
            $fees = $students->isEmpty() ? collect() : $this->records('fees')->whereIn('data->student', $students->pluck('id')->all())->whereNot('status', 'waived')->get();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Class fees', 'icon' => 'wallet', 'stats' => [
                ['label' => 'Enrolled', 'value' => $students->count().($record->value('capacity') ? ' / '.$record->value('capacity') : '')],
                ['label' => 'Billed', 'value' => $this->money($fees->sum('amount'))],
                ['label' => 'Collected', 'value' => $this->money($fees->sum(fn (Record $fee) => (float) $fee->value('paid_to_date')))],
                ['label' => 'Outstanding', 'value' => $this->money($fees->whereIn('status', ['unpaid', 'part_paid'])->sum(fn (Record $fee) => (float) $fee->amount - (float) $fee->value('paid_to_date')))],
            ]]]];
        }

        return [];
    }

    public function homeCards(): array
    {
        $overdue = $this->records('fees')->whereIn('status', ['unpaid', 'part_paid'])->whereDate('due_on', '<', today())->orderBy('due_on')->limit(10)->get();
        $students = $this->studentNames($overdue);

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Overdue fees', 'icon' => 'alarm-clock', 'empty' => 'No overdue fees.',
            'link' => ['label' => 'All fees', 'href' => route('apps.records.index', [$this->app->key, 'fees', 'status' => 'unpaid'])],
            'rows' => $overdue->map(fn (Record $fee) => [
                'label' => $students[$fee->value('student')] ?? $fee->title, 'sub' => $fee->number.' · '.$fee->title.' · due '.$fee->due_on?->format('d M'),
                'value' => $this->money((float) $fee->amount - (float) $fee->value('paid_to_date')), 'href' => $fee->url(),
            ])->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $fees = $this->records('fees')->whereNot('status', 'waived')->whereBetween('created_at', [$from, $to])->get();
        $students = $this->records('students')->whereKey($fees->map(fn (Record $fee) => $fee->value('student'))->unique()->all())->get()->keyBy('id');
        $classes = $this->records('classes')->pluck('title', 'id');
        $owing = fn (Record $fee) => max(0, (float) $fee->amount - (float) $fee->value('paid_to_date'));

        $arrears = $fees->filter(fn (Record $fee) => $owing($fee) > 0)
            ->groupBy(fn (Record $fee) => $classes->get($students->get($fee->value('student'))?->value('class')) ?? 'No class')
            ->map(fn ($group, $class) => [$class, $group->pluck('data.student')->unique()->count(), $this->money($group->sum($owing))])
            ->sortBy(0)->values()->all();

        $terms = $fees->groupBy(fn (Record $fee) => self::TERMS[$fee->value('term')] ?? 'No term')
            ->map(fn ($group, $term) => [
                $term, $this->money($group->sum('amount')), $this->money($group->sum(fn ($fee) => (float) $fee->value('paid_to_date'))), $this->money($group->sum($owing)),
                $group->sum('amount') > 0 ? round($group->sum(fn ($fee) => (float) $fee->value('paid_to_date')) / $group->sum('amount') * 100).'%' : '—',
            ])->values()->all();

        return [
            ['title' => 'Collection by term', 'columns' => ['Term', 'Billed', 'Collected', 'Outstanding', 'Collected %'], 'rows' => $terms],
            ['title' => 'Arrears by class', 'columns' => ['Class', 'Students owing', 'Outstanding'], 'rows' => $arrears],
        ];
    }

    /** @param  iterable<Record>  $fees  @return array<int, string> */
    protected function studentNames(iterable $fees): array
    {
        $ids = collect($fees)->map(fn (Record $fee) => $fee->value('student'))->filter()->unique()->all();

        return $ids ? $this->records('students')->whereKey($ids)->pluck('title', 'id')->all() : [];
    }
}
