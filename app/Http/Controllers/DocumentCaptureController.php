<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocumentCapture;
use App\Models\DocumentCapture;
use App\Models\Record;
use App\Ocr\OcrService;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Contacts\Models\Contact;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scanning receipts, supplier invoices and ID documents: upload, check what was read, then save
 * as an expense or a contact. Members see their own scans; owners, admins and managers see all.
 * Viewers cannot use it, since ID documents hold personal details.
 */
class DocumentCaptureController extends Controller
{
    public const TABS = ['review' => 'To check', 'done' => 'Saved', 'all' => 'All'];

    /** Most files accepted in one upload. */
    public const MAX_FILES = 10;

    public function __construct(protected WorkspaceContext $context, protected OcrService $ocr) {}

    public function index(Request $request): View
    {
        $this->authorizeUse($request);
        $tab = $request->string('tab')->toString();
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'review';
        $workspace = $this->context->getOrFail();

        return view('captures.index', [
            'tab' => $tab,
            'captures' => $this->visible($request)->with('creator')
                ->when($tab === 'review', fn ($query) => $query->where('status', '!=', 'done'))
                ->when($tab === 'done', fn ($query) => $query->where('status', 'done'))
                ->latest('id')->paginate(25)->withQueryString(),
            'toCheck' => $this->visible($request)->where('status', '!=', 'done')->count(),
            'provider' => $this->ocr->provider($workspace),
            'types' => DocumentCapture::TYPES,
            'canConfigure' => $request->user()->can('manage-workspace'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeUse($request);
        $workspace = $this->context->getOrFail();
        if (! $this->ocr->enabled($workspace)) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Choose how documents are read in Document capture settings first.']);
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(DocumentCapture::TYPES))],
            'files' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'files.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'mimetypes:image/jpeg,image/png,image/webp,application/pdf', 'max:10240'],
        ], [
            'files.required' => 'Choose a photo or PDF to scan.',
            'files.max' => 'Upload at most '.self::MAX_FILES.' files at a time.',
            'files.*.mimes' => 'Upload a JPG, PNG or WebP photo, or a PDF.',
            'files.*.mimetypes' => 'Upload a JPG, PNG or WebP photo, or a PDF.',
        ]);

        $captures = [];
        $duplicates = 0;
        foreach ($data['files'] as $file) {
            if (DocumentCapture::query()->where('file_hash', hash_file('sha256', $file->getRealPath()))->exists()) {
                $duplicates++;

                continue;
            }
            $captures[] = $this->ocr->capture($workspace, $request->user(), $file, $data['type']);
        }

        $skipped = $duplicates ? ' '.$duplicates.' '.Str::plural('file', $duplicates).' had already been uploaded and '.($duplicates === 1 ? 'was' : 'were').' skipped.' : '';
        if (! $captures) {
            return back()->with('flash', ['type' => 'warning', 'message' => trim('Nothing new to scan.'.$skipped)]);
        }

        $flash = ['type' => 'success', 'message' => count($captures) === 1 ? 'Uploaded. Check what was read, then save it.'.$skipped : count($captures).' documents uploaded and being read.'.$skipped];

