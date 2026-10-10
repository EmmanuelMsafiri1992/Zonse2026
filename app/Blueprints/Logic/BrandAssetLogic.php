<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Brand asset library: asset names are unique within their type, and the file has to be the right
 * kind for it — a logo is an image or vector, a font a font file, a video a video — whenever the
 * link shows its extension. A colour palette lists its hex codes in the usage notes. Approving and
 * retiring are recorded, and a retired asset says why so nobody reuses it by mistake.
 */
class BrandAssetLogic extends AppLogic
{
    /**
     * File extensions each asset type accepts; types not listed accept any file.
     *
     * @var array<string, list<string>>
     */
    public const EXTENSIONS = [
        'logo' => ['svg', 'png', 'jpg', 'jpeg', 'eps', 'ai', 'pdf', 'webp'],
        'font' => ['ttf', 'otf', 'woff', 'woff2', 'zip'],
        'photo' => ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'heic', 'raw'],
        'video' => ['mp4', 'mov', 'webm', 'avi', 'mkv'],
        'guideline' => ['pdf', 'doc', 'docx', 'pptx', 'key'],
        'template' => ['docx', 'pptx', 'xlsx', 'indd', 'psd', 'ai', 'fig', 'key', 'pdf', 'zip'],
    ];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $type = (string) ($data['type'] ?? '');
        $errors = [];

        $name = mb_strtolower(trim((string) $payload['title']));
        $twin = $this->records('assets')->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->first(fn (Record $asset) => $asset->value('type') === $type && mb_strtolower(trim((string) $asset->title)) === $name);
        if ($twin) {
            $errors['title'] = 'There is already a '.$this->typeLabel($type).' called '.$twin->title.'.';
        }
        $extension = $this->extension((string) ($data['file_url'] ?? ''));
        if ($extension !== null && isset(self::EXTENSIONS[$type]) && ! in_array($extension, self::EXTENSIONS[$type], true)) {
            $errors['data.file_url'] = 'A .'.$extension.' file is not a '.$this->typeLabel($type).'; use '.implode(', ', array_map(fn (string $allowed) => '.'.$allowed, self::EXTENSIONS[$type])).'.';
        }
        if ($type === 'colour_palette' && $this->colours((string) ($data['usage_notes'] ?? '')) === []) {
            $errors['data.usage_notes'] = 'List the palette\'s hex codes, like #1A73E8, in the usage notes.';
        }
        if ($payload['status'] === 'retired' && $existing?->status !== 'retired') {
            $errors['status'] = 'Retire the asset with the Retire action so the reason is kept.';
        }

        return $errors;
    }

    /**
     * The lower-case extension at the end of a link's path, or null when it has none.
     */
    public function extension(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('/\.([a-z0-9]{2,5})$/i', $path, $match) ? mb_strtolower($match[1]) : null;
    }

    /**
     * The distinct hex colours in a piece of text, upper-cased with three-digit codes expanded.
     *
     * @return list<string>
     */
    public function colours(string $text): array
    {
        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $text, $matches);

        return array_values(array_unique(array_map(function (string $hex) {
            $hex = mb_strtoupper($hex);

            return '#'.(strlen($hex) === 3 ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex);
        }, $matches[1])));
    }

    protected function typeLabel(string $type): string
    {
        return str_replace('_', ' ', $type);
    }

    public function saving(Record $record): void
    {
        $this->put($record, ['_colours' => $record->value('type') === 'colour_palette' ? $this->colours((string) $record->value('usage_notes')) : null]);
        if ($record->isDirty('status') && $record->status === 'approved') {
            $this->put($record, ['_approved_on' => today()->toDateString()]);
        }
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'draft' => ['approve' => ['label' => 'Approve', 'icon' => 'badge-check'], 'retire' => ['label' => 'Retire', 'icon' => 'archive', 'fields' => [['name' => 'reason', 'label' => 'Why it is retired', 'type' => 'text']]]],
            'approved' => ['retire' => ['label' => 'Retire', 'icon' => 'archive', 'fields' => [['name' => 'reason', 'label' => 'Why it is retired', 'type' => 'text']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        if ($action === 'approve') {
            if (blank($record->value('file_url'))) {
                throw ValidationException::withMessages(['file_url' => 'Add the file before approving.']);
            }
            $record->update(['status' => 'approved']);

            return $record->title.' is approved for use.';
        }
        $reason = trim($request->validate(['reason' => ['required', 'string', 'max:255']])['reason']);
        $record->update(['status' => 'retired', 'data' => [...$record->data, '_retired_reason' => $reason, '_retired_on' => today()->toDateString()]]);

        return $record->title.' retired: '.$reason.'.';
    }

    public function recordCards(Record $record): array
    {
        $stats = [
            ['label' => 'Type', 'value' => ucfirst($this->typeLabel((string) $record->value('type')))],
            ['label' => 'File', 'value' => $this->extension((string) $record->value('file_url')) ? '.'.$this->extension((string) $record->value('file_url')) : 'Link'],
        ];
        if ($record->status === 'approved') {
            $stats[] = ['label' => 'Approved on', 'value' => $record->value('_approved_on') ? Carbon::parse($record->value('_approved_on'))->format('d M Y') : '—', 'tone' => 'success'];
        }
        if ($record->status === 'retired') {
            $stats[] = ['label' => 'Retired', 'value' => (string) $record->value('_retired_reason'), 'tone' => 'danger'];
        }
        if ($colours = (array) $record->value('_colours')) {
            $stats[] = ['label' => 'Colours', 'value' => implode(' ', $colours)];
        }

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Asset', 'icon' => 'palette', 'stats' => $stats]]];
    }

    public function homeCards(): array
    {
        $assets = $this->records('assets')->get();
        $drafts = $assets->where('status', 'draft')->sortBy('created_at')->take(10);

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Library', 'icon' => 'palette', 'stats' => [
                ['label' => 'Approved', 'value' => $assets->where('status', 'approved')->count()],
                ['label' => 'Awaiting approval', 'value' => $assets->where('status', 'draft')->count()],
                ['label' => 'Retired', 'value' => $assets->where('status', 'retired')->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Awaiting approval', 'icon' => 'badge-check', 'empty' => 'Nothing waiting for approval.',
                'rows' => $drafts->map(fn (Record $asset) => ['label' => $asset->title, 'sub' => ucfirst($this->typeLabel((string) $asset->value('type'))), 'href' => $asset->url()])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $assets = $this->records('assets')->get();

        return [
            ['title' => 'Assets by type', 'columns' => ['Type', 'Approved', 'Draft', 'Retired', 'Total'], 'rows' => $assets->groupBy(fn (Record $asset) => ucfirst($this->typeLabel((string) $asset->value('type'))))->sortKeys()
                ->map(fn ($group, string $type) => [$type, $group->where('status', 'approved')->count(), $group->where('status', 'draft')->count(), $group->where('status', 'retired')->count(), $group->count()])->values()->all()],
            ['title' => 'Retired assets', 'columns' => ['Asset', 'Type', 'Retired on', 'Reason'], 'rows' => $assets->where('status', 'retired')->sortByDesc(fn (Record $asset) => (string) $asset->value('_retired_on'))
                ->map(fn (Record $asset) => [$asset->title, ucfirst($this->typeLabel((string) $asset->value('type'))), $asset->value('_retired_on') ? Carbon::parse($asset->value('_retired_on'))->format('d M Y') : '—', (string) $asset->value('_retired_reason')])->values()->all()],
        ];
    }
}
