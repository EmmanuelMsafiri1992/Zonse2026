<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ImportRun;
use App\Support\Import\Importer;
use App\Support\Import\ImportTarget;
use App\Support\Import\Spreadsheet;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The data import wizard: upload a CSV or Excel file (or an export from QuickBooks, Sage or Xero),
 * match its columns, check the preview, import, and undo if it went wrong.
 */
class ImportController extends Controller
{
    public function __construct(protected WorkspaceContext $context, protected Importer $importer) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();
        $targets = $this->importer->targets($workspace);

        return view('settings.imports.index', [
            'targets' => $targets,
            'selected' => array_key_exists((string) $request->query('target'), $targets) ? (string) $request->query('target') : array_key_first($targets),
            'runs' => ImportRun::query()->with('user')->latest('id')->paginate(15),
            'maxRows' => Importer::MAX_ROWS,
        ]);
    }

    /** A blank file with the right headings and one example row, as Excel or CSV. */
    public function template(string $target, string $format): Response
    {
        $definition = $this->targetOrFail($target);
        $columns = $definition->columns();
        $headers = array_values(array_map(fn (array $column) => $column['label'], $columns));
        $example = [array_values(array_map(fn (array $column) => (string) ($column['example'] ?? ''), $columns))];
        $name = Str::slug($definition->label()).'-import-template';

        return $format === 'xlsx'
            ? response(Spreadsheet::xlsx($headers, $example, Str::limit($definition->label(), 28, '')), 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$name.'.xlsx"',
            ])
            : response(Spreadsheet::csv($headers, $example), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$name.'.csv"',
            ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'target' => ['required', Rule::in(array_keys($this->importer->targets($workspace)))],
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt,xlsx'],
        ], ['target.in' => 'Choose what to import.', 'file.extensions' => 'Upload a CSV or Excel (.xlsx) file. Older .xls files need saving as .xlsx first.', 'file.max' => 'The file is larger than 5 MB. Split it into smaller files.']);

        $run = $this->importer->start($workspace, $request->user(), $this->targetOrFail($data['target']), $request->file('file'));

        return redirect()->route('settings.imports.show', $run)->with('flash', ['type' => 'success', 'message' => $run->total_rows.' '.Str::plural('row', $run->total_rows).' read from '.$run->filename.'. Check the columns are matched correctly.']);
    }

    public function show(ImportRun $import): View
    {
        $target = $this->targetOrFail($import->target);

        return view('settings.imports.show', [
            'run' => $import,
            'target' => $target,
            'columns' => $target->columns(),
            'extraOptions' => $target->extraOptions($import->headers),
            'samples' => collect((array) $import->rows)->take(3)->pluck('cells')->all(),
        ]);
    }

    /** Save the column matching and options, and work out the preview. */
    public function update(Request $request, ImportRun $import): RedirectResponse
    {
        abort_if($import->isFinished(), 409, 'This import has already run.');
        $target = $this->targetOrFail($import->target);
        $extra = $target->extraOptions($import->headers);
        $indexes = array_keys($import->headers);

        $rules = [
            'mapping' => ['array'],
            'options.duplicates' => ['required', Rule::in(array_keys(Importer::DUPLICATE_MODES))],
            'options.date_order' => ['required', Rule::in(array_keys(Importer::DATE_ORDERS))],
        ];
        $messages = [];
        foreach ($target->columns() as $key => $column) {
            $field = 'mapping.'.str_replace('.', '__', $key);
            $rules[$field] = [($column['required'] ?? false) ? 'required' : 'nullable', 'integer', Rule::in($indexes)];
            $messages[$field.'.required'] = 'Choose the column that holds '.strtolower($column['label']).'.';
        }
        foreach ($extra as $key => $option) {
            $rules['options.'.$key] = ['required', Rule::in(array_keys($option['options']))];
        }
        $data = $request->validate($rules, $messages);

        $mapping = [];
        foreach (array_keys($target->columns()) as $key) {
            $index = $data['mapping'][str_replace('.', '__', $key)] ?? null;
            $mapping[$key] = $index === null ? null : (int) $index;
        }
        $import->forceFill(['mapping' => $mapping, 'options' => $data['options'], 'status' => 'ready'])->save();
        $import->forceFill(['summary' => $this->importer->preview($import, $target)])->save();

        return redirect()->to(route('settings.imports.show', $import).'#preview');
    }

    public function run(ImportRun $import): RedirectResponse
    {
        abort_unless($import->status === 'ready', 409, 'Match the columns and check the preview first.');
        $run = $this->importer->import($import, $this->targetOrFail($import->target));

        $parts = array_filter([
            $run->created_count ? $run->created_count.' added' : null,
            $run->updated_count ? $run->updated_count.' updated' : null,
            $run->skipped_count ? $run->skipped_count.' skipped' : null,
            $run->failed_count ? $run->failed_count.' not imported because of problems' : null,
        ]);

        return redirect()->route('settings.imports.show', $run)->with('flash', [
            'type' => $run->failed_count ? 'warning' : 'success',
            'message' => 'Import finished: '.($parts ? implode(', ', $parts) : 'nothing to do').'.',
        ]);
    }

    public function undo(ImportRun $import): RedirectResponse
    {
        abort_unless($import->status === 'done', 409, 'Only a finished import can be undone.');
        $result = $this->importer->undo($import, $this->targetOrFail($import->target));

        return redirect()->route('settings.imports.show', $import)->with('flash', [
            'type' => 'success',
            'message' => 'Import undone: '.$result['removed'].' '.Str::plural('record', $result['removed']).' removed.'
                .($result['kept'] ? ' '.$result['kept'].' kept because they are already in use.' : '')
                .($import->updated_count ? ' Records the import updated keep their new values.' : ''),
        ]);
    }

    /** Throw away an import that has not run yet. */
    public function destroy(ImportRun $import): RedirectResponse
    {
        abort_if($import->isFinished(), 409, 'A finished import stays in the history. Undo it instead.');
        $import->delete();

        return redirect()->route('settings.imports.index')->with('flash', ['type' => 'success', 'message' => 'Import of '.$import->filename.' cancelled.']);
    }

    protected function targetOrFail(string $key): ImportTarget
    {
        return $this->importer->target($this->context->getOrFail(), $key)
            ?? abort(404, 'That kind of record cannot be imported here. Its app may be switched off.');
    }
}
