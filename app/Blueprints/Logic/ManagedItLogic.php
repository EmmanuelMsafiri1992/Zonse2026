<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * IT services & MSP: a managed client needs the number of users covered. Devices and credential notes can't
 * be added for clients who have ended, and each serial number belongs to one device. Rotating a credential
 * keeps the old note as rotated and opens a fresh current one. Clients show their devices and the warranties
 * running out; the home page shows renewals and warranties due in the next 30 days.
 */
class ManagedItLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'clients') {
            if ($payload['status'] === 'managed' && (int) ($data['users_covered'] ?? 0) < 1) {
                $errors['data.users_covered'] = 'Give the number of users covered.';
            }

            return $errors;
        }
        if (! $existing && filled($data['client'] ?? null) && ($client = $this->records('clients')->find($data['client'])) && $client->status === 'ended') {
            $errors['data.client'] = $client->title.' has ended.';
        }
        if ($entity->key === 'devices' && filled($serial = strtoupper(trim((string) ($data['serial_number'] ?? ''))))) {
            $taken = $this->records('devices')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $device) => strtoupper(trim((string) $device->value('serial_number'))) === $serial);
            if ($taken) {
                $errors['data.serial_number'] = 'Serial number '.$serial.' is already on '.$taken->title.'.';
            }
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'devices' && filled($record->value('serial_number'))) {
            $this->put($record, ['serial_number' => strtoupper(trim((string) $record->value('serial_number')))]);
        }
    }

    public function actions(Record $record): array
    {
        return match (true) {
            $record->entity === 'clients' && $record->status === 'onboarding' => ['go_live' => ['label' => 'Fully onboarded', 'icon' => 'check']],
            $record->entity === 'devices' && $record->status === 'active' => ['repair' => ['label' => 'Send for repair', 'icon' => 'wrench'], 'retire' => ['label' => 'Retire', 'icon' => 'archive']],
            $record->entity === 'devices' && $record->status === 'in_repair' => ['back' => ['label' => 'Back in service', 'icon' => 'check'], 'retire' => ['label' => 'Retire', 'icon' => 'archive']],
            $record->entity === 'credentials' && $record->status === 'current' => ['rotate' => ['label' => 'Rotated', 'icon' => 'refresh-cw']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'go_live':
                if ((int) $record->value('users_covered') < 1) {
                    throw ValidationException::withMessages(['users_covered' => 'Give the number of users covered.']);
                }
                $record->update(['status' => 'managed']);

                return $record->title.' is now managed.';
            case 'repair':
                $record->update(['status' => 'in_repair']);

                return $record->title.' sent for repair'.(filled($record->value('warranty_until')) && Carbon::parse($record->value('warranty_until'))->gte(today()) ? ' under warranty' : '').'.';
            case 'back':
                $record->update(['status' => 'active']);

                return $record->title.' is back in service.';
            case 'retire':
                $record->update(['status' => 'retired']);

                return $record->title.' retired.';
            default:
                $record->update(['status' => 'rotated', 'data' => [...$record->data, '_rotated_on' => today()->toDateString()]]);
                Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'credentials', 'title' => $record->title,
                    'status' => 'current', 'data' => collect($record->data)->except('_rotated_on')->all(),
                ]);

                return $record->title.' rotated; a new current note is open.';
        }
    }

    /**
     * Whether a device's warranty ends within the given number of days.
     */
    protected function warrantyEnding(Record $device, int $days): bool
    {
        $until = $device->value('warranty_until');

        return $device->status !== 'retired' && filled($until) && Carbon::parse($until)->between(today(), today()->addDays($days));
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'clients') {
            return [];
        }
        $devices = $this->linked('devices', 'client', $record)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Estate', 'icon' => 'monitor', 'stats' => [
                ['label' => 'Devices in use', 'value' => $devices->where('status', 'active')->count()],
                ['label' => 'In repair', 'value' => $devices->where('status', 'in_repair')->count()],
                ['label' => 'Credential notes', 'value' => $this->linked('credentials', 'client', $record)->where('status', 'current')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Warranties ending in 60 days', 'icon' => 'shield-alert', 'empty' => 'No warranties ending soon.',
                'rows' => $devices->filter(fn (Record $device) => $this->warrantyEnding($device, 60))->sortBy(fn (Record $device) => $device->value('warranty_until'))
                    ->map(fn (Record $device) => ['label' => $device->title, 'sub' => ucfirst((string) $device->value('type')), 'value' => Carbon::parse($device->value('warranty_until'))->format('d M Y'), 'href' => $device->url(), 'tone' => 'warning'])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $clients = $this->records('clients')->where('status', '!=', 'ended')->get();

        return [
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Renewals in the next 30 days', 'icon' => 'refresh-cw', 'empty' => 'No renewals coming up.',
                'rows' => $clients->filter(fn (Record $client) => filled($client->value('renewal_date')) && Carbon::parse($client->value('renewal_date'))->lte(today()->addDays(30)))
                    ->sortBy(fn (Record $client) => $client->value('renewal_date'))
                    ->map(fn (Record $client) => ['label' => $client->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $client->value('agreement'))), 'value' => Carbon::parse($client->value('renewal_date'))->format('d M Y'), 'href' => $client->url(), 'tone' => Carbon::parse($client->value('renewal_date'))->lt(today()) ? 'danger' : 'warning'])->values()->all(),
            ]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Warranties ending in 30 days', 'icon' => 'shield-alert', 'empty' => 'No warranties ending soon.',
                'rows' => $this->records('devices')->get()->filter(fn (Record $device) => $this->warrantyEnding($device, 30))->sortBy(fn (Record $device) => $device->value('warranty_until'))
                    ->map(fn (Record $device) => ['label' => $device->title, 'sub' => $clients->firstWhere('id', (int) $device->value('client'))?->title ?? '', 'value' => Carbon::parse($device->value('warranty_until'))->format('d M'), 'href' => $device->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $devices = $this->records('devices')->where('status', '!=', 'retired')->get()->groupBy(fn (Record $device) => (int) $device->value('client'));

        return [['title' => 'Managed clients', 'columns' => ['Client', 'Agreement', 'Users', 'Devices', 'Monthly fee'], 'rows' => $this->records('clients')->where('status', '!=', 'ended')->orderBy('title')->get()
            ->map(fn (Record $client) => [$client->title, ucfirst(str_replace('_', ' ', (string) $client->value('agreement'))), (int) $client->value('users_covered'), $devices->get($client->id, collect())->count(), $this->money($client->amount)])
            ->all()]];
    }
}
