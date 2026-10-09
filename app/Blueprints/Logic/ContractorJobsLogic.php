<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Contractor job cards: a technician goes out one job at a time, hours are sensible, regulated
 * trades need a compliance certificate before the job is complete, every completed job is signed
 * off by the customer, and invoiced jobs are locked. Jobs are billed with one click.
 */
class ContractorJobsLogic extends AppLogic
{
    public const ACTIVE = ['on_the_way', 'in_progress'];

    public const CERTIFIED_TRADES = ['electrical', 'gas', 'solar'];

    public const DONE = ['complete', 'invoiced'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($existing?->status === 'invoiced') {
            if ($payload['status'] !== 'invoiced' || (float) $existing->amount !== (float) $payload['amount'] || (float) ($data['hours'] ?? 0) !== (float) $existing->value('hours')) {
                $errors['status'] = 'An invoiced job card is locked.';
            }

            return $errors;
        }
        $hours = (float) ($data['hours'] ?? 0);
        if ($hours < 0 || $hours > 24) {
            $errors['data.hours'] = 'Labour hours must be between 0 and 24.';
        }
        if (in_array($payload['status'], self::ACTIVE, true) && blank($data['technician'] ?? null)) {
            $errors['data.technician'] = 'Assign a technician before the job starts.';
        }
        if (in_array($payload['status'], self::DONE, true)) {
            if (in_array($data['trade'] ?? null, self::CERTIFIED_TRADES, true) && blank($data['certificate_number'] ?? null)) {
                $errors['data.certificate_number'] = ucfirst((string) $data['trade']).' work needs a compliance certificate number before it is complete.';
            }
            if (blank($data['customer_signature'] ?? null)) {
                $errors['data.customer_signature'] = 'Record who signed the job off.';
            }
        }
        if (in_array($payload['status'], self::ACTIVE, true) && filled($data['technician'] ?? null)) {
            $busy = $this->records('jobs')->whereIn('status', self::ACTIVE)->where('data->technician', (int) $data['technician'])
                ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first();
            if ($busy) {
                $errors['data.technician'] = 'This technician is already out on '.$busy->title.' ('.$busy->number.').';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        $this->put($record, [
            '_completed_on' => in_array($record->status, self::DONE, true) ? ($record->value('_completed_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'booked' => ['dispatch' => ['label' => 'On the way', 'icon' => 'car']],
            'on_the_way' => ['start' => ['label' => 'Start work', 'icon' => 'play']],
            'in_progress' => ['complete' => ['label' => 'Complete job', 'icon' => 'check', 'fields' => array_values(array_filter([
                ['name' => 'hours', 'label' => 'Labour hours', 'type' => 'number', 'value' => $this->number($record, 'hours')],
                in_array($record->value('trade'), self::CERTIFIED_TRADES, true) ? ['name' => 'certificate_number', 'label' => 'Compliance certificate', 'type' => 'text', 'value' => $record->value('certificate_number')] : null,
                ['name' => 'customer_signature', 'label' => 'Signed off by', 'type' => 'text', 'value' => $record->value('customer_signature')],
            ]))]],
            'complete' => ['invoice' => ['label' => 'Invoice', 'icon' => 'receipt', 'confirm' => 'Invoice '.$record->title.' for '.$this->money($record->amount).'? The job card is locked afterwards.']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'dispatch':
                $record->update(['status' => 'on_the_way']);

                return 'Technician is on the way to '.$record->title.'.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return 'Work started on '.$record->title.'.';
            case 'invoice':
                $record->update(['status' => 'invoiced']);

                return $record->title.' invoiced for '.$this->money($record->amount).'.';
        }

        $certified = in_array($record->value('trade'), self::CERTIFIED_TRADES, true);
        $input = $request->validate([
            'hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'certificate_number' => [$certified ? 'required' : 'nullable', 'string', 'max:100'],
            'customer_signature' => ['required', 'string', 'max:100'],
        ]);
        $record->update(['status' => 'complete', 'data' => [...(array) $record->data, ...$input]]);

        return $record->title.' completed and signed off by '.$input['customer_signature'].'.';
    }

    public function recordCards(Record $record): array
    {
        $trades = $this->app->entities['jobs']->field('trade')?->options ?? [];
        $technician = $record->value('technician') ? User::query()->find($record->value('technician'))?->name : null;
        $needsCertificate = in_array($record->value('trade'), self::CERTIFIED_TRADES, true) && blank($record->value('certificate_number'));

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Job card', 'icon' => 'wrench', 'stats' => [
            ['label' => 'Trade', 'value' => $trades[$record->value('trade')] ?? ucfirst((string) $record->value('trade'))],
            ['label' => 'Technician', 'value' => $technician ?? 'Unassigned', 'tone' => $technician ? null : 'warning'],
            ['label' => 'Labour', 'value' => (float) $record->value('hours').' h'],
            ['label' => 'Certificate', 'value' => $record->value('certificate_number') ?: ($needsCertificate ? 'Required' : '—'), 'tone' => $needsCertificate ? 'warning' : null],
            ['label' => 'Total', 'value' => $record->amount !== null ? $this->money($record->amount) : '—'],
        ]]]];
    }

    public function homeCards(): array
    {
        $jobs = $this->records('jobs')->with('contact')->get();
        $today = $jobs->whereNotIn('status', self::DONE)->filter(fn (Record $job) => $job->occurs_on?->isToday())->sortBy(fn (Record $job) => array_search($job->status, ['in_progress', 'on_the_way', 'booked'], true));
        $names = User::query()->whereIn('id', $today->pluck('data.technician')->filter()->unique())->pluck('name', 'id');
        $toInvoice = $jobs->where('status', 'complete');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Jobs', 'icon' => 'wrench', 'stats' => [
                ['label' => 'Today', 'value' => (string) $today->count()],
                ['label' => 'Out now', 'value' => (string) $jobs->whereIn('status', self::ACTIVE)->count()],
                ['label' => 'To invoice', 'value' => $this->money($toInvoice->sum('amount')), 'tone' => $toInvoice->isNotEmpty() ? 'warning' : null],
                ['label' => 'Invoiced this month', 'value' => $this->money($jobs->where('status', 'invoiced')->filter(fn (Record $job) => Carbon::parse($job->value('_completed_on'))->isCurrentMonth())->sum('amount'))],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => "Today's jobs", 'icon' => 'calendar-check', 'empty' => 'No jobs booked for today.',
                'rows' => $today->map(fn (Record $job) => [
                    'label' => $job->title, 'sub' => trim(($job->contact?->name ?? '').' · '.($names[$job->value('technician')] ?? 'Unassigned'), ' ·'),
                    'value' => ucfirst(str_replace('_', ' ', $job->status)), 'href' => $job->url(), 'tone' => $job->status === 'in_progress' ? 'success' : ($job->value('technician') ? null : 'warning'),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $jobs = $this->dated('jobs', $from, $to)->get();
        $trades = $this->app->entities['jobs']->field('trade')?->options ?? [];
        $byTrade = collect($trades)->map(fn (string $label, string $key) => [
            $label, $jobs->where('data.trade', $key)->count(), $jobs->where('data.trade', $key)->whereIn('status', self::DONE)->count(),
            (float) $jobs->where('data.trade', $key)->sum(fn (Record $job) => $this->number($job, 'hours')), $this->money($jobs->where('data.trade', $key)->sum('amount')),
        ])->values()->all();

        $names = User::query()->whereIn('id', $jobs->pluck('data.technician')->filter()->unique())->pluck('name', 'id');
        $technicians = $jobs->groupBy(fn (Record $job) => $names[$job->value('technician')] ?? 'Unassigned')->sortKeys()->map(fn ($group, $technician) => [
            $technician, $group->count(), $group->whereIn('status', self::DONE)->count(), (float) $group->sum(fn (Record $job) => $this->number($job, 'hours')), $this->money($group->sum('amount')),
        ])->values()->all();

        return [
            ['title' => 'Jobs by trade', 'columns' => ['Trade', 'Jobs', 'Completed', 'Hours', 'Value'], 'rows' => $byTrade],
            ['title' => 'Technicians', 'columns' => ['Technician', 'Jobs', 'Completed', 'Hours', 'Value'], 'rows' => $technicians],
        ];
    }
}
