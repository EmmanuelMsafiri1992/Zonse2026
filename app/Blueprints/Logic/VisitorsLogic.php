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

/**
 * Visitor management: a visitor signs in with the time they arrived (now, if not given), and the same
 * ID number can't be signed in twice at once. Signing out records the time and the length of the visit,
 * which can't end before it began. The home page keeps a roll of who is on site for an evacuation, and
 * each morning visits left open from earlier days are closed and flagged as never signed out.
 */
class VisitorsLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        $id = $this->idNumber((string) ($data['id_number'] ?? ''));
        if ($id !== '' && $payload['status'] === 'signed_in') {
            $twin = $this->records('visits')->where('status', 'signed_in')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $visit) => $this->idNumber((string) $visit->value('id_number')) === $id);
            if ($twin) {
                $errors['data.id_number'] = $twin->title.' with this ID is already signed in'.($twin->value('time_in') ? ' since '.$twin->value('time_in') : '').'.';
            }
        }
        if (filled($data['time_in'] ?? null) && filled($data['time_out'] ?? null) && $this->minutes((string) $data['time_in'], (string) $data['time_out']) < 0) {
            $errors['data.time_out'] = 'The visitor cannot leave before they arrived.';
        }
        if ($payload['status'] === 'signed_out' && blank($data['time_out'] ?? null) && $existing?->status !== 'signed_out') {
            $errors['data.time_out'] = 'Give the time the visitor left, or use Sign out.';
        }

        return $errors;
    }

    /**
     * An ID number with spaces and dashes removed, upper-cased, for comparing.
     */
    protected function idNumber(string $id): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($id));
    }

    /**
     * Minutes between two "H:i" times on the same day.
     */
    public function minutes(string $in, string $out): int
    {
        return (int) Carbon::createFromFormat('H:i', substr($in, 0, 5))->diffInMinutes(Carbon::createFromFormat('H:i', substr($out, 0, 5)), false);
    }

    public function saving(Record $record): void
    {
        $record->occurs_on ??= today();
        if (blank($record->value('time_in'))) {
            $this->put($record, ['time_in' => now()->format('H:i')]);
        }
        if (filled($record->value('time_out'))) {
            $this->put($record, ['_minutes' => max(0, $this->minutes((string) $record->value('time_in'), (string) $record->value('time_out')))]);
        }
    }

    public function actions(Record $record): array
    {
        return $record->status === 'signed_in' ? ['sign_out' => ['label' => 'Sign out', 'icon' => 'log-out']] : [];
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        $out = now()->format('H:i');
        if ($record->occurs_on && $record->occurs_on->lt(today())) {
            $out = '23:59';
        }
        $record->update(['status' => 'signed_out', 'data' => [...$record->data, 'time_out' => $out]]);

        return $record->title.' signed out at '.$out.' after '.$this->duration((int) $record->value('_minutes')).'.';
    }

    /**
     * Minutes in words: "45 min" or "2 h 10 min".
     */
    protected function duration(int $minutes): string
    {
        return $minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    }

    public function daily(Workspace $workspace): int
    {
        $left = $this->records('visits')->where('status', 'signed_in')->whereDate('occurs_on', '<', today()->toDateString())->get();
        $left->each(fn (Record $visit) => $visit->update(['status' => 'signed_out', 'data' => [...$visit->data, '_not_signed_out' => true]]));

        return $left->count();
    }

    public function recordCards(Record $record): array
    {
        $earlier = $this->records('visits')->whereKeyNot($record->id)->get()->filter(fn (Record $visit) => $this->sameVisitor($visit, $record));

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Visit', 'icon' => 'id-card', 'stats' => array_values(array_filter([
            ['label' => 'Arrived', 'value' => (string) $record->value('time_in')],
            ['label' => 'Left', 'value' => $record->value('_not_signed_out') ? 'Never signed out' : ($record->value('time_out') ?: 'Still on site'), 'tone' => $record->value('_not_signed_out') ? 'danger' : ($record->status === 'signed_in' ? 'success' : null)],
            $record->value('time_out') ? ['label' => 'Stayed', 'value' => $this->duration((int) $record->value('_minutes'))] : null,
            ['label' => 'Earlier visits', 'value' => $earlier->count()],
        ]))]]];
    }

    /**
     * Whether two visits were by the same person: the same ID number, or the same name when neither has one.
     */
    protected function sameVisitor(Record $visit, Record $other): bool
    {
        $id = $this->idNumber((string) $other->value('id_number'));

        return $id !== '' ? $this->idNumber((string) $visit->value('id_number')) === $id : mb_strtolower(trim((string) $visit->title)) === mb_strtolower(trim((string) $other->title));
    }

    public function homeCards(): array
    {
        $onSite = $this->records('visits')->where('status', 'signed_in')->orderBy('data->time_in')->get();
        $hosts = User::query()->whereIn('id', $onSite->map(fn (Record $visit) => $visit->value('host'))->filter()->unique())->pluck('name', 'id');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'On site now ('.$onSite->count().')', 'icon' => 'users', 'empty' => 'Nobody is signed in.',
            'rows' => $onSite->map(fn (Record $visit) => [
                'label' => $visit->title, 'sub' => trim(($visit->value('company') ?? '').' · visiting '.($hosts[$visit->value('host')] ?? '—'), ' ·'),
                'value' => 'since '.$visit->value('time_in'), 'href' => $visit->url(), 'tone' => $visit->occurs_on && $visit->occurs_on->lt(today()) ? 'warning' : null,
            ])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $visits = $this->dated('visits', $from, $to)->get();
        $hosts = User::query()->whereIn('id', $visits->map(fn (Record $visit) => $visit->value('host'))->filter()->unique())->pluck('name', 'id');
        $row = function (Collection $group, string $label) {
            $timed = $group->filter(fn (Record $visit) => $visit->value('time_out') !== null && ! $visit->value('_not_signed_out'));

            return [$label, $group->count(), $timed->isNotEmpty() ? $this->duration((int) round($timed->avg(fn (Record $visit) => (int) $visit->value('_minutes')))) : '—', $group->filter(fn (Record $visit) => (bool) $visit->value('_not_signed_out'))->count()];
        };
        $columns = ['Visits', 'Average stay', 'Never signed out'];

        return [
            ['title' => 'Visits by host', 'columns' => ['Host', ...$columns], 'rows' => $visits->groupBy(fn (Record $visit) => $hosts[$visit->value('host')] ?? 'No host')->sortKeys()->map($row)->values()->all()],
            ['title' => 'Visits by company', 'columns' => ['Company', ...$columns], 'rows' => $visits->groupBy(fn (Record $visit) => trim((string) $visit->value('company')) ?: 'Private')->sortKeys()->map($row)->values()->all()],
        ];
    }
}
