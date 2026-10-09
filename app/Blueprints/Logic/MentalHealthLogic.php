<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Counselling and therapy practice: a client is seen only once consent is signed, and a high-risk
 * client always has a named therapist. Sessions are booked, then attended (with notes) or missed;
 * the client's file counts sessions and missed appointments, and three misses in a row flag the
 * client as disengaged so the therapist can follow up. Session notes stay on the session itself.
 */
class MentalHealthLogic extends AppLogic
{
    /**
     * Missed sessions in a row after which a client is flagged as disengaged.
     */
    protected const MISSES_TO_FLAG = 3;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'clients') {
            if ($payload['status'] === 'active' && empty($data['consent_signed'])) {
                $errors['data.consent_signed'] = 'The client must sign consent before therapy starts.';
            }
            if (($data['risk_level'] ?? null) === 'high' && blank($payload['assignee_id'] ?? null)) {
                $errors['assignee_id'] = 'A high-risk client needs a named therapist.';
            }

            return $errors;
        }

        $client = filled($data['client'] ?? null) ? $this->records('clients')->find($data['client']) : null;
        if ($client && $client->status === 'discharged' && $payload['status'] === 'booked') {
            $errors['data.client'] = $client->title.' has been discharged.';
        }
        if ($client && ! $client->value('consent_signed') && in_array($payload['status'], ['booked', 'attended'], true)) {
            $errors['data.client'] = $client->title.' has not signed consent yet.';
        }
        if ($payload['status'] === 'attended') {
            if (blank($data['notes'] ?? null)) {
                $errors['data.notes'] = 'Write the session notes.';
            }
            if (filled($payload['occurs_on'] ?? null) && Carbon::parse($payload['occurs_on'])->isFuture()) {
                $errors['occurs_on'] = 'A session cannot be attended before its date.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'clients' || ! $record->exists) {
            return;
        }
        $sessions = $this->linked('sessions', 'client', $record)->orderBy('occurs_on')->orderBy('id')->get();
        $held = $sessions->whereIn('status', ['attended', 'missed']);
        $streak = 0;
        foreach ($held->reverse() as $session) {
            if ($session->status !== 'missed') {
                break;
            }
            $streak++;
        }
        $this->put($record, [
            '_attended' => $sessions->where('status', 'attended')->count(),
            '_missed' => $sessions->where('status', 'missed')->count(),
            '_missed_in_a_row' => $streak,
            '_disengaged' => $streak >= self::MISSES_TO_FLAG,
            '_last_session' => $sessions->where('status', 'attended')->last()?->occurs_on?->toDateString(),
            '_next_session' => $sessions->where('status', 'booked')->filter(fn (Record $session) => $session->occurs_on?->gte(today()))->first()?->occurs_on?->toDateString(),
        ]);
        if ($record->status === 'intake' && $record->value('_attended') > 0) {
            $record->status = 'active';
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'sessions') {
            $this->recalculate($this->parent($record, 'client'));
            $this->recalculate($this->previousParent($record, 'client'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'sessions') {
            $this->recalculate($this->parent($record, 'client'));
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'clients') {
            return match ($record->status) {
                'intake' => ['consent' => ['label' => 'Consent signed', 'icon' => 'file-signature']],
                'active' => ['hold' => ['label' => 'Put on hold', 'icon' => 'pause'], 'discharge' => ['label' => 'Discharge', 'icon' => 'log-out']],
                'on_hold' => ['resume' => ['label' => 'Resume', 'icon' => 'play'], 'discharge' => ['label' => 'Discharge', 'icon' => 'log-out']],
                default => [],
            };
        }

        return $record->status === 'booked' ? [
            'attend' => ['label' => 'Attended', 'icon' => 'check', 'fields' => [
                ['name' => 'notes', 'label' => 'Session notes (confidential)', 'type' => 'textarea'],
                ['name' => 'homework', 'label' => 'Homework', 'type' => 'textarea'],
            ]],
            'miss' => ['label' => 'Missed', 'icon' => 'user-x'],
            'cancel' => ['label' => 'Cancel', 'icon' => 'x'],
        ] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($record->entity === 'clients') {
            return match ($action) {
                'consent' => $this->moveClient($record, ['consent_signed' => true], 'active', $record->title.' signed consent and is now active.'),
                'hold' => $this->moveClient($record, [], 'on_hold', $record->title.' is on hold.'),
                'resume' => $this->moveClient($record, [], 'active', $record->title.' is active again.'),
                default => $this->moveClient($record, ['_discharged_on' => today()->toDateString()], 'discharged', $record->title.' discharged after '.(int) $record->value('_attended').' session(s).'),
            };
        }

        $client = $this->parent($record, 'client');
        if ($action === 'attend') {
            $input = $request->validate(['notes' => ['required', 'string'], 'homework' => ['nullable', 'string']]);
            if ($record->occurs_on?->isFuture()) {
                throw ValidationException::withMessages(['notes' => 'This session is on '.$record->occurs_on->format('d M Y').'.']);
            }
            $record->update(['status' => 'attended', 'occurs_on' => $record->occurs_on ?? today(), 'data' => [...$record->data, 'notes' => $input['notes'], 'homework' => $input['homework'] ?? null]]);

            return 'Session with '.($client?->title ?? $record->title).' recorded.';
        }
        if ($action === 'miss') {
            $record->update(['status' => 'missed']);
            $client = $client?->fresh();

            return 'Session marked missed'.($client?->value('_disengaged') ? '; '.$client->title.' has missed '.$client->value('_missed_in_a_row').' in a row — follow up.' : '.');
        }
        $record->update(['status' => 'cancelled']);

        return 'Session cancelled.';
    }

    /**
     * Move a client to another status with some extra values.
     *
     * @param  array<string, mixed>  $values
     */
    protected function moveClient(Record $client, array $values, string $status, string $message): string
    {
        $client->update(['status' => $status, 'data' => [...$client->data, ...$values]]);

        return $message;
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'clients') {
            return [];
        }
        $date = fn (?string $value) => $value ? Carbon::parse($value)->format('d M Y') : '—';

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Therapy', 'icon' => 'brain', 'stats' => [
                ['label' => 'Risk', 'value' => ucfirst((string) ($record->value('risk_level') ?? 'low')), 'tone' => $record->value('risk_level') === 'high' ? 'danger' : ($record->value('risk_level') === 'moderate' ? 'warning' : null)],
                ['label' => 'Sessions attended', 'value' => (string) (int) $record->value('_attended')],
                ['label' => 'Missed', 'value' => (string) (int) $record->value('_missed'), 'tone' => $record->value('_disengaged') ? 'danger' : null],
                ['label' => 'Last session', 'value' => $date($record->value('_last_session'))],
                ['label' => 'Next session', 'value' => $date($record->value('_next_session'))],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $clients = $this->records('clients')->whereIn('status', ['intake', 'active', 'on_hold'])->get();
        $today = $this->records('sessions')->whereDate('occurs_on', today())->get();
        $watch = $clients->filter(fn (Record $client) => $client->value('risk_level') === 'high' || $client->value('_disengaged'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Practice', 'icon' => 'brain', 'stats' => [
                ['label' => 'Active clients', 'value' => (string) $clients->where('status', 'active')->count()],
                ['label' => 'Waiting on consent', 'value' => (string) $clients->where('status', 'intake')->count()],
                ['label' => 'Sessions today', 'value' => (string) $today->whereIn('status', ['booked', 'attended'])->count()],
                ['label' => 'High risk', 'value' => (string) $clients->filter(fn (Record $client) => $client->value('risk_level') === 'high')->count(), 'tone' => $watch->isNotEmpty() ? 'danger' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Clients to watch', 'icon' => 'triangle-alert', 'empty' => 'No high-risk or disengaged clients.',
                'rows' => $watch->map(fn (Record $client) => [
                    'label' => $client->title,
                    'sub' => $client->value('_disengaged') ? 'Missed '.$client->value('_missed_in_a_row').' in a row' : 'High risk',
                    'value' => $client->value('_last_session') ? Carbon::parse($client->value('_last_session'))->format('d M') : '—',
                    'href' => $client->url(), 'tone' => 'danger',
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $sessions = $this->dated('sessions', $from, $to)->get();
        $rate = function (Collection $group): string {
            $held = $group->whereIn('status', ['attended', 'missed'])->count();

            return $held ? (int) round($group->where('status', 'attended')->count() / $held * 100).'%' : '—';
        };

        $byModality = $sessions->groupBy(fn (Record $session) => str_replace('_', ' ', ucfirst((string) ($session->value('modality') ?: 'in_person'))))->sortKeys()
            ->map(fn (Collection $group, string $modality) => [$modality, $group->count(), $group->where('status', 'attended')->count(), $group->where('status', 'missed')->count(), $rate($group), $this->money($group->where('status', 'attended')->sum('amount'))])->values()->all();

        $therapists = User::query()->whereIn('id', $sessions->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byTherapist = $sessions->groupBy(fn (Record $session) => $therapists[$session->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn (Collection $group, string $name) => [$name, $group->count(), $group->where('status', 'attended')->count(), $group->pluck('data.client')->unique()->count(), $rate($group)])->values()->all();

        $byMonth = collect($this->months($from, $to))->map(function (string $label, string $month) use ($sessions, $rate) {
            $group = $sessions->filter(fn (Record $session) => $session->occurs_on?->format('Y-m') === $month);

            return [$label, $group->count(), $group->where('status', 'attended')->count(), $group->where('status', 'missed')->count(), $rate($group)];
        })->values()->all();

        return [
            ['title' => 'Sessions by modality', 'columns' => ['Modality', 'Sessions', 'Attended', 'Missed', 'Attendance', 'Fees'], 'rows' => $byModality],
            ['title' => 'Caseload by therapist', 'columns' => ['Therapist', 'Sessions', 'Attended', 'Clients', 'Attendance'], 'rows' => $byTherapist],
            ['title' => 'Attendance by month', 'columns' => ['Month', 'Sessions', 'Attended', 'Missed', 'Attendance'], 'rows' => $byMonth],
        ];
    }
}
