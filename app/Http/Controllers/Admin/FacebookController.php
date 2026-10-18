<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SocialPost;
use App\Social\FacebookPage;
use App\Social\PostCard;
use App\Social\PostPlanner;
use App\Social\SocialException;
use App\Social\SocialPoster;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Facebook series for the people who run Zonseob: connect the Page, choose posting times,
 * see what is coming next and what has gone out, and push the next post by hand.
 */
class FacebookController extends Controller
{
    public function __construct(protected SocialPoster $poster, protected PostPlanner $planner) {}

    public function index(): View
    {
        $sequence = $this->planner->sequence();
        $remaining = $this->planner->remaining(SocialPoster::CHANNEL);

        return view('admin.facebook', [
            'settings' => $this->poster->settings(),
            'configured' => $this->poster->isConfigured(),
            'total' => count($sequence),
            'remainingCount' => $remaining->count(),
            'upcoming' => $remaining->take(8),
            'posts' => SocialPost::query()->where('channel', SocialPoster::CHANNEL)->latest()->limit(40)->get(),
            'failed' => SocialPost::query()->where('channel', SocialPoster::CHANNEL)->where('status', SocialPost::STATUS_FAILED)->count(),
            'nextSlot' => $this->poster->dueSlot(),
            'cardsAvailable' => app(PostCard::class)->available(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'page_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'page_token' => ['nullable', 'string', 'max:1000'],
            'times' => ['required', 'string', 'regex:/^\s*([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*\s*$/'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'enabled' => ['nullable', 'boolean'],
        ], [
            'page_id.regex' => 'The Page ID is the number in the Page URL (facebook.com/profile.php?id=…).',
            'times.regex' => 'Posting times are 24-hour times separated by commas, like 09:00,15:00.',
        ]);

        $this->poster->saveSettings([
            'page_id' => $data['page_id'],
            'times' => preg_replace('/\s+/', '', $data['times']),
            'timezone' => $data['timezone'],
            'enabled' => (bool) ($data['enabled'] ?? false),
        ]);
        if (($data['page_token'] ?? '') !== '') {
            $this->poster->saveToken(trim($data['page_token']));
        }

        return back()->with('flash', ['type' => 'success', 'message' => 'Facebook settings saved.']);
    }

    /** Swaps a short-lived user token from Graph API Explorer for a long-lived Page token and stores it. */
    public function connect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'app_id' => ['required', 'string', 'max:40'],
            'app_secret' => ['required', 'string', 'max:120'],
            'user_token' => ['required', 'string', 'max:1000'],
        ]);
        $pageId = $this->poster->settings()['page_id'];
        if ($pageId === '') {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Save the Page ID first.']);
        }

        try {
            $token = FacebookPage::exchangeForPageToken($data['app_id'], $data['app_secret'], $data['user_token'], $pageId);
            $this->poster->saveToken($token);
            $name = (new FacebookPage($pageId, $token))->name();
        } catch (SocialException $e) {
            return back()->with('flash', ['type' => 'danger', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => "Connected to the Page \"{$name}\". The token does not expire while the app stays live."]);
    }

    public function disconnect(): RedirectResponse
    {
        $this->poster->saveToken('');
        $this->poster->saveSettings(['enabled' => false]);

        return back()->with('flash', ['type' => 'success', 'message' => 'Page token removed and posting switched off.']);
    }

    public function check(): RedirectResponse
    {
        try {
            $name = $this->poster->page()->name();
        } catch (SocialException $e) {
            return back()->with('flash', ['type' => 'danger', 'message' => $e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => "The token works: it can post to \"{$name}\"."]);
    }

    public function postNow(): RedirectResponse
    {
        if (! $this->poster->isConfigured()) {
            return back()->with('flash', ['type' => 'warning', 'message' => 'Connect the Page before posting.']);
        }

        $post = $this->poster->postNext();
        if (! $post) {
            return back()->with('flash', ['type' => 'success', 'message' => 'The series is complete: every post has been published.']);
        }

        return back()->with('flash', $post->isPosted()
            ? ['type' => 'success', 'message' => "Posted \"{$post->title}\"."]
            : ['type' => 'danger', 'message' => "Posting \"{$post->title}\" failed: {$post->error}"]);
    }

    /** The card image for a post in the series, so it can be checked before it goes out. */
    public function card(string $kind, string $key, PostCard $card): BinaryFileResponse
    {
        $draft = $this->planner->find($kind, $key);
        abort_unless($draft, 404);

        $path = $card->render($draft, storage_path('app/social/preview'));
        abort_unless($path, 404, 'Cards cannot be drawn on this server (GD or the fonts are missing).');

        return response()->file($path, ['Content-Type' => 'image/png', 'Cache-Control' => 'no-store']);
    }
}
