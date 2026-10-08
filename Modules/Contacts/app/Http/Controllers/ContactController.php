<?php

namespace Modules\Contacts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Support\Lists;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Contacts\Http\Requests\ContactRequest;
use Modules\Contacts\Models\Contact;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContactController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Contact::class);

        $filters = $this->filters($request);
        $contacts = $this->filtered($filters)->with('branch')->orderBy('name')->paginate(20)->withQueryString();
        $counts = Contact::query()->where('is_active', true)->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        return view('contacts::index', compact('contacts', 'filters', 'counts'));
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Contact::class);

        $contact = new Contact(['type' => $request->query('type', 'customer'), 'kind' => 'person', 'is_active' => true]);

        return view('contacts::form', $this->formData($contact));
    }

    public function store(ContactRequest $request): RedirectResponse
    {
        $this->authorize('create', Contact::class);

        $contact = Contact::create($request->payload());

        return redirect()->route('contacts.show', $contact)->with('flash', ['type' => 'success', 'message' => $contact->displayName().' was added.']);
    }

    public function show(Contact $contact): View
    {
        $this->authorize('view', $contact);

        $contact->load(['branch', 'creator', 'comments.user']);

        return view('contacts::show', ['contact' => $contact]);
    }

    public function edit(Contact $contact): View
    {
        $this->authorize('update', $contact);

        return view('contacts::form', $this->formData($contact));
    }

    public function update(ContactRequest $request, Contact $contact): RedirectResponse
    {
        $this->authorize('update', $contact);

        $contact->update($request->payload());

        return redirect()->route('contacts.show', $contact)->with('flash', ['type' => 'success', 'message' => 'Contact updated.']);
    }

    public function destroy(Contact $contact): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $name = $contact->displayName();
        $contact->delete();

        return redirect()->route('contacts.index')->with('flash', ['type' => 'success', 'message' => $name.' was deleted.']);
    }

    public function comment(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorize('update', $contact);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $contact->addComment($data['body'], $request->user(), true);

        return back()->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Contact::class);

        $query = $this->filtered($this->filters($request))->orderBy('name');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Name', 'Company', 'Type', 'Email', 'Phone', 'Mobile', 'Tax number', 'Address', 'City', 'Country', 'Currency', 'Tags', 'Active']);
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    fputcsv($out, [
                        $c->name, $c->company_name, $c->typeLabel(), $c->email, $c->phone, $c->mobile, $c->tax_number,
                        $c->address, $c->city, $c->country_code, $c->currency_code, implode(', ', $c->tags ?? []), $c->is_active ? 'yes' : 'no',
                    ]);
                }
            });
            fclose($out);
        }, 'contacts-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array{q: string, type: ?string, status: string} */
    protected function filters(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'type' => $request->query('type') ?: null,
            'status' => (string) $request->query('status', 'active'),
        ];
    }

    /**
     * @param  array{q: string, type: ?string, status: string}  $filters
     * @return Builder<Contact>
     */
    protected function filtered(array $filters): Builder
    {
        $query = Contact::query()->search($filters['q'])->ofType($filters['type']);

        return match ($filters['status']) {
            'archived' => $query->where('is_active', false),
            'all' => $query,
            default => $query->where('is_active', true),
        };
    }

    /** @return array<string, mixed> */
    protected function formData(Contact $contact): array
    {
        return [
            'contact' => $contact,
            'types' => Contact::TYPES,
            'kinds' => Contact::KINDS,
            'countries' => Lists::COUNTRIES,
            'currencies' => Lists::CURRENCIES,
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
        ];
    }
}
