<?php

namespace App\Support\Import;

use App\Blueprints\BlueprintRegistry;
use App\Models\ImportRun;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The import wizard's engine: reads the file, guesses which column is which, previews what each
 * row will do, imports the rows, and undoes an import.
 */
class Importer
{
    public const MAX_ROWS = 5000;

    /** Rows shown in the preview table. */
    public const PREVIEW_ROWS = 100;

    /** Problems kept for the report. */
    public const MAX_PROBLEMS = 500;

    public const DUPLICATE_MODES = [
        'skip' => 'Skip them and keep what is already there',
        'update' => 'Update them with the values in the file',
        'create' => 'Add them again as new records',
    ];

    public const DATE_ORDERS = ['dmy' => 'Day first (31/12/2026)', 'mdy' => 'Month first (12/31/2026)'];

    /** Headings that give away which accounting package wrote the file. */
    public const SOURCES = [
        'quickbooks' => ['name' => 'QuickBooks', 'markers' => ['customerfullname', 'vendor', 'productservicename', 'salespricerate', 'purchasecost', 'qtyonhand', 'reorderpoint', 'billingaddressline1', 'billingstreet', 'billingcity', 'openbalance', 'salesdescription', 'incomeaccount', 'customer']],
        'sage' => ['name' => 'Sage', 'markers' => ['accountreference', 'contactname', 'telephone', 'towncity', 'street1', 'town', 'vatregistrationnumber', 'vatnumber', 'stockcode', 'itemcode', 'salesprice', 'costprice', 'quantityinstock', 'reference']],
        'xero' => ['name' => 'Xero', 'markers' => ['contactname', 'emailaddress', 'poaddressline1', 'pocity', 'pocountry', 'phonenumber', 'mobilenumber', 'taxnumber', 'itemname', 'salesunitprice', 'purchasesunitprice', 'quantityonhand']],
    ];

    public function __construct(protected BlueprintRegistry $blueprints) {}

    /**
     * Everything this workspace can import into, by key.
     *
     * @return array<string, ImportTarget>
     */
    public function targets(Workspace $workspace): array
    {
        $targets = [];
        if ($workspace->hasModule('contacts')) {
            $targets['contacts'] = new ContactTarget;
        }
        if ($workspace->hasModule('invoicing')) {
            $targets['items'] = new ItemTarget;
        }
        foreach ($this->blueprints->all() as $app) {
            if (! $workspace->hasModule($app->key)) {
                continue;
            }
            foreach ($app->entities as $entity) {
                $target = new RecordTarget($app, $entity, $workspace);
                $targets[$target->key()] = $target;
            }
        }

        return $targets;
    }

    public function target(Workspace $workspace, string $key): ?ImportTarget
    {
        return $this->targets($workspace)[$key] ?? null;
    }

    /**
     * Read an uploaded file and open an import for it, with the columns matched as well as the headings allow.
     *
     * @throws ValidationException when the file is unreadable, empty or too long
     */
    public function start(Workspace $workspace, User $user, ImportTarget $target, UploadedFile $file): ImportRun
    {
        try {
            $rows = Spreadsheet::read((string) $file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }
        if (count($rows) < 2) {
            throw ValidationException::withMessages(['file' => 'The file needs a heading row and at least one row of data.']);
        }
        if (count($rows) - 1 > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.number_format(count($rows) - 1).' rows. Split it into files of at most '.number_format(self::MAX_ROWS).' rows.']);
        }

        $headerRow = array_shift($rows);
        $width = max(array_map(fn (array $row) => count($row['cells']), [$headerRow, ...$rows]));
        $headers = [];
        for ($index = 0; $index < $width; $index++) {
            $headers[] = trim(ltrim($headerRow['cells'][$index] ?? '', '*')) ?: 'Column '.($index + 1);
        }

