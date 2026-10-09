<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Audit working papers: a paper needs a conclusion before it is reviewed or cleared, working
 * paper references are unique within an engagement, archived engagements take no new papers,
 * and an engagement cannot be reported while papers are uncleared or high-risk findings open.
 */
class AuditWorkingPapersLogic extends AppLogic
{
    public const RISKS = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];

        if (in_array($entity->key, ['papers', 'findings'], true) && ! empty($data['engagement'])) {
            $engagement = $this->records('engagements')->find($data['engagement']);
            $moving = ! $existing || (int) $existing->value('engagement') !== (int) $data['engagement'];
            if ($engagement && $engagement->status === 'archived' && $moving) {
                return ['data.engagement' => $engagement->title.' is archived.'];
            }
        }

        if ($entity->key === 'papers') {
            $errors = [];
            if (in_array($payload['status'], ['reviewed', 'cleared'], true) && blank($data['conclusion'] ?? null)) {
                $errors['data.conclusion'] = 'Write the conclusion before the paper is reviewed.';
            }
            if (! empty($data['engagement']) && filled($data['reference'] ?? null)) {
                $duplicate = $this->linked('papers', 'engagement', (int) $data['engagement'])->where('data->reference', $data['reference'])
                    ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists();
                if ($duplicate) {
                    $errors['data.reference'] = 'Working paper '.$data['reference'].' already exists on this engagement.';
                }
            }

            return $errors;
        }

        if ($entity->key === 'findings' && $payload['status'] === 'resolved' && blank($data['management_response'] ?? null)) {
            return ['data.management_response' => 'Record management\'s response before resolving the finding.'];
        }

        if ($entity->key === 'engagements' && in_array($payload['status'], ['reported', 'archived'], true) && $existing && ! in_array($existing->status, ['reported', 'archived'], true)) {
            $progress = $this->progress($existing);
            if ($progress['uncleared'] > 0) {
                return ['status' => $progress['uncleared'].' working paper(s) are not cleared yet.'];
            }
            if ($progress['open_high'] > 0) {
                return ['status' => $progress['open_high'].' high-risk finding(s) are still open. Agree or resolve them first.'];
            }
        }

        return [];
    }

    /** @return array{papers: int, cleared: int, uncleared: int, percent: int, findings: array<string, int>, open_high: int} */
    public function progress(Record $engagement): array
    {
        $papers = $this->linked('papers', 'engagement', $engagement)->get();
        $findings = $this->linked('findings', 'engagement', $engagement)->get();
        $cleared = $papers->where('status', 'cleared')->count();

        return [
            'papers' => $papers->count(),
            'cleared' => $cleared,
            'uncleared' => $papers->count() - $cleared,
            'percent' => $papers->count() ? (int) round($cleared / $papers->count() * 100) : 0,
            'findings' => collect(self::RISKS)->map(fn ($label, $risk) => $findings->filter(fn (Record $finding) => $finding->value('risk') === $risk)->count())->all(),
            'open_high' => $findings->where('status', 'open')->filter(fn (Record $finding) => $finding->value('risk') === 'high')->count(),
        ];
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity !== 'engagements') {
            return [];
        }

        $progress = $this->progress($record);
        $papers = $this->linked('papers', 'engagement', $record)->get()->sortBy(fn (Record $paper) => (string) $paper->value('reference'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Audit progress', 'icon' => 'file-search', 'stats' => [
                ['label' => 'Papers cleared', 'value' => $progress['cleared'].' of '.$progress['papers'].' ('.$progress['percent'].'%)', 'tone' => $progress['papers'] && ! $progress['uncleared'] ? 'success' : null],
                ['label' => 'High-risk findings', 'value' => (string) $progress['findings']['high'], 'tone' => $progress['open_high'] ? 'danger' : null],
                ['label' => 'Medium / low', 'value' => $progress['findings']['medium'].' / '.$progress['findings']['low']],
                ['label' => 'Materiality', 'value' => $record->value('materiality') ? $this->money($record->value('materiality')) : '—'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Working papers', 'icon' => 'file-text', 'empty' => 'No working papers yet.',
                'rows' => $papers->map(fn (Record $paper) => [
                    'label' => $paper->value('reference').' '.$paper->title, 'value' => ucfirst(str_replace('_', ' ', $paper->status)), 'href' => $paper->url(),
                    'tone' => $paper->status === 'cleared' ? 'success' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function homeCards(): array
    {
        $live = $this->records('engagements')->whereNotIn('status', ['reported', 'archived'])->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Engagements in progress', 'icon' => 'file-search', 'empty' => 'No engagements in progress.',
            'rows' => $live->map(function (Record $engagement) {
                $progress = $this->progress($engagement);
                $late = $engagement->due_on && $engagement->due_on->lt(today());

                return [
                    'label' => $engagement->title, 'sub' => ucfirst($engagement->status).' · '.$progress['percent'].'% cleared',
                    'value' => $engagement->due_on ? ($late ? 'Late' : 'Due '.$engagement->due_on->format('d M')) : '—', 'href' => $engagement->url(), 'tone' => $late ? 'danger' : null,
                ];
            })->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $engagements = $this->records('engagements')->orderBy('title')->get();
        $names = $engagements->pluck('title', 'id');
        $progress = $engagements->map(function (Record $engagement) {
            $progress = $this->progress($engagement);

            return [$engagement->title, ucfirst($engagement->status), $progress['papers'], $progress['percent'].'%', $progress['findings']['high'], $engagement->due_on?->format('d M Y') ?? '—'];
        })->all();

        $findings = $this->dated('findings', $from, $to)->get()->sortBy(fn (Record $finding) => array_search($finding->value('risk'), array_keys(self::RISKS), true))
            ->map(fn (Record $finding) => [$names[$finding->value('engagement')] ?? '—', $finding->title, self::RISKS[$finding->value('risk')] ?? '—', ucfirst($finding->status)])->values()->all();

        return [
            ['title' => 'Engagement progress', 'columns' => ['Engagement', 'Stage', 'Papers', 'Cleared', 'High-risk findings', 'Report deadline'], 'rows' => $progress],
            ['title' => 'Findings', 'columns' => ['Engagement', 'Finding', 'Risk', 'Status'], 'rows' => $findings],
        ];
    }
}
