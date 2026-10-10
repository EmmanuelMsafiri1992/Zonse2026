<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Contractor site access: a worker is inducted on a date and the induction lasts twelve months unless
 * an expiry is given. Only an inducted worker whose induction and medical are both still valid can sign
 * in, and nobody can be signed in twice. Signing out records the time out, which must be after the time
 * in, and the hours on site. Each day, workers whose induction or medical has lapsed are expired.
 * Blocking a worker needs a reason.
 */
class ContractorAccessLogic extends AppLogic
{
    /**
     * Months an induction stays valid.
     */
    protected const INDUCTION_MONTHS = 12;

    /**
     * Days ahead that an expiring induction or medical is shown.
     */
    protected const WARN_DAYS = 30;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'workers') {
            if ($payload['status'] === 'inducted' && blank($data['induction_date'] ?? null)) {
                $errors['data.induction_date'] = 'Enter the date the worker was inducted.';
            }
            if (filled($data['induction_date'] ?? null) && filled($data['induction_expiry'] ?? null) && Carbon::parse($data['induction_expiry'])->lte(Carbon::parse($data['induction_date']))) {
                $errors['data.induction_expiry'] = 'The induction must expire after the induction date.';
            }
            if ($payload['status'] === 'inducted' && ($lapsed = $this->lapsed($data))) {
                $errors['status'] = 'The worker\'s '.$lapsed.' has expired.';
            }
            if ($payload['status'] === 'blocked' && $existing?->status !== 'blocked') {
                $errors['status'] = 'Use "Block" so the reason is recorded.';
            }

            return $errors;
        }

        $worker = filled($data['worker'] ?? null) ? $this->records('workers')->find($data['worker']) : null;
        if (filled($data['time_out'] ?? null) && filled($data['time_in'] ?? null) && $data['time_out'] <= $data['time_in']) {
            $errors['data.time_out'] = 'The time out must be after the time in.';
        }
        if ($payload['status'] === 'signed_out' && blank($data['time_out'] ?? null)) {
            $errors['data.time_out'] = 'Enter the time the worker left.';
        }
        if ($worker && ! $existing) {
            if ($problem = $this->cannotEnter($worker, Carbon::parse($payload['occurs_on'] ?? today()))) {
                $errors['data.worker'] = $problem;
            }
        }
        if ($worker && $payload['status'] === 'on_site' && blank($data['time_out'] ?? null) && ($there = $this->onSite($worker, $existing?->id))) {
            $errors['data.worker'] = $worker->title.' is already signed in ('.$there->number.').';
        }

        return $errors;
    }

    /**
     * Which of the worker's induction or medical has lapsed, if either.
     *
     * @param  array<string, mixed>  $data
     */
    protected function lapsed(array $data, ?Carbon $on = null): ?string
    {
        $on ??= today();
        $expiry = $data['induction_expiry'] ?? (filled($data['induction_date'] ?? null) ? Carbon::parse($data['induction_date'])->addMonths(self::INDUCTION_MONTHS)->toDateString() : null);
        if (filled($expiry) && Carbon::parse($expiry)->lt($on)) {
            return 'induction';
        }
        if (filled($data['medical_expiry'] ?? null) && Carbon::parse($data['medical_expiry'])->lt($on)) {
            return 'medical';
        }

        return null;
    }

    /**
     * Why the worker can't sign in on the date, if they can't.
     */
    protected function cannotEnter(Record $worker, Carbon $on): ?string
    {
        if ($worker->status !== 'inducted') {
            return $worker->title.' is '.$worker->status.($worker->status === 'blocked' && $worker->value('_block_reason') ? ': '.$worker->value('_block_reason') : '').'.';
        }
        if ($lapsed = $this->lapsed((array) $worker->data, $on)) {
            return $worker->title.'\'s '.$lapsed.' has expired.';
        }

        return null;
    }

    /**
     * The worker's open sign-in.
     */
    protected function onSite(Record $worker, ?int $except = null): ?Record
    {
        return $this->linked('sign_ins', 'worker', $worker)->where('status', 'on_site')->when($except, fn ($query) => $query->whereKeyNot($except))->first();
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'workers') {
            if ($record->value('induction_date') && ! $record->value('induction_expiry')) {
                $this->put($record, ['induction_expiry' => Carbon::parse($record->value('induction_date'))->addMonths(self::INDUCTION_MONTHS)->toDateString()]);
            }

            return;
        }

        $record->occurs_on ??= today();
        $worker = $this->parent($record, 'worker');
        if ($worker) {
            $record->title = $worker->title;
        }
        if ($record->value('time_out')) {
            $record->status = 'signed_out';
        }
        $this->put($record, [
            '_company' => $worker?->value('company'),
            '_hours' => $record->value('time_in') && $record->value('time_out') ? round(Carbon::parse($record->value('time_in'))->diffInMinutes(Carbon::parse($record->value('time_out'))) / 60, 2) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'sign_ins') {
            return $record->status === 'on_site' ? ['sign_out' => ['label' => 'Sign out', 'icon' => 'log-out', 'fields' => [['name' => 'time_out', 'label' => 'Time out', 'type' => 'time', 'value' => now()->format('H:i')]]]] : [];
        }

        return [
            ...(in_array($record->status, ['pending', 'expired'], true) ? ['induct' => ['label' => 'Induct', 'icon' => 'badge-check', 'fields' => [
                ['name' => 'induction_date', 'label' => 'Induction date', 'type' => 'date', 'value' => today()->toDateString()],
                ['name' => 'medical_expiry', 'label' => 'Medical expiry', 'type' => 'date', 'value' => $record->value('medical_expiry')],
            ]]] : []),
            ...($record->status !== 'blocked' ? ['block' => ['label' => 'Block from site', 'icon' => 'ban', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]]] : ['unblock' => ['label' => 'Lift block', 'icon' => 'check']]),
        ];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'sign_out') {
            $timeOut = $request->validate(['time_out' => ['required', 'date_format:H:i']])['time_out'];
            if ($timeOut <= (string) $record->value('time_in')) {
                throw ValidationException::withMessages(['time_out' => 'The time out must be after the time in.']);
            }
            $record->update(['data' => [...$record->data, 'time_out' => $timeOut]]);

            return $record->title.' signed out after '.$record->value('_hours').' hours.';
        }

        if ($action === 'block') {
            $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
            $open = $this->onSite($record);
            $record->update(['status' => 'blocked', 'data' => [...$record->data, '_block_reason' => $reason]]);
            $open?->update(['data' => [...$open->data, 'time_out' => max(now()->format('H:i'), Carbon::parse($open->value('time_in'))->addMinute()->format('H:i'))]]);

            return $record->title.' blocked from site'.($open ? ' and signed out' : '').'.';
        }

        if ($action === 'unblock') {
            $status = filled($record->value('induction_date')) && ! $this->lapsed((array) $record->data) ? 'inducted' : 'pending';
            $record->update(['status' => $status, 'data' => [...$record->data, '_block_reason' => null]]);

            return $record->title.' can come back to site ('.$status.').';
        }

        $values = $request->validate(['induction_date' => ['required', 'date', 'before_or_equal:today'], 'medical_expiry' => ['nullable', 'date']]);
        $data = [...$record->data, 'induction_date' => $values['induction_date'], 'induction_expiry' => Carbon::parse($values['induction_date'])->addMonths(self::INDUCTION_MONTHS)->toDateString(), 'medical_expiry' => $values['medical_expiry'] ?? $record->value('medical_expiry')];
        if ($lapsed = $this->lapsed($data)) {
            throw ValidationException::withMessages([$lapsed === 'medical' ? 'medical_expiry' : 'induction_date' => 'The worker\'s '.$lapsed.' has expired.']);
        }
        $record->update(['status' => 'inducted', 'data' => $data]);

        return $record->title.' inducted until '.Carbon::parse($record->value('induction_expiry'))->format('d M Y').'.';
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('workers')->where('status', 'inducted')->get()->filter(fn (Record $worker) => $this->lapsed((array) $worker->data));
        $lapsed->each(fn (Record $worker) => $worker->update(['status' => 'expired']));

        return $lapsed->count();
    }

    public function homeCards(): array
    {
        $onSite = $this->records('sign_ins')->where('status', 'on_site')->orderBy('occurs_on')->get();
        $soon = today()->addDays(self::WARN_DAYS);
        $expiring = $this->records('workers')->where('status', 'inducted')->get()
            ->map(function (Record $worker) use ($soon) {
                $dates = collect(['Induction' => $worker->value('induction_expiry'), 'Medical' => $worker->value('medical_expiry')])->filter()->map(fn (string $date) => Carbon::parse($date))->filter(fn (Carbon $date) => $date->lte($soon));

                return $dates->isEmpty() ? null : ['worker' => $worker, 'what' => $dates->sort()->keys()->first(), 'date' => $dates->sort()->first()];
            })->filter()->sortBy(fn (array $row) => $row['date'])->values();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'On site now ('.$onSite->count().')', 'icon' => 'hard-hat', 'empty' => 'Nobody is signed in.',
                'rows' => $onSite->map(fn (Record $signIn) => ['label' => $signIn->title, 'sub' => trim($signIn->value('_company').' · '.$signIn->value('area'), ' ·'), 'value' => ($signIn->occurs_on->isToday() ? '' : $signIn->occurs_on->format('d M').' ').'in '.$signIn->value('time_in'), 'href' => $signIn->url(), 'tone' => $signIn->occurs_on->isToday() ? null : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Expiring in '.self::WARN_DAYS.' days', 'icon' => 'calendar-clock', 'empty' => 'Nothing expiring.',
                'rows' => $expiring->map(fn (array $row) => ['label' => $row['worker']->title, 'sub' => $row['worker']->value('company'), 'value' => $row['what'].' '.$row['date']->format('d M'), 'href' => $row['worker']->url(), 'tone' => 'warning'])->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $signIns = $this->dated('sign_ins', $from, $to)->get();

        $byCompany = $signIns->groupBy(fn (Record $signIn) => trim((string) $signIn->value('_company')) ?: '—')->sortKeys()
            ->map(fn (Collection $group, string $company) => [$company, $group->pluck('data.worker')->unique()->count(), $group->count(), number_format($group->sum(fn (Record $signIn) => $this->number($signIn, '_hours')), 1)])
            ->values()->all();

        $blocked = $this->records('workers')->whereIn('status', ['blocked', 'expired'])->orderBy('title')->get()
            ->map(fn (Record $worker) => [$worker->title, $worker->value('company'), ucfirst($worker->status), $worker->status === 'blocked' ? ($worker->value('_block_reason') ?? '—') : ucfirst((string) $this->lapsed((array) $worker->data)).' expired'])->values()->all();

        return [
            ['title' => 'Hours on site by company', 'columns' => ['Company', 'Workers', 'Sign-ins', 'Hours'], 'rows' => $byCompany],
            ['title' => 'Workers kept off site', 'columns' => ['Worker', 'Company', 'Status', 'Reason'], 'rows' => $blocked],
        ];
    }
}
