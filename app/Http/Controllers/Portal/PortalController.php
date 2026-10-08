<?php

namespace App\Http\Controllers\Portal;

use App\Blueprints\BlueprintRegistry;
use App\Http\Controllers\Controller;
use App\Models\PortalAccess;
use App\Models\Record;
use App\Models\SignatureSigner;
use App\Models\Workspace;
use App\Support\Audit;
use App\Support\Portal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Modules\Appointments\Models\Appointment;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Quote;

/**
 * What a signed-in contact sees in a workspace's portal. Every query is limited to the
 * contact behind the portal access, inside the workspace the middleware put in context.
 */
class PortalController extends Controller
{
    public function __construct(protected Portal $portal) {}

    protected function access(Request $request): PortalAccess
    {
        return $request->attributes->get('portalAccess');
    }

    protected function ensureSection(Workspace $workspace, string $section): void
    {
        abort_unless($this->portal->shows($workspace, $section), 404);
    }

    public function home(Request $request, Workspace $workspace): View
    {
        $contactId = $this->access($request)->contact_id;
        $counts = [];

        if ($this->portal->shows($workspace, 'invoices')) {
            $open = Invoice::where('contact_id', $contactId)->open()->get();
            $counts['invoices'] = ['value' => $open->count(), 'hint' => $open->count() ? 'unpaid, '.$open->first()->money($open->sum('balance')).' due' : 'nothing to pay'];
        }
        if ($this->portal->shows($workspace, 'appointments')) {
            $next = $this->upcomingAppointments($contactId)->first();
            $counts['appointments'] = ['value' => $this->upcomingAppointments($contactId)->count(), 'hint' => $next ? 'next on '.$next->starts_at->format('d M, H:i') : 'none coming up'];
        }
        if ($this->portal->shows($workspace, 'requests')) {
            $active = Ticket::forContact($contactId)->active()->count();
            $counts['requests'] = ['value' => $active, 'hint' => 'open'];
        }
        if ($this->portal->shows($workspace, 'documents')) {
            $counts['documents'] = ['value' => $this->documentsToSign($this->access($request))->count(), 'hint' => 'waiting for you'];
        }
        if ($this->portal->shows($workspace, 'records')) {
            $counts['records'] = ['value' => $this->contactRecords($contactId)->count(), 'hint' => 'on file'];
        }

        return view('portal.home', ['counts' => $counts, 'welcome' => $this->portal->welcome($workspace)]);
    }

    public function invoices(Request $request, Workspace $workspace): View
    {
        $this->ensureSection($workspace, 'invoices');
        $contactId = $this->access($request)->contact_id;

        return view('portal.invoices', [
            'invoices' => Invoice::where('contact_id', $contactId)->where('status', '!=', 'draft')->latest('issue_date')->latest('id')->get(),
            'quotes' => Quote::where('contact_id', $contactId)->where('status', '!=', 'draft')->latest('issue_date')->latest('id')->get(),
        ]);
    }

    public function appointments(Request $request, Workspace $workspace): View
    {
        $this->ensureSection($workspace, 'appointments');
        $contactId = $this->access($request)->contact_id;

        $upcoming = $this->upcomingAppointments($contactId)->get();

        return view('portal.appointments', [
            'upcoming' => $upcoming,
            'past' => Appointment::forContact($contactId)->with(['service', 'staff'])->whereNotIn('id', $upcoming->pluck('id'))
                ->latest('starts_at')->limit(50)->get(),
        ]);
    }

    public function cancelAppointment(Request $request, Workspace $workspace, int $appointment): RedirectResponse
    {
        $this->ensureSection($workspace, 'appointments');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $booking = Appointment::forContact($this->access($request)->contact_id)->findOrFail($appointment);
        abort_unless($booking->isActive() && ! $booking->isPast(), 422, 'Only upcoming bookings can be cancelled.');

        $booking->transitionTo('cancelled', trim('Cancelled by the client in the portal. '.($data['reason'] ?? '')));
        Audit::log('portal', 'appointment-cancelled', 'Client cancelled '.$booking->displayTitle().' in the portal', $booking);

        return back()->with('flash', ['type' => 'success', 'message' => 'Your booking is cancelled.']);
    }

