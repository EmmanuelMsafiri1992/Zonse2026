<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Property valuation: a report is due a week after instruction unless a date is set. A valuation is
 * inspected only on a date that has passed, and it is issued only with a market value and a report link.
 * Each valuation works out its value per m², of the building or of the land when there is no building.
 * The home page lists reports past their due date, and the report shows turnaround and fees by purpose.
 */
class PropertyValuationLogic extends AppLogic
{
    public const REPORT_WITHIN_DAYS = 7;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];
        if (in_array($payload['status'], ['inspected', 'report_draft', 'issued'], true)) {
            if (blank($payload['occurs_on'] ?? null)) {
                $errors['occurs_on'] = 'Give the inspection date.';
            } elseif (Carbon::parse($payload['occurs_on'])->isFuture()) {
                $errors['occurs_on'] = 'The inspection date cannot be in the future.';
            }
        }
        if ($payload['status'] === 'issued') {
            $errors = [...$errors, ...$this->issueErrors((float) ($data['market_value'] ?? 0), $data['report_url'] ?? null)];
        }

        return $errors;
    }

    /**
     * @return array<string, string>
     */
    protected function issueErrors(float $value, ?string $link): array
    {
        return array_filter([
            'data.market_value' => $value <= 0 ? 'Give the market value before issuing.' : null,
            'data.report_url' => blank($link) ? 'Link the report before issuing.' : null,
        ]);
    }

    public function saving(Record $record): void
    {
        $record->due_on ??= ($record->created_at ?? now())->copy()->startOfDay()->addDays(self::REPORT_WITHIN_DAYS);
        $size = $this->number($record, 'building_size') ?: $this->number($record, 'erf_size');
        $value = $this->number($record, 'market_value');
        $this->put($record, [
            '_per_m2' => $size > 0 && $value > 0 ? round($value / $size, 2) : null,
            '_issued_on' => $record->status === 'issued' ? ($record->value('_issued_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function actions(Record $record): array
    {
        $issue = ['issue' => ['label' => 'Issue report', 'icon' => 'file-check', 'fields' => [
            ['name' => 'market_value', 'label' => 'Market value', 'type' => 'number', 'value' => $record->value('market_value')],
            ['name' => 'report_url', 'label' => 'Report link', 'type' => 'url', 'value' => $record->value('report_url')],
        ]]];

        return match ($record->status) {
            'instructed' => ['inspect' => ['label' => 'Inspected today', 'icon' => 'search'], 'cancel' => ['label' => 'Cancel', 'icon' => 'x']],
            'inspected' => ['draft' => ['label' => 'Report drafted', 'icon' => 'file-pen'], ...$issue],
            'report_draft' => $issue,
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'inspect':
                $record->update(['status' => 'inspected', 'occurs_on' => today()]);

                return $record->title.' inspected; the report is due '.$record->due_on->format('d M').'.';
            case 'draft':
                $record->update(['status' => 'report_draft']);

                return 'Report drafted for '.$record->title.'.';
            case 'issue':
                $input = $request->validate(['market_value' => ['nullable', 'numeric', 'min:0'], 'report_url' => ['nullable', 'url', 'max:2000']]);
                $errors = $this->issueErrors((float) ($input['market_value'] ?? 0), $input['report_url'] ?? null);
                if ($errors !== []) {
                    throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn (string $message, string $key) => [str_replace('data.', '', $key) => $message])->all());
                }
                $record->update(['status' => 'issued', 'data' => [...$record->data, 'market_value' => (float) $input['market_value'], 'report_url' => $input['report_url']]]);

                return $record->title.' valued at '.$this->money($input['market_value']).'.';
            default:
                $record->update(['status' => 'cancelled']);

                return 'Valuation of '.$record->title.' cancelled.';
        }
    }

    public function homeCards(): array
    {
        $late = $this->records('valuations')->whereIn('status', ['instructed', 'inspected', 'report_draft'])->whereDate('due_on', '<', today()->toDateString())->orderBy('due_on')->get();

        return [['view' => 'apps.logic.list-card', 'data' => [
            'title' => 'Reports overdue', 'icon' => 'file-clock', 'empty' => 'Every report is on time.',
            'rows' => $late->map(fn (Record $valuation) => ['label' => $valuation->title, 'sub' => ucfirst((string) $valuation->value('purpose')), 'value' => (int) $valuation->due_on->diffInDays(today()).' days late', 'href' => $valuation->url(), 'tone' => 'danger'])->values()->all(),
        ]]];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $valuations = $this->records('valuations')->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])->get();

        return [['title' => 'Valuations by purpose', 'columns' => ['Purpose', 'Instructed', 'Issued', 'Average days to issue', 'Fees'], 'rows' => $valuations
            ->groupBy(fn (Record $valuation) => ucfirst((string) $valuation->value('purpose')))->sortKeys()
            ->map(function ($group, string $purpose) {
                $issued = $group->where('status', 'issued')->filter(fn (Record $valuation) => $valuation->value('_issued_on'));

                return [$purpose, $group->count(), $issued->count(),
                    $issued->isNotEmpty() ? number_format($issued->avg(fn (Record $valuation) => (int) $valuation->created_at->copy()->startOfDay()->diffInDays(Carbon::parse($valuation->value('_issued_on')))), 1) : '—',
                    $this->money($group->where('status', '!=', 'cancelled')->sum(fn (Record $valuation) => (float) $valuation->amount))];
            })->values()->all()]];
    }
}