        return count($captures) === 1
            ? redirect()->route('captures.show', $captures[0])->with('flash', $flash)
            : redirect()->route('captures.index')->with('flash', $flash);
    }

    public function show(Request $request, DocumentCapture $capture): View
    {
        $this->authorizeCapture($request, $capture);
        $capture->load(['creator', 'result']);
        $workspace = $this->context->getOrFail();

        return view('captures.show', [
            'capture' => $capture,
            'possibleDuplicate' => $this->possibleDuplicate($capture),
            'canSaveExpense' => $this->context->hasModule('expenses'),
            'contactTypes' => Contact::TYPES,
            'currencies' => array_values(array_unique(array_filter([$workspace->currency_code, 'USD', 'ZWG', 'ZAR', 'KES', 'ZMW', 'BWP', 'NGN', 'GHS', 'EUR', 'GBP']))),
            'categories' => DocumentCapture::CATEGORIES,
        ]);
    }

    /** Save corrections, and optionally turn the scan into an expense or a contact. */
    public function update(Request $request, DocumentCapture $capture): RedirectResponse
    {
        $this->authorizeCapture($request, $capture);
        abort_if(in_array($capture->status, ['processing', 'done'], true), 403);

        $rules = ['action' => ['required', Rule::in(['save', 'expense', 'contact'])], 'contact_type' => ['nullable', Rule::in(array_keys(Contact::TYPES))]];
        foreach ($capture->fieldDefinitions() as $key => $meta) {
            $rules['fields.'.$key] = match ($meta['type']) {
                'date' => ['nullable', 'date'],
                'money' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
                'currency' => ['nullable', 'string', 'size:3', 'alpha'],
                'category' => ['nullable', Rule::in(DocumentCapture::CATEGORIES)],
                default => ['nullable', 'string', 'max:190'],
            };
        }
        $data = $request->validate($rules);
        $fields = collect($capture->fieldDefinitions())->map(function (array $meta, string $key) use ($data) {
            $value = trim((string) ($data['fields'][$key] ?? ''));

            return $value === '' ? null : match ($meta['type']) {
                'money' => number_format((float) $value, 2, '.', ''),
                'currency' => strtoupper($value),
                default => $value,
            };
        })->all();

        if ($data['action'] === 'expense') {
            abort_unless(in_array($capture->type, ['receipt', 'invoice'], true) && $this->context->hasModule('expenses'), 403);
            if ($fields['total'] === null) {
                return back()->withInput()->withErrors(['fields.total' => 'Enter the amount before saving the expense.']);
            }
            $record = $this->ocr->saveAsExpense($capture, $fields, $request->user());

            return redirect()->to($record->url())->with('flash', ['type' => 'success', 'message' => 'Expense '.$record->number.' saved from the scan.']);
        }

        if ($data['action'] === 'contact') {
            abort_unless($capture->type === 'id_document', 403);
            if ($fields['surname'] === null && $fields['first_names'] === null) {
                return back()->withInput()->withErrors(['fields.surname' => 'Enter the person\'s name before saving the contact.']);
            }
            $contact = $this->ocr->saveAsContact($capture, $fields, $request->user(), $data['contact_type'] ?? 'customer');

            return redirect()->to($contact->activityUrl())->with('flash', ['type' => 'success', 'message' => 'Contact '.$contact->name.' saved from the ID document.']);
        }

        $capture->forceFill(['fields' => $fields])->save();

        return back()->with('flash', ['type' => 'success', 'message' => 'Changes saved.']);
    }

    /** Read a failed scan again, e.g. after fixing the provider's key. */
    public function retry(Request $request, DocumentCapture $capture): RedirectResponse
    {
        $this->authorizeCapture($request, $capture);
        abort_unless($capture->status === 'failed', 403);
        if (! $this->ocr->enabled($this->context->getOrFail())) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Document capture is not set up. Choose a provider in settings first.']);
        }

        $capture->forceFill(['status' => 'processing', 'error' => null])->save();
        ProcessDocumentCapture::dispatch($capture->id);

        return back()->with('flash', ['type' => 'info', 'message' => 'Reading the document again.']);
    }

    public function file(Request $request, DocumentCapture $capture): Response
    {
        $this->authorizeCapture($request, $capture);
        $disk = Storage::disk(OcrService::DISK);
        abort_unless($disk->exists($capture->file_path), 404);

        return response($disk->get($capture->file_path), 200, [
            'Content-Type' => $capture->mime,
            'Content-Disposition' => 'inline; filename="'.str_replace(['"', '\\', "\r", "\n"], '', $capture->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(Request $request, DocumentCapture $capture): RedirectResponse
    {
        $this->authorizeCapture($request, $capture);
        $this->ocr->delete($capture);

        return redirect()->route('captures.index')->with('flash', ['type' => 'success', 'message' => 'Scan deleted.']);
    }

    /** An expense already saved with the same amount and date, so the same receipt is not claimed twice. */
    protected function possibleDuplicate(DocumentCapture $capture): ?Record
    {
        if ($capture->status === 'done' || ! $this->context->hasModule('expenses') || ! $capture->field('total') || ! $capture->field('date')) {
            return null;
        }

        return Record::query()->where('blueprint', 'expenses')->where('entity', 'expenses')
            ->where('amount', $capture->field('total'))->whereDate('occurs_on', $capture->field('date'))
            ->first();
    }

    /** @return Builder<DocumentCapture> */
    protected function visible(Request $request): Builder
    {
        return DocumentCapture::query()->when(! $this->seesAll($request), fn ($query) => $query->where('created_by', $request->user()->id));
    }

    protected function seesAll(Request $request): bool
    {
        return in_array($request->user()->roleIn($this->context->getOrFail()), ['owner', 'admin', 'manager'], true)
            || $request->user()->isAdminOf($this->context->getOrFail());
    }

    protected function authorizeUse(Request $request): void
    {
        abort_unless($request->user()->can('capture-documents'), 403);
    }

    protected function authorizeCapture(Request $request, DocumentCapture $capture): void
    {
        $this->authorizeUse($request);
        abort_unless($this->seesAll($request) || $capture->created_by === $request->user()->id, 403);
    }
}
