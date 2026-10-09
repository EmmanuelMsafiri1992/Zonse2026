<?php

namespace App\Http\Controllers;

use App\Inbox\Inbox;
use App\Models\InboxChannel;
use App\Models\InboxConversation;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Contacts\Models\Contact;

/** One list of conversations from every channel, with the thread and a reply box beside it. */
class InboxController extends Controller
{
    public const VIEWS = ['open' => 'Open', 'mine' => 'Mine', 'unassigned' => 'Unassigned', 'closed' => 'Closed', 'all' => 'All'];

    public function __construct(protected WorkspaceContext $context, protected Inbox $inbox) {}

    public function index(Request $request): View
    {
        return $this->page($request);
    }

    public function show(Request $request, InboxConversation $conversation): View
    {
        if ($conversation->unread_count > 0) {
            $conversation->forceFill(['unread_count' => 0])->save();
        }

        return $this->page($request, $conversation);
    }

    public function reply(Request $request, InboxConversation $conversation): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.Inbox::MAX_LENGTH]]);
        $channel = $conversation->channel;
        if (! $channel || ! $channel->is_active) {
            return back()->withInput()->with('flash', ['type' => 'danger', 'message' => 'This channel is switched off, so replies cannot be sent.']);
        }

        $message = $this->inbox->reply($conversation, $data['body'], $request->user());
        $message->refresh();

        return redirect()->route('inbox.show', $conversation)->with('flash', match ($message->status) {
            'failed' => ['type' => 'danger', 'message' => 'The reply was not sent: '.$message->error],
            'sent' => ['type' => 'success', 'message' => $channel->test_mode ? 'Reply recorded (test mode: nothing was sent).' : 'Reply sent.'],
            default => ['type' => 'success', 'message' => 'Reply is on its way.'],
        });
    }

    public function update(Request $request, InboxConversation $conversation): RedirectResponse
    {
        $workspace = $this->context->getOrFail();
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(array_keys(InboxConversation::STATUSES))],
            'assigned_to' => ['sometimes', 'nullable', Rule::in($workspace->members()->pluck('users.id')->all())],
            'contact_id' => ['sometimes', 'nullable', Rule::exists('contacts', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
        ]);

        $conversation->forceFill($data)->save();

        $message = match (true) {
            ($data['status'] ?? null) === 'closed' => 'Conversation closed.',
            ($data['status'] ?? null) === 'open' => 'Conversation reopened.',
            array_key_exists('assigned_to', $data) => $conversation->assignee ? 'Assigned to '.$conversation->assignee->name.'.' : 'Unassigned.',
            default => 'Contact linked.',
        };

        return back()->with('flash', ['type' => 'success', 'message' => $message]);
    }

    /** Save the person writing in as a new contact, filling in the email or number they wrote from. */
    public function createContact(InboxConversation $conversation): RedirectResponse
    {
        if ($conversation->contact_id) {
            return back();
        }

        $type = $conversation->channel?->type;
        $contact = Contact::create([
            'workspace_id' => $conversation->workspace_id,
            'type' => 'customer',
            'kind' => 'person',
            'name' => $conversation->name ?: $conversation->handle,
            'email' => $type === 'email' ? $conversation->handle : null,
            'mobile' => in_array($type, ['sms', 'whatsapp'], true) ? $conversation->handle : null,
            'notes' => in_array($type, ['facebook', 'instagram'], true) ? 'Added from '.$conversation->channel->name.' messages.' : null,
            'is_active' => true,
        ]);
        $conversation->forceFill(['contact_id' => $contact->id])->save();

        Audit::log('inbox', 'contact-created', "Saved {$contact->name} as a contact from the inbox", $contact);

        return back()->with('flash', ['type' => 'success', 'message' => "{$contact->name} saved as a contact."]);
    }

    protected function page(Request $request, ?InboxConversation $current = null): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(array_keys(self::VIEWS))],
            'channel' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $view = $filters['view'] ?? 'open';
        $search = trim((string) ($filters['q'] ?? ''));

        $conversations = InboxConversation::query()->with(['channel', 'contact', 'assignee'])
            ->when($view === 'open', fn (Builder $query) => $query->where('status', 'open'))
            ->when($view === 'closed', fn (Builder $query) => $query->where('status', 'closed'))
            ->when($view === 'mine', fn (Builder $query) => $query->where('status', 'open')->where('assigned_to', $user->id))
            ->when($view === 'unassigned', fn (Builder $query) => $query->where('status', 'open')->whereNull('assigned_to'))
            ->when($filters['channel'] ?? null, fn (Builder $query, $channel) => $query->where('inbox_channel_id', $channel))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")->orWhere('handle', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")->orWhere('last_message_preview', 'like', "%{$search}%")
                ->orWhereHas('contact', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        $counts = InboxConversation::query()->open()
            ->selectRaw('COUNT(*) as open_count')
            ->selectRaw('SUM(CASE WHEN assigned_to = ? THEN 1 ELSE 0 END) as mine_count', [$user->id])
            ->selectRaw('SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned_count')
            ->first();

        $workspace = $this->context->getOrFail();
        $current?->load(['channel', 'contact', 'assignee', 'messages' => fn ($query) => $query->with('sender')->orderBy('id')]);

        return view('inbox.index', [
            'conversations' => $conversations,
            'current' => $current,
            'channels' => InboxChannel::query()->orderBy('name')->get(),
            'members' => $workspace->members()->orderBy('name')->get(['users.id', 'users.name']),
            'contacts' => $current && ! $current->contact_id ? Contact::query()->active()->orderBy('name')->limit(300)->get(['id', 'name', 'company_name', 'kind']) : collect(),
            'view' => $view,
            'filters' => $filters,
            'counts' => [
                'open' => (int) ($counts->open_count ?? 0),
                'mine' => (int) ($counts->mine_count ?? 0),
                'unassigned' => (int) ($counts->unassigned_count ?? 0),
            ],
        ]);
    }
}
