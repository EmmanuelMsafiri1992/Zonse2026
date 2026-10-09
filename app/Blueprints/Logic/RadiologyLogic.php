<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Radiology: an imaging request is booked onto a date, scanned and then reported by a
 * radiologist. Each step keeps its time so the department can see how long reports take per
 * modality, and a study that was scanned more than two days ago without a report is flagged.
 * A report is needed to close a study, and a reported study cannot go back.
 */
class RadiologyLogic extends AppLogic
{
    /**
     * Hours after the scan by which a report is expected.
     */
    protected const REPORT_HOURS = 48;

    /**
     * How far a study has gone, so it never moves backwards once reported.
     */
    protected const STAGES = ['requested' => 0, 'booked' => 1, 'scanned' => 2, 'reported' => 3, 'cancelled' => 0];

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($payload['status'] === 'booked' && blank($payload['occurs_on'] ?? null)) {
            $errors['occurs_on'] = 'Choose the scan date.';
        }
        if ($payload['status'] === 'reported' && blank($data['report'] ?? null)) {
            $errors['data.report'] = 'Write the radiologist report.';
        }
        if ($existing?->status === 'reported' && $payload['status'] !== 'reported') {
            $errors['status'] = 'A reported study cannot go back.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        $now = now()->toDateTimeString();
        $values = ['_requested_at' => $record->value('_requested_at') ?? $now];
        $stage = self::STAGES[$record->status] ?? 0;
        if ($stage >= 2) {
            $values['_scanned_at'] = $record->value('_scanned_at') ?? $now;
        }
        if ($stage >= 3) {
            $values['_reported_at'] = $record->value('_reported_at') ?? $now;
            $values['_report_hours'] = (int) Carbon::parse($values['_scanned_at'])->diffInHours(Carbon::parse($values['_reported_at']));
        }
        $values['_report_late'] = $record->status === 'scanned' && Carbon::parse($values['_scanned_at'])->lt(now()->subHours(self::REPORT_HOURS))
            || $stage >= 3 && $values['_report_hours'] > self::REPORT_HOURS;
        $this->put($record, $values);
    }

    public function actions(Record $record): array
    {
        return match ($record->status) {
            'requested' => [
                'book' => ['label' => 'Book scan', 'icon' => 'calendar-plus', 'fields' => [['name' => 'date', 'label' => 'Scan date', 'type' => 'date']]],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
            ],
            'booked' => [
                'scan' => ['label' => 'Scanned', 'icon' => 'scan', 'fields' => [['name' => 'dicom_link', 'label' => 'DICOM / PACS link (optional)', 'type' => 'url']]],
                'cancel' => ['label' => 'Cancel', 'icon' => 'x', 'fields' => [['name' => 'reason', 'label' => 'Reason', 'type' => 'text']]],
            ],
            'scanned' => ['report' => ['label' => 'Report', 'icon' => 'file-text', 'fields' => [['name' => 'report', 'label' => 'Radiologist report', 'type' => 'textarea']]]],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'book':
                $date = $request->validate(['date' => ['required', 'date', 'after_or_equal:today']])['date'];
                $record->update(['status' => 'booked', 'occurs_on' => $date]);

                return $record->title.'\'s '.$this->modality($record).' booked for '.Carbon::parse($date)->format('d M Y').'.';
            case 'scan':
                $link = $request->validate(['dicom_link' => ['nullable', 'url']])['dicom_link'] ?? null;
                $record->update(['status' => 'scanned', 'occurs_on' => $record->occurs_on ?? today(), 'data' => [...$record->data, 'dicom_link' => $link ?? $record->value('dicom_link')]]);

                return $record->title.' scanned; report due by '.now()->addHours(self::REPORT_HOURS)->format('d M H:i').'.';
            case 'report':
                $report = $request->validate(['report' => ['required', 'string']])['report'];
                $record->update(['status' => 'reported', 'assignee_id' => $record->assignee_id ?? $request->user()?->id, 'data' => [...$record->data, 'report' => $report]]);

                return 'Report for '.$record->title.' ready'.(filled($record->value('referring_doctor')) ? ' for '.$record->value('referring_doctor') : '').'.';
        }

        $reason = $request->validate(['reason' => ['required', 'string']])['reason'];
        $record->update(['status' => 'cancelled', 'data' => [...$record->data, '_cancel_reason' => $reason]]);

        return $record->title.'\'s study cancelled.';
    }