    public function requests(Request $request, Workspace $workspace): View
    {
        $this->ensureSection($workspace, 'requests');

        return view('portal.requests', [
            'tickets' => Ticket::forContact($this->access($request)->contact_id)->latest('last_activity_at')->latest('id')->get(),
        ]);
    }

    public function storeRequest(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->ensureSection($workspace, 'requests');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:5000'],
        ]);
        $access = $this->access($request);

        $ticket = Ticket::create([
            'contact_id' => $access->contact_id,
            'requester_name' => $access->contact->displayName(),
            'requester_email' => $access->email,
            'subject' => $data['subject'],
            'body' => $data['body'],
            'channel' => 'web',
        ]);
        Audit::log('portal', 'request-created', 'Client opened '.$ticket->number.' in the portal', $ticket);

        return redirect()->route('portal.requests.show', [$workspace, $ticket->id])
            ->with('flash', ['type' => 'success', 'message' => 'Thanks, we have your request ('.$ticket->number.').']);
    }

    public function showRequest(Request $request, Workspace $workspace, int $ticket): View
    {
        $this->ensureSection($workspace, 'requests');
        $record = Ticket::forContact($this->access($request)->contact_id)->findOrFail($ticket);

        return view('portal.request', [
            'ticket' => $record,
            'replies' => $record->comments()->where('is_internal', false)->with('user')->reorder()->oldest()->get(),
        ]);
    }

    public function replyToRequest(Request $request, Workspace $workspace, int $ticket): RedirectResponse
    {
        $this->ensureSection($workspace, 'requests');
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $record = Ticket::forContact($this->access($request)->contact_id)->findOrFail($ticket);
        abort_if($record->status === 'closed', 422, 'This request is closed. Please open a new one.');

        $record->customerReply($data['body'], $this->access($request)->contact->displayName());

        return back()->with('flash', ['type' => 'success', 'message' => 'Reply sent.']);
    }

    public function documents(Request $request, Workspace $workspace): View
    {
        $this->ensureSection($workspace, 'documents');
        $access = $this->access($request);

        return view('portal.documents', [
            'waiting' => $this->documentsToSign($access),
            'signed' => SignatureSigner::where('email', $access->email)->where('status', 'signed')->with('request')->latest('signed_at')->get(),
        ]);
    }

    public function records(Request $request, Workspace $workspace): View
    {
        $this->ensureSection($workspace, 'records');

        return view('portal.records', ['records' => $this->contactRecords($this->access($request)->contact_id)]);
    }

    public function profile(Request $request, Workspace $workspace): View
    {
        return view('portal.profile', ['contact' => $this->access($request)->contact]);
    }

    public function updateProfile(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:40'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:120'],
        ]);

        $contact = $this->access($request)->contact;
        $contact->update($data);
        Audit::log('portal', 'profile-updated', $contact->displayName().' updated their details in the portal', $contact);

        return back()->with('flash', ['type' => 'success', 'message' => 'Your details are saved.']);
    }

    protected function upcomingAppointments(int $contactId): Builder
    {
        return Appointment::forContact($contactId)->active()->with(['service', 'staff'])
            ->where('ends_at', '>=', Appointment::localNow()->format('Y-m-d H:i:s'))
            ->oldest('starts_at');
    }

    /** @return Collection<int, SignatureSigner> */
    protected function documentsToSign(PortalAccess $access): Collection
    {
        return SignatureSigner::where('email', $access->email)
            ->whereIn('status', ['pending', 'viewed'])
            ->whereHas('request', fn ($query) => $query->where('status', 'pending'))
            ->with('request')
            ->latest('id')
            ->get();
    }

    /** @return Collection<int, Record> */
    protected function contactRecords(int $contactId): Collection
    {
        $registry = app(BlueprintRegistry::class);

        return Record::forContact($contactId)->latest('id')->limit(200)->get()
            ->filter(fn (Record $record) => $registry->get((string) $record->blueprint)?->entity((string) $record->entity) !== null)
            ->values();
    }
}
