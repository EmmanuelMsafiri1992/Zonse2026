<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Org chart & succession planning: a position can't report to itself, directly or through the chain
 * above it, and one person holds one position. A position is filled while it has a holder and vacant
 * without one, unless it is frozen. Each position shows its direct reports and successors, and the home
 * page flags positions with nobody ready to step in.
 */
class OrgChartLogic extends AppLogic
{
    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if ($entity->key === 'positions') {
            if ($existing && filled($data['reports_to'] ?? null) && $this->chainReaches((int) $data['reports_to'], $existing->id)) {
                $errors['data.reports_to'] = 'That would make '.$existing->title.' report to itself.';
            }
            $other = filled($data['holder'] ?? null) ? $this->records('positions')->where('data->holder', (int) $data['holder'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->first() : null;
            if ($other) {
                $errors['data.holder'] = User::query()->whereKey($data['holder'])->value('name').' already holds '.$other->title.'.';
            }
        }
        if ($entity->key === 'successors' && filled($data['position'] ?? null)) {
            $twin = $this->linked('successors', 'position', (int) $data['position'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
                ->first(fn (Record $successor) => mb_strtolower(trim((string) $successor->title)) === mb_strtolower(trim((string) $payload['title'])));
            if ($twin) {
                $errors['title'] = $twin->title.' is already a successor for this position.';
            }
        }

        return $errors;
    }

    /**
     * Whether following "reports to" up from a position reaches the given one.
     */
    protected function chainReaches(int $start, int $target): bool
    {
        $positions = $this->records('positions')->get()->keyBy('id');
        $seen = [];
        $id = $start;
        while ($id && ! isset($seen[$id])) {
            if ($id === $target) {
                return true;
            }
            $seen[$id] = true;
            $id = (int) $positions->get($id)?->value('reports_to');
        }

        return false;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'positions' && $record->status !== 'frozen') {
            $record->status = filled($record->value('holder')) ? 'filled' : 'vacant';
        }
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'positions') {
            return [];
        }
        $reports = $this->linked('positions', 'reports_to', $record)->get();
        $successors = $this->linked('successors', 'position', $record)->get();

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Succession', 'icon' => 'user-check', 'stats' => [
                ['label' => 'Direct reports', 'value' => $reports->count()],
                ['label' => 'Ready now', 'value' => $successors->where('status', 'ready_now')->count(), 'tone' => $successors->where('status', 'ready_now')->isEmpty() ? 'danger' : 'success'],
                ['label' => 'Ready in 1–2 years', 'value' => $successors->where('status', 'ready_1_2_years')->count()],
                ['label' => 'Need development', 'value' => $successors->where('status', 'development_needed')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reports to '.$record->title, 'icon' => 'network', 'empty' => 'No positions report here.',
                'rows' => $reports->map(fn (Record $position) => ['label' => $position->title, 'sub' => $this->holder($position), 'value' => ucfirst($position->status), 'href' => $position->url(), 'tone' => $position->status === 'vacant' ? 'warning' : null])->values()->all(),
            ]],
        ];
    }

    protected function holder(Record $position): string
    {
        return (string) (filled($position->value('holder')) ? User::query()->whereKey($position->value('holder'))->value('name') : null) ?: 'Vacant';
    }

    public function homeCards(): array
    {
        $positions = $this->records('positions')->where('status', '!=', 'frozen')->get();
        $ready = $this->records('successors')->where('status', 'ready_now')->get()->map(fn (Record $successor) => (int) $successor->value('position'))->unique();
        $gaps = $positions->filter(fn (Record $position) => ! $ready->contains($position->id))->sortBy('title');

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'No successor ready ('.$gaps->count().' of '.$positions->count().')', 'icon' => 'user-check', 'empty' => 'Every position has someone ready now.',
            'rows' => $gaps->map(fn (Record $position) => ['label' => $position->title, 'sub' => $this->holder($position), 'value' => $position->value('department'), 'href' => $position->url(), 'tone' => $position->status === 'vacant' ? 'danger' : 'warning'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $positions = $this->records('positions')->orderBy('title')->get();
        $successors = $this->records('successors')->get()->groupBy(fn (Record $successor) => (int) $successor->value('position'));

        return [['title' => 'Bench strength', 'columns' => ['Position', 'Department', 'Holder', 'Direct reports', 'Ready now', 'Ready in 1–2 years', 'Need development'], 'rows' => $positions
            ->map(function (Record $position) use ($positions, $successors) {
                /** @var Collection<int, Record> $bench */
                $bench = $successors[$position->id] ?? collect();

                return [$position->title, (string) $position->value('department'), $this->holder($position), $positions->filter(fn (Record $other) => (int) $other->value('reports_to') === $position->id)->count(),
                    $bench->where('status', 'ready_now')->count(), $bench->where('status', 'ready_1_2_years')->count(), $bench->where('status', 'development_needed')->count()];
            })->values()->all()]];
    }
}