    /**
     * A study's modality as a readable name.
     */
    protected function modality(Record $record): string
    {
        return match ($record->value('modality')) {
            'x_ray' => 'X-ray',
            'ct' => 'CT',
            'mri' => 'MRI',
            default => (string) $record->value('modality'),
        };
    }

    public function recordCards(Record $record): array
    {
        $at = fn (string $key) => filled($record->value($key)) ? Carbon::parse($record->value($key))->format('d M H:i') : '—';

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Study', 'icon' => 'scan', 'stats' => [
                ['label' => 'Requested', 'value' => $at('_requested_at')],
                ['label' => 'Scanned', 'value' => $at('_scanned_at')],
                ['label' => 'Reported', 'value' => $at('_reported_at')],
                ['label' => 'Report time', 'value' => $record->value('_report_hours') !== null ? $record->value('_report_hours').' h' : '—', 'tone' => $record->value('_report_late') ? 'danger' : null],
            ]]],
        ];
    }

    public function homeCards(): array
    {
        $studies = $this->records('studies')->whereIn('status', ['requested', 'booked', 'scanned'])->get();
        $toReport = $studies->where('status', 'scanned')->sortBy(fn (Record $study) => $study->value('_scanned_at'));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Radiology', 'icon' => 'scan', 'stats' => [
                ['label' => 'To book', 'value' => (string) $studies->where('status', 'requested')->count()],
                ['label' => 'Booked today', 'value' => (string) $studies->where('status', 'booked')->filter(fn (Record $study) => $study->occurs_on?->isToday())->count()],
                ['label' => 'To report', 'value' => (string) $toReport->count()],
                ['label' => 'Reports late', 'value' => (string) $toReport->filter(fn (Record $study) => Carbon::parse($study->value('_scanned_at'))->lt(now()->subHours(self::REPORT_HOURS)))->count()],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Reporting worklist', 'icon' => 'file-text', 'empty' => 'Nothing waiting for a report.',
                'rows' => $toReport->map(fn (Record $study) => [
                    'label' => $study->title, 'sub' => $this->modality($study).' · '.$study->value('body_part'), 'value' => Carbon::parse($study->value('_scanned_at'))->diffForHumans(), 'href' => $study->url(), 'tone' => Carbon::parse($study->value('_scanned_at'))->lt(now()->subHours(self::REPORT_HOURS)) ? 'danger' : null,
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $studies = $this->dated('studies', $from, $to)->get();
        $byModality = $studies->groupBy(fn (Record $study) => $this->modality($study))->sortKeys()->map(function (Collection $group, string $modality) {
            $reported = $group->where('status', 'reported');

            return [$modality, $group->count(), $reported->count(), $reported->isEmpty() ? '—' : round($reported->avg(fn (Record $study) => (int) $study->value('_report_hours')), 1).' h', $reported->filter(fn (Record $study) => $study->value('_report_late'))->count(), $this->money($group->where('status', '!=', 'cancelled')->sum('amount'))];
        })->values()->all();

        $byReferrer = $studies->groupBy(fn (Record $study) => filled($study->value('referring_doctor')) ? (string) $study->value('referring_doctor') : 'Self-referred')->sortKeys()
            ->map(fn (Collection $group, string $doctor) => [$doctor, $group->count(), $group->where('status', 'reported')->count(), $this->money($group->where('status', '!=', 'cancelled')->sum('amount'))])->values()->all();

        $radiologists = User::query()->whereIn('id', $studies->pluck('assignee_id')->filter()->unique())->pluck('name', 'id');
        $byRadiologist = $studies->where('status', 'reported')->groupBy(fn (Record $study) => $radiologists[$study->assignee_id] ?? 'Unassigned')->sortKeys()
            ->map(fn (Collection $group, string $name) => [$name, $group->count(), round($group->avg(fn (Record $study) => (int) $study->value('_report_hours')), 1).' h'])->values()->all();

        return [
            ['title' => 'Studies by modality', 'columns' => ['Modality', 'Studies', 'Reported', 'Average report time', 'Late reports', 'Fees'], 'rows' => $byModality],
            ['title' => 'Studies by referrer', 'columns' => ['Referring doctor', 'Studies', 'Reported', 'Fees'], 'rows' => $byReferrer],
            ['title' => 'Reports by radiologist', 'columns' => ['Radiologist', 'Reports', 'Average report time'], 'rows' => $byRadiologist],
        ];
    }
}
