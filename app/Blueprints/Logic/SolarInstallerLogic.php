<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Solar installer: a site survey recommends a system size from the customer's monthly usage, is
 * quoted with a price, and is won at most once. An installation comes from a won survey, inherits
 * its customer and contract value, is commissioned only with its equipment and serial numbers
 * recorded, and gets a warranty date when handed over.
 */
class SolarInstallerLogic extends AppLogic
{
    /** Average daily peak-sun hours used to size a system. */
    public const SUN_HOURS = 5;

    public const WARRANTY_YEARS = 5;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'surveys') {
            if (filled($data['monthly_usage'] ?? null) && (float) $data['monthly_usage'] <= 0) {
                $errors['data.monthly_usage'] = 'Monthly usage must be more than 0 kWh.';
            }
            if (in_array($payload['status'], ['quoted', 'won'], true) && (float) ($payload['amount'] ?? 0) <= 0) {
                $errors['amount'] = 'Give the quote a price before it goes out.';
            }
            if ($payload['status'] === 'won' && $existing?->status === 'lost') {
                $errors['status'] = 'A lost survey cannot be won again. Book a new survey.';
            }

            return $errors;
        }

        $survey = ! empty($data['survey']) ? $this->records('surveys')->find($data['survey']) : null;
        if ($survey) {
            if ($survey->status !== 'won' && (! $existing || (int) $existing->value('survey') !== $survey->id)) {
                $errors['data.survey'] = $survey->title.' has not been won yet.';
            } elseif ($this->linked('installations', 'survey', $survey)->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['data.survey'] = $survey->title.' already has an installation.';
            }
        }
        if (in_array($payload['status'], ['commissioned', 'handed_over'], true)) {
            foreach (['panels', 'inverter', 'serial_numbers'] as $field) {
                if (blank($data[$field] ?? null)) {
                    $errors['data.'.$field] = 'Record the '.str_replace('_', ' ', $field).' before commissioning.';
                }
            }
        }
        if (filled($data['warranty_until'] ?? null) && filled($payload['occurs_on'] ?? null) && Carbon::parse($data['warranty_until'])->lt(Carbon::parse($payload['occurs_on']))) {
            $errors['data.warranty_until'] = 'The warranty cannot end before the install date.';
        }

        return $errors;
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'surveys') {
            $usage = $this->number($record, 'monthly_usage');
            if ($usage > 0 && blank($record->value('recommended_size'))) {
                $this->put($record, ['recommended_size' => round($usage / 30 / self::SUN_HOURS, 1)]);
            }

            return;
        }

        $survey = $this->parent($record, 'survey');
        if ($survey) {
            $record->contact_id ??= $survey->contact_id;
            $record->amount ??= $survey->amount;
        }
        if ($record->status === 'handed_over' && blank($record->value('warranty_until'))) {
            $this->put($record, ['warranty_until' => ($record->occurs_on ?? today())->copy()->addYears(self::WARRANTY_YEARS)->toDateString()]);
        }
        $this->put($record, ['_commissioned_on' => in_array($record->status, ['commissioned', 'handed_over'], true) ? ($record->value('_commissioned_on') ?? today()->toDateString()) : null]);
    }

    public function actions(Record $record): array
    {
        if ($record->entity === 'surveys') {
            return match ($record->status) {
                'booked' => ['done' => ['label' => 'Survey done', 'icon' => 'clipboard-check']],
                'done' => ['quote' => ['label' => 'Quote', 'icon' => 'file-text', 'fields' => [
                    ['name' => 'recommended_size', 'label' => 'System size (kW)', 'type' => 'number', 'value' => $record->value('recommended_size')],
                    ['name' => 'amount', 'label' => 'Quote', 'type' => 'number', 'value' => $record->amount],
                ]]],
                'quoted' => [
                    'win' => ['label' => 'Won', 'icon' => 'trophy', 'confirm' => 'Mark '.$record->title.' as won and schedule the installation?'],
                    'lose' => ['label' => 'Lost', 'icon' => 'x'],
                ],
                default => [],
            };
        }

        return match ($record->status) {
            'scheduled' => ['start' => ['label' => 'Start installing', 'icon' => 'hammer']],
            'installing' => ['commission' => ['label' => 'Commission', 'icon' => 'zap', 'fields' => [
                ['name' => 'panels', 'label' => 'Panels (make × qty)', 'type' => 'text', 'value' => $record->value('panels')],
                ['name' => 'inverter', 'label' => 'Inverter', 'type' => 'text', 'value' => $record->value('inverter')],
                ['name' => 'serial_numbers', 'label' => 'Serial numbers', 'type' => 'textarea', 'value' => $record->value('serial_numbers')],
            ]]],
            'commissioned' => ['hand_over' => ['label' => 'Hand over', 'icon' => 'key-round', 'confirm' => 'Hand over to the customer? The warranty starts today.']],
            default => [],
        };
    }

    public function runAction(string $action, Record $record, Request $request): string
    {
        switch ($action) {
            case 'done':
                $record->update(['status' => 'done']);

                return 'Survey of '.$record->title.' done.';
            case 'quote':
                $input = $request->validate(['recommended_size' => ['required', 'numeric', 'gt:0'], 'amount' => ['required', 'numeric', 'gt:0']]);
                $record->update(['status' => 'quoted', 'amount' => $input['amount'], 'data' => [...(array) $record->data, 'recommended_size' => $input['recommended_size']]]);

                return $record->title.' quoted at '.$this->money($input['amount']).' for a '.$input['recommended_size'].' kW system.';
            case 'win':
                $record->update(['status' => 'won']);
                $installation = Record::create([
                    'workspace_id' => $record->workspace_id, 'blueprint' => $record->blueprint, 'entity' => 'installations', 'title' => $record->title, 'status' => 'scheduled',
                    'contact_id' => $record->contact_id, 'assignee_id' => $record->assignee_id, 'amount' => $record->amount, 'currency' => $record->currency, 'occurs_on' => today()->addWeeks(2),
                    'data' => ['survey' => $record->id],
                ]);

                return $record->title.' won. Installation '.$installation->number.' scheduled for '.$installation->occurs_on->format('d M Y').'.';
            case 'lose':
                $record->update(['status' => 'lost']);

                return $record->title.' marked lost.';
            case 'start':
                $record->update(['status' => 'installing']);

                return 'Installation at '.$record->title.' under way.';
            case 'commission':
                $input = $request->validate(['panels' => ['required', 'string', 'max:200'], 'inverter' => ['required', 'string', 'max:200'], 'serial_numbers' => ['required', 'string']]);
                $record->update(['status' => 'commissioned', 'data' => [...(array) $record->data, ...$input]]);

                return $record->title.' commissioned.';
        }

        $record->update(['status' => 'handed_over']);

        return $record->title.' handed over. Warranty until '.Carbon::parse($record->fresh()->value('warranty_until'))->format('d M Y').'.';
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'surveys') {
            $usage = $this->number($record, 'monthly_usage');
            $size = $this->number($record, 'recommended_size');
            $installation = $this->linked('installations', 'survey', $record)->first();

            return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'System sizing', 'icon' => 'sun', 'stats' => [
                ['label' => 'Daily usage', 'value' => $usage > 0 ? round($usage / 30, 1).' kWh' : '—'],
                ['label' => 'Recommended', 'value' => $size > 0 ? $size.' kW' : '—'],
                ['label' => 'Est. daily output', 'value' => $size > 0 ? round($size * self::SUN_HOURS, 1).' kWh' : '—'],
                ['label' => 'Quote', 'value' => $record->amount > 0 ? $this->money($record->amount) : '—'],
                ['label' => 'Installation', 'value' => $installation ? $installation->number.' · '.str_replace('_', ' ', $installation->status) : '—', 'tone' => $installation ? 'success' : null],
            ]]]];
        }

        $warranty = $record->value('warranty_until') ? Carbon::parse($record->value('warranty_until')) : null;

        return [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'System', 'icon' => 'solar-panel', 'stats' => [
            ['label' => 'Panels', 'value' => $record->value('panels') ?: '—'],
            ['label' => 'Inverter', 'value' => $record->value('inverter') ?: '—'],
            ['label' => 'Batteries', 'value' => $record->value('batteries') ?: 'None'],
            ['label' => 'Commissioned', 'value' => $record->value('_commissioned_on') ? Carbon::parse($record->value('_commissioned_on'))->format('d M Y') : 'Not yet'],
            ['label' => 'Warranty', 'value' => $warranty?->format('d M Y') ?? '—', 'tone' => $warranty?->lt(today()) ? 'danger' : null],
        ]]]];
    }

    public function homeCards(): array
    {
        $surveys = $this->records('surveys')->get();
        $installations = $this->records('installations')->with('contact')->get();
        $pipeline = $surveys->where('status', 'quoted');
        $upcoming = $installations->whereIn('status', ['scheduled', 'installing'])->sortBy('occurs_on');
        $expiring = $installations->where('status', 'handed_over')->filter(fn (Record $install) => $install->value('warranty_until') && Carbon::parse($install->value('warranty_until'))->between(today(), today()->addDays(60)));

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Pipeline', 'icon' => 'sun', 'stats' => [
                ['label' => 'Surveys booked', 'value' => (string) $surveys->where('status', 'booked')->count()],
                ['label' => 'Quotes out', 'value' => $this->money($pipeline->sum('amount')).' ('.$pipeline->count().')'],
                ['label' => 'kW installed', 'value' => (string) round($installations->whereIn('status', ['commissioned', 'handed_over'])->sum(fn (Record $install) => (float) $this->parent($install, 'survey')?->value('recommended_size')), 1)],
                ['label' => 'Warranties ending soon', 'value' => (string) $expiring->count(), 'tone' => $expiring->isNotEmpty() ? 'warning' : null],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Upcoming installations', 'icon' => 'calendar-days', 'empty' => 'Nothing scheduled.',
                'rows' => $upcoming->take(10)->map(fn (Record $install) => [
                    'label' => $install->title, 'sub' => $install->contact?->name, 'value' => $install->occurs_on?->format('D d M') ?? 'Unscheduled', 'href' => $install->url(),
                    'tone' => $install->status === 'installing' ? 'success' : ($install->occurs_on?->lt(today()) ? 'danger' : null),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $surveys = $this->dated('surveys', $from, $to)->get();
        $roofs = $this->app->entities['surveys']->field('roof_type')?->options ?? [];
        $byRoof = collect($roofs)->map(fn (string $label, string $key) => [
            $label, $surveys->where('data.roof_type', $key)->count(), $surveys->where('data.roof_type', $key)->where('status', 'won')->count(),
            $this->money($surveys->where('data.roof_type', $key)->where('status', 'won')->sum('amount')),
        ])->values()->all();

        $funnel = collect($this->app->entities['surveys']->statuses)->map(fn (string $label, string $key) => [$label, $surveys->where('status', $key)->count(), $this->money($surveys->where('status', $key)->sum('amount'))])->values()->all();

        $installs = $this->dated('installations', $from, $to)->with('contact')->orderBy('occurs_on')->get()->map(fn (Record $install) => [
            $install->number, $install->title, $install->contact?->name ?? '—', $install->occurs_on?->format('d M Y') ?? '—', ucfirst(str_replace('_', ' ', $install->status)),
            $this->money($install->amount), $install->value('warranty_until') ? Carbon::parse($install->value('warranty_until'))->format('d M Y') : '—',
        ])->all();

        return [
            ['title' => 'Survey funnel', 'columns' => ['Stage', 'Surveys', 'Value'], 'rows' => $funnel],
            ['title' => 'Wins by roof type', 'columns' => ['Roof', 'Surveys', 'Won', 'Won value'], 'rows' => $byRoof],
            ['title' => 'Installations', 'columns' => ['Number', 'Site', 'Customer', 'Install date', 'Status', 'Value', 'Warranty'], 'rows' => $installs],
        ];
    }
}
