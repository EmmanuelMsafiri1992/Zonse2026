<?php

namespace App\Http\Controllers\Apps;

use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordRequest;
use App\Models\Branch;
use App\Models\CustomField;
use App\Models\Record;
use App\Support\CustomFields;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Contacts\Models\Contact;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Generic list / form / detail screens for every blueprint app entity. */
class RecordController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BlueprintRegistry $blueprints, protected WorkspaceContext $context) {}

    public function index(Request $request, string $blueprint, string $entity): View
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $this->authorize('viewAny', Record::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', ''),
            'contact' => $request->query('contact'),
            'assignee' => $request->query('assignee'),
        ];

        $query = Record::query()->ofEntity($app->key, $def->key)->with(['contact', 'assignee'])
            ->search($filters['q'])->status($filters['status'])->forContact($filters['contact'])->assignedTo($filters['assignee'])
            ->orderByDesc('id');

        $counts = Record::query()->ofEntity($app->key, $def->key)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('apps.records.index', [
            'app' => $app,
            'def' => $def,
            'records' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'counts' => $counts,
            'members' => $this->memberOptions(),
            'contactName' => $filters['contact'] ? Contact::query()->find($filters['contact'])?->displayName() : null,
        ]);
    }

    public function create(Request $request, string $blueprint, string $entity): View
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $this->authorize('create', Record::class);

        $record = new Record([
            'blueprint' => $app->key, 'entity' => $def->key, 'status' => $def->defaultStatus(),
            'contact_id' => $request->query('contact'),
            'assignee_id' => $def->hasAssignee ? $request->user()->id : null,
            'data' => array_filter((array) $request->query('data', [])),
        ]);

        return view('apps.records.form', $this->formData($app, $def, $record));
    }

    public function store(RecordRequest $request, string $blueprint, string $entity): RedirectResponse
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $this->authorize('create', Record::class);

        $record = Record::create(array_merge($request->payload(), ['blueprint' => $app->key, 'entity' => $def->key]));

        return redirect()->to($record->url())
            ->with('flash', ['type' => 'success', 'message' => $def->label.' '.$record->number.' saved.']);
    }

    public function show(string $blueprint, string $entity, int $record): View
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $record = $this->find($app, $def, $record);
        $this->authorize('view', $record);

        $record->load(['contact', 'assignee', 'branch', 'creator', 'comments.user']);

        $linked = [];
        foreach ($app->entitiesReferencing($def->key) as $entityKey => $fields) {
            $child = $app->entity($entityKey);
            foreach ($fields as $field) {
                $linked[] = [
                    'entity' => $child,
                    'field' => $field,
                    'records' => Record::query()->ofEntity($app->key, $entityKey)->linkedTo($field->key, $record->id)->orderByDesc('id')->limit(10)->get(),
                    'count' => Record::query()->ofEntity($app->key, $entityKey)->linkedTo($field->key, $record->id)->count(),
                ];
            }
        }

        return view('apps.records.show', [
            'app' => $app,
            'def' => $def,
            'record' => $record,
            'linked' => $linked,
            'related' => collect($def->recordFields())->mapWithKeys(fn ($f) => [$f->key => $record->related($f->key)]),
            'billable' => $def->isBillable() && $this->context->hasModule('invoicing'),
            'invoices' => $def->isBillable() && $this->context->hasModule('invoicing') ? $record->invoices()->latest('id')->limit(12)->get() : collect(),
            'cards' => $app->logic()->recordCards($record),
            'actions' => $app->logic()->actions($record),
            'documents' => $app->logic()->documents($record),
        ]);
    }

    public function edit(string $blueprint, string $entity, int $record): View
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $record = $this->find($app, $def, $record);
        $this->authorize('update', $record);

        return view('apps.records.form', $this->formData($app, $def, $record));
    }

    public function update(RecordRequest $request, string $blueprint, string $entity, int $record): RedirectResponse
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $record = $this->find($app, $def, $record);
        $this->authorize('update', $record);

        $payload = $request->payload();
        // Keys starting with "_" are kept by the app logic (sale lines, billing marks), not the form.
        $payload['data'] = array_merge(array_filter((array) $record->data, fn ($key) => str_starts_with((string) $key, '_'), ARRAY_FILTER_USE_KEY), $payload['data']);
        $record->update($payload);

        return redirect()->to($record->url())
            ->with('flash', ['type' => 'success', 'message' => $def->label.' '.$record->number.' updated.']);
    }

    public function status(Request $request, string $blueprint, string $entity, int $record): RedirectResponse
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $record = $this->find($app, $def, $record);
        $this->authorize('update', $record);

        $validated = $request->validate(['status' => ['required', 'in:'.implode(',', array_keys($def->statuses))]]);
        $record->update(['status' => $validated['status']]);

        return back()->with('flash', ['type' => 'success', 'message' => $def->label.' marked '.strtolower($record->statusLabel()).'.']);
    }

    public function comment(Request $request, string $blueprint, string $entity, int $record): RedirectResponse
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $record = $this->find($app, $def, $record);
        $this->authorize('view', $record);

        $validated = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $record->addComment($validated['body'], $request->user(), true);

        return back()->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    public function destroy(string $blueprint, string $entity, int $record): RedirectResponse
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $record = $this->find($app, $def, $record);
        $this->authorize('delete', $record);

        $record->delete();

        return redirect()->route('apps.records.index', ['blueprint' => $app->key, 'entity' => $def->key])
            ->with('flash', ['type' => 'success', 'message' => $def->label.' '.$record->number.' deleted.']);
    }

    public function export(Request $request, string $blueprint, string $entity): StreamedResponse
    {
        [$app, $def] = $this->resolve($blueprint, $entity);
        $this->authorize('viewAny', Record::class);

        $records = Record::query()->ofEntity($app->key, $def->key)->with(['contact', 'assignee'])
            ->search($request->query('q'))->status($request->query('status'))->orderBy('id')->get();

        $filename = $app->key.'-'.$def->key.'-'.now()->format('Ymd').'.csv';

        $extra = CustomFields::for(CustomField::appEntity($app->key, $def->key));

        return response()->streamDownload(function () use ($records, $def, $extra) {
            $out = fopen('php://output', 'w');
            $header = ['Number', $def->titleLabel, 'Status'];
            if ($def->hasContact()) {
                $header[] = $def->contactLabel;
            }
            if ($def->hasAssignee) {
                $header[] = 'Assignee';
            }
            if ($def->hasAmount()) {
                $header[] = $def->amountLabel;
            }
            if ($def->hasDate()) {
                $header[] = $def->dateLabel;
            }
            if ($def->hasDue()) {
                $header[] = $def->dueLabel;
            }
            foreach ($def->fields as $field) {
                $header[] = $field->label;
            }
            foreach ($extra as $field) {
                $header[] = $field->label;
            }
            $header[] = 'Created';
            fputcsv($out, $header);

            foreach ($records as $record) {
                $row = [$record->number, $record->title, $record->statusLabel()];
                if ($def->hasContact()) {
                    $row[] = $record->contact?->displayName() ?? '';
                }
                if ($def->hasAssignee) {
                    $row[] = $record->assignee?->name ?? '';
                }
                if ($def->hasAmount()) {
                    $row[] = $record->amount !== null ? number_format((float) $record->amount, 2, '.', '') : '';
                }
                if ($def->hasDate()) {
                    $row[] = $record->occurs_on?->toDateString() ?? '';
                }
                if ($def->hasDue()) {
                    $row[] = $record->due_on?->toDateString() ?? '';
                }
                foreach ($def->fields as $field) {
                    $row[] = $record->displayValue($field);
                }
                foreach ($extra as $field) {
                    $row[] = $field->display($record->customField($field->key)) ?? '';
                }
                $row[] = $record->created_at?->toDateTimeString();
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ----- helpers ------------------------------------------------------------

    /** @return array{0: Blueprint, 1: Entity} */
    protected function resolve(string $blueprint, string $entity): array
    {
        $app = $this->blueprints->get($blueprint) ?? abort(404);
        $def = $app->entity($entity) ?? abort(404);

        return [$app, $def];
    }

    protected function find(Blueprint $app, Entity $def, int $id): Record
    {
        return Record::query()->ofEntity($app->key, $def->key)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    protected function formData(Blueprint $app, Entity $def, Record $record): array
    {
        $relatedOptions = [];
        foreach ($def->recordFields() as $field) {
            $relatedOptions[$field->key] = Record::query()->ofEntity($app->key, $field->relatedEntity)
                ->orderBy('title')->limit(500)->get()->mapWithKeys(fn (Record $r) => [$r->id => $r->title.' ('.$r->number.')'])->all();
        }

        return [
            'app' => $app,
            'def' => $def,
            'record' => $record,
            'contacts' => $def->hasContact() && $this->context->hasModule('contacts')
                ? Contact::query()->orderBy('name')->limit(500)->get()->mapWithKeys(fn (Contact $c) => [$c->id => $c->displayName()])->all()
                : [],
            'members' => $this->memberOptions(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'relatedOptions' => $relatedOptions,
        ];
    }

    /** @return Collection<int, string> */
    protected function memberOptions(): Collection
    {
        return $this->context->getOrFail()->members()->orderBy('name')->get()->pluck('name', 'id');
    }
}