        return ImportRun::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'target' => $target->key(),
            'source' => self::detectSource($headers),
            'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
            'status' => 'mapping',
            'headers' => $headers,
            'rows' => $rows,
            'mapping' => $this->guessMapping($target, $headers),
            'options' => $this->defaultOptions($target, $headers),
            'total_rows' => count($rows),
        ]);
    }

    /**
     * Which file column feeds each target column, matched on the heading, its aliases or the label.
     *
     * @param  list<string>  $headers
     * @return array<string, ?int>
     */
    public function guessMapping(ImportTarget $target, array $headers): array
    {
        $keys = array_map(fn (string $header) => Values::key($header), $headers);
        $used = [];
        $mapping = [];
        foreach ($target->columns() as $key => $column) {
            $mapping[$key] = null;
            $candidates = array_map(fn (string $name) => Values::key($name), [$column['label'], str_replace('data.', '', $key), ...($column['aliases'] ?? [])]);
            foreach ($candidates as $candidate) {
                $index = array_search($candidate, $keys, true);
                if ($index !== false && ! isset($used[$index])) {
                    $mapping[$key] = $index;
                    $used[$index] = true;
                    break;
                }
            }
        }

        return $mapping;
    }

    /** @param  list<string>  $headers */
    public static function detectSource(array $headers): ?string
    {
        $keys = array_map(fn (string $header) => Values::key($header), $headers);
        $scores = collect(self::SOURCES)->map(fn (array $source) => count(array_intersect($keys, $source['markers'])))->filter(fn (int $score) => $score >= 2)->sortDesc();

        return $scores->keys()->first();
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, string>
     */
    public function defaultOptions(ImportTarget $target, array $headers): array
    {
        return ['duplicates' => 'skip', 'date_order' => 'dmy']
            + collect($target->extraOptions($headers))->map(fn (array $option) => $option['default'])->all();
    }

    /**
     * Work out what every row will do, without saving anything.
     *
     * @return array{counts: array{create: int, update: int, skip: int, error: int, warning: int}, rows: list<array{line: int, action: string, label: string, messages: list<string>}>, problems: list<array{line: int, errors: list<string>, warnings: list<string>}>}
     */
    public function preview(ImportRun $run, ImportTarget $target): array
    {
        return $this->process($run, $target, false);
    }

    /** Save the rows. Rows with errors are left out; the rest go in together. */
    public function import(ImportRun $run, ImportTarget $target): ImportRun
    {
        $summary = DB::transaction(fn () => $this->process($run, $target, true));
        $run->forceFill([
            'status' => 'done',
            'summary' => Arr::except($summary, 'created_ids'),
            'rows' => null,
            'created_count' => $summary['counts']['create'],
            'updated_count' => $summary['counts']['update'],
            'skipped_count' => $summary['counts']['skip'],
            'failed_count' => $summary['counts']['error'],
            'created_ids' => $summary['created_ids'],
            'imported_at' => now(),
        ])->save();

        Audit::log('data', 'imported', 'Imported '.$target->label().' from '.$run->filename, $run, [
            'created' => $run->created_count, 'updated' => $run->updated_count, 'skipped' => $run->skipped_count, 'failed' => $run->failed_count,
        ]);

        return $run;
    }

    /**
     * Remove what an import added. Records something else now uses (an invoice, a booking) are kept.
     *
     * @return array{removed: int, kept: int}
     */
    public function undo(ImportRun $run, ImportTarget $target): array
    {
        $removed = 0;
        $kept = 0;
        DB::transaction(function () use ($run, $target, &$removed, &$kept) {
            $class = $target->modelClass();
            foreach (array_chunk($run->created_ids ?? [], 500) as $ids) {
                foreach ($class::query()->whereKey($ids)->get() as $model) {
                    if ($target->inUse($model)) {
                        $kept++;

                        continue;
                    }
                    if (method_exists($model, 'forceDelete')) {
                        $model->forceDelete();
                    } else {
                        $model->delete();
                    }
                    $removed++;
                }
            }
            $run->forceFill(['status' => 'undone', 'undone_at' => now()])->save();
        });

        Audit::log('data', 'import-undone', 'Undid the import of '.$run->filename, $run, ['removed' => $removed, 'kept' => $kept]);

        return ['removed' => $removed, 'kept' => $kept];
    }

    /** @return array{counts: array{create: int, update: int, skip: int, error: int, warning: int}, rows: list<array<string, mixed>>, problems: list<array<string, mixed>>, created_ids: list<int>} */
    protected function process(ImportRun $run, ImportTarget $target, bool $apply): array
    {
        $mapping = array_filter((array) $run->mapping, fn ($index) => $index !== null && $index !== '');
        $options = (array) $run->options;
        $mode = $options['duplicates'] ?? 'skip';
        $counts = ['create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0, 'warning' => 0];
        $rows = [];
        $problems = [];
        $createdIds = [];
        $seen = [];

        foreach ((array) $run->rows as $row) {
            $raw = [];
            foreach ($mapping as $column => $index) {
                $raw[$column] = (string) ($row['cells'][(int) $index] ?? '');
            }
            $prepared = $target->prepare($raw, $options);
            $errors = $prepared['errors'];
            $warnings = $prepared['warnings'];
            $values = $prepared['values'];
            $label = '';
            $action = 'error';

            if ($errors === []) {
                $matchKey = $target->matchKey($values);
                $existing = $mode === 'create' ? null : $target->findExisting($values);
                $repeat = $mode !== 'create' && ! $existing && $matchKey !== null && isset($seen[$matchKey]);
                $action = match (true) {
                    $existing !== null || $repeat => $mode === 'update' ? 'update' : 'skip',
                    default => 'create',
                };
                if ($action !== 'skip') {
                    $errors = $target->check($values, $existing);
                }
                if ($errors !== []) {
                    $action = 'error';
                } elseif ($apply && $action === 'create') {
                    $model = $target->create($values, $options);
                    $createdIds[] = $model->getKey();
                    $label = $target->describe($model);
                } elseif ($apply && $action === 'update' && $existing) {
                    $target->update($existing, $values);
                    $label = $target->describe($existing->refresh());
                }
                if ($label === '') {
                    $label = $existing ? $target->describe($existing) : (string) ($values['title'] ?? $values['name'] ?? '');
                }
                if ($matchKey !== null) {
                    $seen[$matchKey] = true;
                }
                if ($action === 'skip') {
                    $warnings = [$existing ? 'Already in Zonseo, so it is skipped.' : 'Repeats an earlier row, so it is skipped.'];
                } elseif ($repeat && $action === 'update' && ! $apply) {
                    $warnings[] = 'Repeats an earlier row, which it will update.';
                }
            }

            $counts[$action]++;
            if ($warnings !== [] && in_array($action, ['create', 'update'], true)) {
                $counts['warning']++;
            }
            if (count($rows) < self::PREVIEW_ROWS) {
                $rows[] = ['line' => $row['line'], 'action' => $action, 'label' => $label, 'messages' => $errors !== [] ? $errors : $warnings];
            }
            if (($errors !== [] || ($warnings !== [] && $action !== 'skip')) && count($problems) < self::MAX_PROBLEMS) {
                $problems[] = ['line' => $row['line'], 'errors' => $errors, 'warnings' => $action === 'skip' ? [] : $warnings];
            }
        }

        return ['counts' => $counts, 'rows' => $rows, 'problems' => $problems, 'created_ids' => $createdIds];
    }
}
