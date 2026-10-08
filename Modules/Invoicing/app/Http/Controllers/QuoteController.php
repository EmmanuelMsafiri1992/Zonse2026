<?php

namespace Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Approvals;
use App\Support\Lists;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Documents\DocumentPdf;
use Modules\Invoicing\Http\Requests\DocumentRequest;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Item;
use Modules\Invoicing\Models\Quote;
use Modules\Invoicing\Models\TaxRate;
use Symfony\Component\HttpFoundation\Response;

class QuoteController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quote::class);
        Quote::refreshExpired();

        $filters = $this->filters($request);
        $quotes = $this->filtered($filters)->with('contact')->latest('issue_date')->latest('id')->paginate(20)->withQueryString();

        $stats = [
            'open' => Quote::query()->where('status', 'sent')->sum('total'),
            'open_count' => Quote::query()->where('status', 'sent')->count(),
            'accepted' => Quote::query()->whereIn('status', ['accepted', 'converted'])->whereBetween('issue_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->sum('total'),
            'drafts' => Quote::query()->where('status', 'draft')->count(),
        ];
        $contact = $filters['contact'] ? Contact::query()->find($filters['contact']) : null;

        return view('invoicing::quotes.index', compact('quotes', 'filters', 'stats', 'contact'));
    }

    public function create(Request $request, WorkspaceContext $context): View
    {
        $this->authorize('create', Quote::class);

        $workspace = $context->getOrFail();
        $quote = new Quote([
            'contact_id' => $request->integer('contact') ?: null,
            'issue_date' => today(),
            'valid_until' => today()->addDays((int) $workspace->setting('invoicing.quote_valid_days', 30)),
            'currency_code' => $workspace->currency_code,
            'terms' => $workspace->setting('invoicing.terms'),
        ]);

        return view('invoicing::quotes.form', $this->formData($quote));
    }

    public function store(DocumentRequest $request): RedirectResponse
    {
        $this->authorize('create', Quote::class);

        $quote = DB::transaction(function () use ($request) {
            $quote = Quote::create($request->documentPayload());

            return $quote->syncLines($request->lines());
        });

        return redirect()->route('quotes.show', $quote)->with('flash', ['type' => 'success', 'message' => $quote->number.' was created.']);
    }

    public function show(Quote $quote): View
    {
        $this->authorize('view', $quote);

        $quote->load(['contact', 'branch', 'creator', 'lines.item', 'invoice', 'comments.user']);

        return view('invoicing::quotes.show', ['quote' => $quote]);
    }

    public function edit(Quote $quote): View|RedirectResponse
    {
        $this->authorize('update', $quote);

        if (! $quote->isEditable()) {
            return redirect()->route('quotes.show', $quote)->with('flash', ['type' => 'warning', 'message' => 'A quote that has been accepted, rejected or invoiced can no longer be edited.']);
        }
        $quote->load('lines');

        return view('invoicing::quotes.form', $this->formData($quote));
    }

    public function update(DocumentRequest $request, Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);

        if (! $quote->isEditable()) {
            return redirect()->route('quotes.show', $quote)->with('flash', ['type' => 'warning', 'message' => 'This quote can no longer be edited.']);
        }

        DB::transaction(function () use ($request, $quote) {
            $quote->update($request->documentPayload() + ['status' => $quote->status === 'expired' ? 'draft' : $quote->status]);
            $quote->syncLines($request->lines());
        });

        return redirect()->route('quotes.show', $quote)->with('flash', ['type' => 'success', 'message' => 'Quote updated.']);
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        $this->authorize('delete', $quote);

        $number = $quote->number;
        $quote->delete();

        return redirect()->route('quotes.index')->with('flash', ['type' => 'success', 'message' => $number.' was deleted.']);
    }

    public function send(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        if ($rule = Approvals::blocking($quote, 'quote.send', $request->user())) {
            return back()->with('flash', ['type' => 'warning', 'message' => $quote->number.' needs approval before it goes out ('.$rule->name.'). Ask for approval below.']);
        }

        $wasDraft = $quote->status === 'draft';
        $quote->markSent();
        if ($wasDraft) {
            Approvals::settle($quote, 'quote.send', $request->user(), 'Sent it directly.');
        }

        return back()->with('flash', ['type' => 'success', 'message' => $quote->number.' is marked as sent.']);
    }

    public function accept(Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        $quote->accept();

        return back()->with('flash', ['type' => 'success', 'message' => $quote->number.' accepted. You can now turn it into an invoice.']);
    }

    public function reject(Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        $quote->reject();

        return back()->with('flash', ['type' => 'success', 'message' => $quote->number.' marked as rejected.']);
    }

    public function convert(Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);
        $this->authorize('create', Invoice::class);

        if ($quote->status === 'rejected') {
            return back()->with('flash', ['type' => 'danger', 'message' => 'A rejected quote cannot be invoiced.']);
        }
        $invoice = $quote->convertToInvoice();

        return redirect()->route('invoices.show', $invoice)->with('flash', ['type' => 'success', 'message' => $quote->number.' became invoice '.$invoice->number.'.']);
    }

    public function print(Quote $quote): View
    {
        $this->authorize('view', $quote);
        $quote->load(['contact', 'branch', 'lines']);

        return view('invoicing::quotes.print', ['quote' => $quote, 'document' => $quote, 'kind' => 'quote']);
    }

    public function pdf(Quote $quote): Response
    {
        $this->authorize('view', $quote);
        $quote->load(['workspace', 'contact', 'branch', 'lines']);

        return DocumentPdf::response($quote, 'quote');
    }

    public function comment(Request $request, Quote $quote): RedirectResponse
    {
        $this->authorize('update', $quote);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $quote->addComment($data['body'], $request->user(), true);

        return back()->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    /** @return array{q: string, status: ?string, contact: ?int} */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status') ?: null,
            'contact' => $request->integer('contact') ?: null,
        ];
    }

    /**
     * @param  array{q: string, status: ?string, contact: ?int}  $filters
     * @return Builder<Quote>
     */
    protected function filtered(array $filters): Builder
    {
        return Quote::query()->search($filters['q'])->forContact($filters['contact'])->status($filters['status']);
    }

    /** @return array<string, mixed> */
    protected function formData(Quote $quote): array
    {
        return [
            'quote' => $quote,
            'document' => $quote,
            'kind' => 'quote',
            'contacts' => Contact::query()->active()->whereIn('type', ['customer', 'lead', 'other'])->orderBy('name')->get(['id', 'name', 'company_name', 'kind', 'currency_code']),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'currencies' => Lists::CURRENCIES,
            'items' => Item::query()->active()->with('taxRate')->orderBy('name')->get()->map->toPickerRow()->values(),
            'taxRates' => TaxRate::query()->active()->orderBy('rate')->get(['id', 'name', 'rate', 'is_default']),
            'defaultTaxRate' => TaxRate::defaultRate(),
        ];
    }
}
