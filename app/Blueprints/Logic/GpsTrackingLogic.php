<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * GPS tracking & trip log: a tracker's IMEI is 15 digits and can't be used twice, and removed trackers take no
 * trips or alerts. A trip over the speed limit raises a speeding alert against the tracker. An alert is
 * acknowledged by someone before it is closed, and panic alerts are listed first. Trackers whose subscription has
 * run out go offline each morning, and each tracker shows its distance and open alerts for the last 30 days.
 */
class GpsTrackingLogic extends AppLogic
{
    /**
     * Speed in km/h above which a trip raises an alert.
     */
    public const SPEED_LIMIT = 120;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'trackers') {
            $imei = preg_replace('/\D/', '', (string) ($data['imei'] ?? ''));
            if (filled($data['imei'] ?? null) && strlen($imei) !== 15) {
                $errors['data.imei'] = 'An IMEI has 15 digits.';
            } elseif ($imei !== '' && ($other = $this->records('trackers')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()->first(fn (Record $tracker) => $tracker->value('imei') === $imei))) {
                $errors['data.imei'] = 'This IMEI is already on '.$other->title.'.';
            }

            return $errors;
        }
        if (! $existing && filled($data['tracker'] ?? null) && ($tracker = $this->records('trackers')->find($data['tracker'])) && $tracker->status === 'removed') {
            $errors['data.tracker'] = 'The tracker on '.$tracker->title.' has been removed.';
        }
        if ($entity->key === 'trips') {
            foreach (['distance', 'max_speed', 'idle_minutes'] as $field) {
                if (filled($data[$field] ?? null) && (float) $data[$field] < 0) {
                    $errors['data.'.$field] = 'This can\'t be negative.';
                }
            }
        }
        if ($entity->key === 'alerts' && $payload['status'] === 'acknowledged' && blank($payload['assignee_id'] ?? null)) {
            $errors['assignee_id'] = 'Say who acknowledged the alert.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'trackers') {
            $this->put($record, ['imei' => preg_replace('/\D/', '', (string) $record->value('imei'))]);

            return;
        }
        $record->occurs_on ??= today();
        if ($record->entity === 'trips' && ($tracker = $this->parent($record, 'tracker'))) {
            $record->title = $tracker->title;
        }
    }

    public function saved(Record $record): void
    {
        if ($record->entity !== 'trips' || $this->number($record, 'max_speed') <= self::SPEED_LIMIT || filled($record->value('_alert'))) {
            return;
        }
        $alert = Record::create([
            'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'alerts', 'title' => 'Speeding: '.$record->title,
            'status' => 'new', 'occurs_on' => $record->occurs_on, 'assignee_id' => $record->assignee_id,
            'data' => ['tracker' => $record->value('tracker'), 'type' => 'speeding', 'location' => trim($record->value('from').' → '.$record->value('to'), ' →'), '_trip' => $record->id, '_speed' => $this->number($record, 'max_speed')],
        ]);
        $record->data = [...$record->data, '_alert' => $alert->id];
        $record->saveQuietly();
    }

    public function daily(Workspace $workspace): int
    {
        $lapsed = $this->records('trackers')->where('status', 'online')->get()
            ->filter(fn (Record $tracker) => filled($until = $tracker->value('subscription_until')) && Carbon::parse($until)->lt(today()));
        $lapsed->each(fn (Record $tracker) => $tracker->update(['status' => 'offline']));

        return $lapsed->count();
    }

    public function actions(Record $record): array
    {
        return match ([$record->entity, $record->status]) {
            ['alerts', 'new'] => ['acknowledge' => ['label' => 'Acknowledge', 'icon' => 'check'], 'close' => ['label' => 'Close', 'icon' => 'x']],
            ['alerts', 'acknowledged'] => ['close' => ['label' => 'Close', 'icon' => 'x']],
            ['trips', 'logged'] => ['review' => ['label' => 'Reviewed', 'icon' => 'check']],
            ['trackers', 'online'], ['trackers', 'offline'] => ['remove' => ['label' => 'Remove tracker', 'icon' => 'trash-2']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'acknowledge':
                $record->update(['status' => 'acknowledged', 'assignee_id' => $request->user()->id]);

                return $record->title.' acknowledged.';
            case 'close':
                $record->update(['status' => 'closed', 'assignee_id' => $record->assignee_id ?? $request->user()->id]);

                return $record->title.' closed.';
            case 'review':
                $record->update(['status' => 'reviewed']);

                return $record->title.'\'s trip reviewed.';
            default:
                $record->update(['status' => 'removed']);

                return 'Tracker removed from '.$record->title.'.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'trackers') {
            return [];
        }
        $since = today()->subDays(30)->toDateString();
        $trips = $this->linked('trips', 'tracker', $record)->whereDate('occurs_on', '>=', $since)->get();

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Last 30 days', 'icon' => 'route', 'stats' => [
            ['label' => 'Trips', 'value' => $trips->count()],
            ['label' => 'Distance', 'value' => number_format($trips->sum(fn (Record $trip) => $this->number($trip, 'distance'))).' km'],
            ['label' => 'Top speed', 'value' => (int) $trips->max(fn (Record $trip) => $this->number($trip, 'max_speed')).' km/h'],
            ['label' => 'Open alerts', 'value' => $this->linked('alerts', 'tracker', $record)->whereIn('status', ['new', 'acknowledged'])->count()],
        ]]]];
    }

    public function homeCards(): array
    {
        $trackers = $this->records('trackers')->where('status', '!=', 'removed')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Open alerts', 'icon' => 'siren', 'empty' => 'No open alerts.',
                'rows' => $this->records('alerts')->whereIn('status', ['new', 'acknowledged'])->latest('occurs_on')->latest('id')->get()
                    ->sortBy(fn (Record $alert) => $alert->value('type') === 'panic' ? 0 : 1)
                    ->map(fn (Record $alert) => ['label' => $alert->title, 'sub' => str_replace('_', ' ', (string) $alert->value('type')).(filled($alert->value('location')) ? ' · '.$alert->value('location') : ''), 'value' => $alert->status === 'new' ? 'New' : 'Acknowledged', 'href' => $alert->url(), 'tone' => $alert->value('type') === 'panic' ? 'danger' : ($alert->status === 'new' ? 'warning' : null)])
                    ->values()->all(),
            ]],
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Trackers', 'icon' => 'satellite-dish', 'stats' => [
                ['label' => 'Online', 'value' => $trackers->where('status', 'online')->count()],
                ['label' => 'Offline', 'value' => $trackers->where('status', 'offline')->count()],
                ['label' => 'Subscription ending in 30 days', 'value' => $trackers->filter(fn (Record $tracker) => filled($until = $tracker->value('subscription_until')) && Carbon::parse($until)->between(today(), today()->addDays(30)))->count()],
            ]]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $trips = $this->dated('trips', $from, $to)->get();
        $alerts = $this->dated('alerts', $from, $to)->get();
        $trackers = $this->records('trackers')->pluck('title', 'id');

        return [
            ['title' => 'Distance by vehicle', 'columns' => ['Vehicle', 'Trips', 'Distance (km)', 'Top speed (km/h)', 'Idle (h)'], 'rows' => $trips
                ->groupBy(fn (Record $trip) => $trackers[(int) $trip->value('tracker')] ?? $trip->title)->sortKeys()
                ->map(fn ($group, string $vehicle) => [$vehicle, $group->count(), number_format($group->sum(fn (Record $trip) => $this->number($trip, 'distance')), 1), (int) $group->max(fn (Record $trip) => $this->number($trip, 'max_speed')), round($group->sum(fn (Record $trip) => $this->number($trip, 'idle_minutes')) / 60, 1)])
                ->values()->all()],
            ['title' => 'Alerts by type', 'columns' => ['Type', 'Alerts', 'Still open'], 'rows' => $alerts
                ->groupBy(fn (Record $alert) => ucfirst(str_replace('_', ' ', (string) $alert->value('type'))))->sortKeys()
                ->map(fn ($group, string $type) => [$type, $group->count(), $group->whereIn('status', ['new', 'acknowledged'])->count()])
                ->values()->all()],
        ];
    }
}
