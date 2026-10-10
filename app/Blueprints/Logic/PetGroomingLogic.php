<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Pet grooming & boarding: appointments are only booked for active pets. Boarding and daycare need
 * vaccinations that are valid for the whole stay, and a boarding stay needs a collect-by date after the
 * drop-off date. A visit is checked in, worked on, ready and then collected, and each pet shows its history.
 */
class PetGroomingLogic extends AppLogic
{
    /**
     * Services where the pet stays with other animals.
     *
     * @var list<string>
     */
    public const STAYS = ['boarding', 'daycare'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key !== 'appointments' || blank($data['pet'] ?? null) || ! ($pet = $this->records('pets')->find($data['pet']))) {
            return $errors;
        }
        if (! $existing && $pet->status !== 'active') {
            $errors['data.pet'] = $pet->title.' is inactive.';
        }
        $start = filled($payload['occurs_on'] ?? null) ? Carbon::parse($payload['occurs_on']) : today();
        $end = filled($payload['due_on'] ?? null) ? Carbon::parse($payload['due_on']) : $start;
        if (($data['type'] ?? null) === 'boarding' && $end->lte($start)) {
            $errors['due_on'] = 'A boarding stay needs a collect-by date after the drop-off date.';
        }
        if (in_array($data['type'] ?? null, self::STAYS, true) && in_array($payload['status'], ['booked', 'checked_in'], true) && ($problem = $this->unvaccinated($pet, $end))) {
            $errors['data.pet'] = $problem;
        }

        return $errors;
    }

    /**
     * Why a pet can't stay until the given date, if there is a reason.
     */
    protected function unvaccinated(Record $pet, Carbon $until): ?string
    {
        $valid = $pet->value('vaccinations_until');
        if (blank($valid)) {
            return $pet->title.' has no vaccination record.';
        }

        return Carbon::parse($valid)->lt($until->copy()->startOfDay()) ? $pet->title.'\'s vaccinations run out on '.Carbon::parse($valid)->format('d M Y').'.' : null;
    }

    public function saving(Record $record): void
    {
        if ($record->entity !== 'appointments') {
            return;
        }
        $record->occurs_on ??= today();
        if ($pet = $this->parent($record, 'pet')) {
            $this->put($record, ['_pet' => $pet->title]);
        }
    }

    public function actions(Record $record): array
    {
        if ($record->entity !== 'appointments') {
            return [];
        }

        return match ($record->status) {
            'booked' => ['check_in' => ['label' => 'Check in', 'icon' => 'log-in'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'checked_in' => ['start' => ['label' => 'Start', 'icon' => 'scissors']],
            'in_progress' => ['ready' => ['label' => 'Ready', 'icon' => 'check']],
            'ready' => ['collect' => ['label' => 'Collected', 'icon' => 'hand']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $pet = (string) ($record->value('_pet') ?: $record->title);
        switch ($action) {
            case 'check_in':
                if (in_array($record->value('type'), self::STAYS, true) && ($petRecord = $this->parent($record, 'pet')) && ($problem = $this->unvaccinated($petRecord, $record->due_on ?? today()))) {
                    throw ValidationException::withMessages(['pet' => $problem]);
                }
                $record->update(['status' => 'checked_in', 'data' => [...$record->data, 'drop_off' => $record->value('drop_off') ?: now()->format('H:i')]]);

                return $pet.' checked in.';
            case 'cancel':
                $record->update(['status' => 'cancelled']);

                return $pet.'\'s '.$record->title.' cancelled.';
            case 'start':
                $record->update(['status' => 'in_progress']);

                return $pet.' is being looked after.';
            case 'ready':
                $record->update(['status' => 'ready']);

                return $pet.' is ready to go home.';
            default:
                $record->update(['status' => 'collected']);

                return $pet.' collected.';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'pets') {
            return [];
        }

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Visits', 'icon' => 'paw-print', 'empty' => 'No visits yet.',
            'rows' => $this->linked('appointments', 'pet', $record)->orderByDesc('occurs_on')->get()
                ->map(fn (Record $visit) => ['label' => $visit->title, 'sub' => ucfirst(str_replace('_', ' ', (string) $visit->value('type'))).' · '.str_replace('_', ' ', $visit->status), 'value' => $visit->occurs_on->format('d M Y'), 'href' => $visit->url()])->all(),
        ]]];
    }

    public function homeCards(): array
    {
        $onSite = $this->records('appointments')->whereIn('status', ['checked_in', 'in_progress', 'ready'])->get();
        $today = $this->records('appointments')->where('status', 'booked')->whereDate('occurs_on', today()->toDateString())->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'In our care', 'icon' => 'paw-print', 'stats' => [
                ['label' => 'Pets on site', 'value' => $onSite->count()],
                ['label' => 'Boarding', 'value' => $onSite->filter(fn (Record $visit) => $visit->value('type') === 'boarding')->count()],
                ['label' => 'Ready to collect', 'value' => $onSite->where('status', 'ready')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Arriving today', 'icon' => 'calendar', 'empty' => 'No more bookings today.',
                'rows' => $today->sortBy(fn (Record $visit) => (string) $visit->value('drop_off'))
                    ->map(fn (Record $visit) => ['label' => (string) ($visit->value('_pet') ?: $visit->title), 'sub' => ucfirst(str_replace('_', ' ', (string) $visit->value('type'))), 'value' => substr((string) $visit->value('drop_off'), 0, 5) ?: '—', 'href' => $visit->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        return [['title' => 'Visits by service', 'columns' => ['Service', 'Visits', 'Cancelled', 'Takings'], 'rows' => $this->dated('appointments', $from, $to)->get()
            ->groupBy(fn (Record $visit) => ucfirst(str_replace('_', ' ', (string) ($visit->value('type') ?: 'other'))))->sortKeys()
            ->map(fn ($group, string $type) => [$type, $group->where('status', '!=', 'cancelled')->count(), $group->where('status', 'cancelled')->count(), $this->money($group->where('status', 'collected')->sum('amount'))])
            ->values()->all()]];
    }
}
