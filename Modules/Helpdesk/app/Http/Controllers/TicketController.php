<?php

namespace Modules\Helpdesk\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Http\Requests\TicketRequest;
use Modules\Helpdesk\Models\Ticket;

class TicketController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        $filters = $this->filters($request);

        $query = Ticket::query()->with(['contact', 'assignee'])
            ->search($filters['q'])->priority($filters['priority'])->assignedTo($filters['assignee'])->forContact($filters['contact']);

        $query = match ($filters['status']) {
            'active' => $query->active()->orderByUrgency(),
            'all' => $query->orderByDesc('last_activity_at'),
            default => $query->status($filters['status'])->orderByDesc('last_activity_at'),
        };

        return view('helpdesk::tickets.index', [
            'tickets' => $query->paginate(25)->withQueryString(),
            'filters' => $filters,
            'statuses' => Ticket::STATUSES,
            'priorities' => Ticket::PRIORITIES,
            'members' => $this->memberOptions(),
            'stats' => [
                'open' => Ticket::query()->where('status', 'open')->count(),
                'unassigned' => Ticket::query()->active()->whereNull('assignee_id')->count(),
                'pending' => Ticket::query()->where('status', 'pending')->count(),
                'resolved_week' => Ticket::query()->whereIn('status', ['resolved', 'closed'])->where('resolved_at', '>=', now()->subDays(7))->count(),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Ticket::class);

        $ticket = new Ticket(['contact_id' => $request->query('contact'), 'assignee_id' => $request->user()->id]);

        return view('helpdesk::tickets.form', $this->formData($ticket));
    }

    public function store(TicketRequest $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = Ticket::create($request->payload());

        return redirect()->route('tickets.show', $ticket)
            ->with('flash', ['type' => 'success', 'message' => 'Ticket '.$ticket->number.' opened.']);
    }

    public function show(Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        $ticket->load(['contact', 'assignee', 'branch', 'creator', 'comments.user']);

        $others = $ticket->contact_id
            ? Ticket::query()->forContact($ticket->contact_id)->where('id', '!=', $ticket->id)->orderByDesc('created_at')->limit(5)->get()
            : collect();

        return view('helpdesk::tickets.show', [
            'ticket' => $ticket,
            'thread' => $ticket->comments->sortBy('created_at')->values(),
            'others' => $others,
            'statuses' => Ticket::STATUSES,
            'priorities' => Ticket::PRIORITIES,
            'members' => $this->memberOptions(),
        ]);
    }

    public function edit(Ticket $ticket): View
    {
        $this->authorize('update', $ticket);

        return view('helpdesk::tickets.form', $this->formData($ticket));
    }

    public function update(TicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('update', $ticket);

        $ticket->update($request->payload());

        return redirect()->route('tickets.show', $ticket)->with('flash', ['type' => 'success', 'message' => 'Ticket updated.']);
    }

    /** Quick changes from the ticket page: status, priority or assignee, one at a time or together. */
    public function triage(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $workspaceId = $request->user()->current_workspace_id;
        $data = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Ticket::STATUSES))],
            'priority' => ['nullable', Rule::in(array_keys(Ticket::PRIORITIES))],
            'assignee_id' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
        ]);

        $changes = array_filter([
            'status' => $data['status'] ?? null,
            'priority' => $data['priority'] ?? null,
        ]);
        if ($request->has('assignee_id')) {
            $changes['assignee_id'] = $data['assignee_id'] ?: null;
        }

        $ticket->update($changes);

        $message = isset($changes['status']) ? 'Ticket marked '.strtolower($ticket->statusLabel()).'.' : 'Ticket updated.';

        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    public function destroy(Ticket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        $ticket->delete();

        return redirect()->route('tickets.index')->with('flash', ['type' => 'success', 'message' => 'Ticket deleted.']);
    }

    /** Add a reply to the customer, an internal note, or log what the customer told you. */
    public function reply(Request $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'kind' => ['required', Rule::in(['reply', 'note', 'customer'])],
        ]);

        match ($data['kind']) {
            'note' => $ticket->note($data['body'], $request->user()),
            'customer' => $ticket->customerReply($data['body']),
            default => $ticket->reply($data['body'], $request->user()),
        };

        $message = match ($data['kind']) {
            'note' => 'Note added.',
            'customer' => 'Customer message logged. Ticket is open again.',
            default => 'Reply added. Ticket is now waiting on the customer.',
        };

        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    /** @return array{q: string, status: string, priority: ?string, assignee: ?string, contact: ?string} */
    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        if ($status === '') {
            $status = $request->query('contact') || $request->query('q') ? 'all' : 'active';
        }

        $assignee = $request->query('assignee') ?: null;
        if ($assignee === 'me') {
            $assignee = (string) $request->user()->id;
        }

        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($status, array_merge(['active', 'all'], array_keys(Ticket::STATUSES)), true) ? $status : 'active',
            'priority' => $request->query('priority') ?: null,
            'assignee' => $assignee,
            'contact' => $request->query('contact') ?: null,
        ];
    }

    /** @return Collection<int, string> */
    protected function memberOptions(): Collection
    {
        return app(WorkspaceContext::class)->getOrFail()->members()->orderBy('name')->get()->pluck('name', 'id');
    }

    /** @return array<string, mixed> */
    protected function formData(Ticket $ticket): array
    {
        return [
            'ticket' => $ticket,
            'contacts' => Contact::query()->active()->orderBy('name')->get()->mapWithKeys(fn (Contact $c) => [$c->id => $c->displayName().($c->email ? ' · '.$c->email : '')]),
            'members' => $this->memberOptions(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'channels' => Ticket::CHANNELS,
            'priorities' => Ticket::PRIORITIES,
        ];
    }
}
