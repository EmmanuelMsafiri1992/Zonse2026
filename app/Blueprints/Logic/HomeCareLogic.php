<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Home and elderly care: carers visit clients at home. A visit is only scheduled for a client who
 * is being cared for (not in hospital, paused or ended), and a carer is never booked into two
 * visits at the same time. The carer starts and completes the visit, recording the tasks done and
 * any concerns; concerns show on the home page. Visits left unstarted from earlier days become missed,
 * and each client shows today's visits against the visits per day in their care plan.
 */
class HomeCareLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'clients') {
            if (filled($data['visits_per_day'] ?? null) && ($data['visits_per_day'] < 1 || $data['visits_per_day'] > 6)) {
                $errors['data.visits_per_day'] = 'Plan between 1 and 6 visits a day.';
            }

            return $errors;
        }

        $client = filled($data['client'] ?? null) ? $this->records('clients')->find($data['client']) : null;
        if ($client && $client->status !== 'active' && $payload['status'] === 'scheduled') {
            $errors['data.client'] = $client->title.' is '.($client->status === 'hospital' ? 'in hospital' : $client->status).'; visits are on hold.';
        }
        if ($payload['status'] === 'scheduled' && filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->lt(today()) && ! $existing) {
            $errors['occurs_on'] = 'Schedule visits for today or later.';
        }
        if (in_array($payload['status'], ['scheduled', 'in_progress'], true) && filled($payload['occurs_on'] ?? null) && filled($data['start_time'] ?? null) && filled($data['carer'] ?? null)
            && $clash = $this->clash(Carbon::parse($payload['occurs_on']), (string) $data['start_time'], (int) $data['carer'], $existing?->id)) {
            $errors['data.start_time'] = (User::query()->whereKey($data['carer'])->value('name') ?? 'The carer').' is already visiting '.($this->parent($clash, 'client')?->title ?? $clash->title).' at '.substr((string) $data['start_time'], 0, 5).'.';
        }
        if ($payload['status'] === 'completed' && blank($data['tasks_done'] ?? null)) {
            $errors['data.tasks_done'] = 'Record the tasks done.';
        }

        return $errors;
    }

    /**
     * Another active visit by the same carer at the same date and time.
     */
    protected function clash(Carbon $date, string $time, int $carer, ?int $except): ?Record
    {
        return $this->records('visits')->whereIn('status', ['scheduled', 'in_progress'])->whereDate('occurs_on', $date)
            ->when($except, fn ($query) => $query->whereKeyNot($except))->get()
            ->first(fn (Record $visit) => (int) $visit->value('carer') === $carer && substr((string) $visit->value('start_time'), 0, 5) === substr($time, 0, 5));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'visits' && $record->status === 'completed' && filled($record->value('_started_at')) && blank($record->value('_minutes'))) {
            $this->put($record, ['_finished_at' => now()->toDateTimeString(), '_minutes' => (int) Carbon::parse($record->value('_started_at'))->diffInMinutes(now())]);
        }
        if ($record->entity === 'visits') {
            $this->put($record, ['_concern' => $record->status === 'completed' && filled($record->value('concerns'))]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'clients') {
            return match ($record->status) {
                'active' => ['hospital' => ['label' => 'Admitted to hospital', 'icon' => 'hospital'], 'pause' => ['label' => 'Pause care', 'icon' => 'pause']],
                'hospital', 'paused' => ['resume' => ['label' => 'Resume care', 'icon' => 'play']],
                default => [],
            };
        }

        return match ($record->status) {
            'scheduled' => ['start' => ['label' => 'Start visit', 'icon' => 'play'], 'miss' => ['label' => 'Missed', 'icon' => 'x']],
            'in_progress' => ['complete' => ['label' => 'Complete', 'icon' => 'check', 'fields' => [
                ['name' => 'tasks_done', 'label' => 'Tasks done', 'type' => 'textarea'],
                ['name' => 'concerns', 'label' => 'Concerns', 'type' => 'textarea'],
            ]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'clients') {
            $status = ['hospital' => 'hospital', 'pause' => 'paused', 'resume' => 'active'][$action];
            $record->update(['status' => $status]);
            $cancelled = 0;
            if ($status !== 'active') {
                $upcoming = $this->linked('visits', 'client', $record)->where('status', 'scheduled')->whereDate('occurs_on', '>=', today())->get();
                $upcoming->each(fn (Record $visit) => $visit->update(['status' => 'missed', 'data' => [...$visit->data, '_reason' => $status === 'hospital' ? 'Client in hospital' : 'Care paused']]));
                $cancelled = $upcoming->count();
            }

            return match ($status) {
                'hospital' => $record->title.' is in hospital; '.$cancelled.' upcoming visit(s) called off.',
                'paused' => $record->title.'\'s care is paused; '.$cancelled.' upcoming visit(s) called off.',
                default => $record->title.'\'s care has resumed.',
            };
        }

        $client = $this->parent($record, 'client');
        if ($action === 'start') {
            if ($record->occurs_on && ! $record->occurs_on->isToday()) {
                throw ValidationException::withMessages(['status' => 'This visit is on '.$record->occurs_on->format('d M Y').'.']);
            }
            $record->update(['status' => 'in_progress', 'data' => [...$record->data, '_started_at' => now()->toDateTimeString()]]);

            return 'Visit to '.($client?->title ?? $record->title).' started.';
        }
        if ($action === 'miss') {
            $record->update(['status' => 'missed']);

            return 'Visit to '.($client?->title ?? $record->title).' marked missed.';
        }
        $input = $request->validate(['tasks_done' => ['required', 'string'], 'concerns' => ['nullable', 'string']]);
        $record->update(['status' => 'completed', 'data' => [...$record->data, 'tasks_done' => $input['tasks_done'], 'concerns' => $input['concerns'] ?? null]]);
        $minutes = (int) $record->fresh()->value('_minutes');

        return 'Visit to '.($client?->title ?? $record->title).' completed after '.$minutes.' min'.(filled($input['concerns'] ?? null) ? '; concern raised.' : '.');
    }

    public function daily(Workspace $workspace): int
    {
        $stale = $this->records('visits')->where('status', 'scheduled')->whereDate('occurs_on', '<', today())->get();
        $stale->each(fn (Record $visit) => $visit->update(['status' => 'missed']));

        return $stale->count();
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'clients') {
            return [];
        }
        $visits = $this->linked('visits', 'client', $record)->orderByDesc('occurs_on')->orderByDesc('id')->get();
        $today = $visits->filter(fn (Record $visit) => $visit->occurs_on?->isToday() && $visit->status !== 'missed');
        $planned = (int) ($record->value('visits_per_day') ?: 1);
        $month = $visits->filter(fn (Record $visit) => $visit->occurs_on?->isSameMonth(today()));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Care', 'icon' => 'hand-heart', 'stats' => [
                ['label' => 'Visits today', 'value' => $today->count().' of '.$planned, 'tone' => $record->status === 'active' && $today->count() < $planned ? 'warning' : null],
                ['label' => 'Completed this month', 'value' => (string) $month->where('status', 'completed')->count()],
                ['label' => 'Missed this month', 'value' => (string) $month->where('status', 'missed')->count(), 'tone' => $month->where('status', 'missed')->isNotEmpty() ? 'danger' : null],
                ['label' => 'Concerns raised', 'value' => (string) $visits->filter(fn (Record $visit) => $visit->value('_concern'))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Recent visits', 'icon' => 'calendar', 'empty' => 'No visits yet.',
                'rows' => $visits->take(10)->map(fn (Record $visit) => [
                    'label' => $visit->occurs_on?->format('d M').' '.substr((string) $visit->value('start_time'), 0, 5), 'sub' => (string) $visit->value('concerns'), 'value' => ucfirst(str_replace('_', ' ', $visit->status)), 'href' => $visit->url(), 'tone' => $visit->status === 'missed' || $visit->value('_concern') ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $today = $this->records('visits')->whereDate('occurs_on', today())->get();
        $concerns = $this->records('visits')->where('status', 'completed')->whereDate('occurs_on', '>=', today()->subWeek())->orderByDesc('occurs_on')->get()->filter(fn (Record $visit) => $visit->value('_concern'));
        $clients = $this->records('clients')->pluck('title', 'id');
        $carers = User::query()->whereIn('id', $today->pluck('data.carer')->merge($concerns->pluck('data.carer'))->filter()->unique())->pluck('name', 'id');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Visits today', 'icon' => 'hand-heart', 'stats' => [
                ['label' => 'Scheduled', 'value' => (string) $today->where('status', 'scheduled')->count()],
                ['label' => 'In progress', 'value' => (string) $today->where('status', 'in_progress')->count()],
                ['label' => 'Completed', 'value' => (string) $today->where('status', 'completed')->count()],
                ['label' => 'Missed', 'value' => (string) $today->where('status', 'missed')->count(), 'tone' => $today->where('status', 'missed')->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Concerns raised', 'icon' => 'triangle-alert', 'empty' => 'No concerns in the last week.',
                'rows' => $concerns->map(fn (Record $visit) => [
                    'label' => $clients[$visit->value('client')] ?? $visit->title, 'sub' => (string) str($visit->value('concerns'))->limit(60), 'value' => ($carers[$visit->value('carer')] ?? '').' · '.$visit->occurs_on?->format('d M'), 'href' => $visit->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->get();
        $carers = User::query()->whereIn('id', $visits->pluck('data.carer')->filter()->unique())->pluck('name', 'id');
        $clients = $this->records('clients')->pluck('title', 'id');

        $byCarer = $visits->groupBy(fn (Record $visit) => $carers[$visit->value('carer')] ?? 'Unassigned')->sortKeys()->map(function (Collection $group, string $name) {
            $timed = $group->where('status', 'completed')->filter(fn (Record $visit) => $visit->value('_minutes') !== null);

            return [$name, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'missed')->count(), $timed->isEmpty() ? '—' : (int) round($timed->avg(fn (Record $visit) => (int) $visit->value('_minutes'))).' min'];
        })->values()->all();

        $byClient = $visits->groupBy(fn (Record $visit) => $clients[$visit->value('client')] ?? 'Unknown')->sortKeys()
            ->map(fn (Collection $group, string $name) => [$name, $group->count(), $group->where('status', 'completed')->count(), $group->where('status', 'missed')->count(), $group->filter(fn (Record $visit) => $visit->value('_concern'))->count()])->values()->all();

        $concerns = $visits->filter(fn (Record $visit) => $visit->value('_concern'))->sortBy('occurs_on')
            ->map(fn (Record $visit) => [$visit->occurs_on?->format('d M Y'), $clients[$visit->value('client')] ?? '', $carers[$visit->value('carer')] ?? '', (string) $visit->value('concerns')])->values()->all();

        return [
            ['title' => 'Visits by carer', 'columns' => ['Carer', 'Visits', 'Completed', 'Missed', 'Average length'], 'rows' => $byCarer],
            ['title' => 'Visits by client', 'columns' => ['Client', 'Visits', 'Completed', 'Missed', 'Concerns'], 'rows' => $byClient],
            ['title' => 'Concerns log', 'columns' => ['Date', 'Client', 'Carer', 'Concern'], 'rows' => $concerns],
        ];
    }
}
