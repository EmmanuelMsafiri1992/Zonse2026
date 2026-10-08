<?php

namespace App\Http\Controllers;

use App\Support\Notifier;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The signed-in person's alerts for the current workspace, and their alert choices. */
class NotificationController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $unreadOnly = $request->boolean('unread');

        return view('notifications.index', [
            'notifications' => $this->inbox($request)->when($unreadOnly, fn ($q) => $q->whereNull('read_at'))
                ->latest()->paginate(30)->withQueryString(),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $this->inbox($request)->whereNull('read_at')->count(),
        ]);
    }

    /** Marks the alert read and follows its link. */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $alert = $this->inbox($request)->findOrFail($notification);
        $alert->markAsRead();

        // Links are only followed on this site, so an alert can never send someone elsewhere.
        $url = (string) ($alert->data['url'] ?? '');
        $isLocal = $url !== '' && in_array(parse_url($url, PHP_URL_HOST), [$request->getHost(), parse_url(config('app.url'), PHP_URL_HOST)], true);

        return $isLocal ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->inbox($request)->whereNull('read_at')->update(['read_at' => now()]);

        return back()->with('flash', ['type' => 'success', 'message' => 'All notifications marked as read.']);
    }

    public function destroy(Request $request, string $notification): RedirectResponse
    {
        $this->inbox($request)->findOrFail($notification)->delete();

        return back()->with('flash', ['type' => 'success', 'message' => 'Notification removed.']);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (array_keys(Notifier::KINDS) as $kind) {
            foreach (array_keys(Notifier::CHANNELS) as $channel) {
                $rules["preferences.$kind.$channel"] = ['nullable', 'boolean'];
            }
        }
        $request->validate($rules);

        $preferences = [];
        foreach (array_keys(Notifier::KINDS) as $kind) {
            foreach (array_keys(Notifier::CHANNELS) as $channel) {
                $preferences[$kind][$channel] = $request->boolean("preferences.$kind.$channel");
            }
        }
        $request->user()->forceFill(['notification_preferences' => $preferences])->save();

        return back()->with('flash', ['type' => 'success', 'message' => 'Notification choices saved.']);
    }

    protected function inbox(Request $request): MorphMany
    {
        return $request->user()->notifications()->where('workspace_id', $this->context->id());
    }
}
