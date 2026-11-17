<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SocialPost;
use App\Social\LinkedInPoster;
use App\Social\PostCard;
use App\Social\PostPlanner;
use App\Social\SocialException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Facebook series, on the Zonseob LinkedIn Page: connect with a token, choose posting times,
 * see what is next and what has gone out, and push the next post by hand. The token never
 * reaches the browser.
 */
class LinkedInController extends Controller
{
    public function __construct(protected LinkedInPoster $poster, protected PostPlanner $planner) {}

    public function index(): View
    {
        $remaining = $this->poster->remaining();

        return view('admin.linkedin', [
            'settings' => $this->poster->settings(),
            'configured' => $this->poster->isConfigured(),
            'total' => count($this->planner->sequence()),
            'remainingCount' => $remaining->count(),
            'upcoming' => $remaining->take(8),
            'posts' => SocialPost::query()->where('channel', LinkedInPoster::CHANNEL)->latest()->limit(40)->get(),
            'failed' => SocialPost::query()->where('channel', LinkedInPoster::CHANNEL)->where('status', SocialPost::STATUS_FAILED)->count(),
            'nextSlot' => $this->poster->dueSlot(),
            'cardsAvailable' => app(PostCard::class)->available(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'organization_id' => ['nullable', 'string', 'regex:/^\d{3,30}$/'],
            'token' => ['nullable', 'string', 'max:2000'],
            'times' => ['required', 'string', 'regex:/^\s*([01]\d|2[0-3]):[0-5]\d(\s*,\s*([01]\d|2[0-3]):[0-5]\d)*\s*$/'],
            'enabled' => ['nullable', 'boolean'],
        ], [
            'organization_id.regex' => 'The Page ID is the number in the Page admin URL (linkedin.com/company/…/admin).',
            'times.regex' => 'Posting times are 24-hour times separated by commas, like 10:30,16:30.',
        ]);

        $values = ['times' => preg_replace('/\s+/', '', $data['times']), 'enabled' => (bool) ($data['enabled'] ?? false)];
        if (($data['organization_id'] ?? '') !== '') {
            $values['organization_id'] = $data['organization_id'];
        }
        $this->poster->saveSettings($values);

        if (trim((string) ($data['token'] ?? '')) === '') {
            return back()->with('flash', ['type' => 'success', 'message' => 'LinkedIn settings saved.']);
        }

        try {
            $name = $this->poster->connect(trim($data['token']));
        } catch (SocialException $e) {
            return back()->with('flash', ['type' => 'danger', 'message' => 'The token was not saved: '.$e->getMessage()]);
        }

        return back()->with('flash', ['type' => 'success', 'message' => "Connected to the LinkedIn Page \"{$name}\". The token lasts 60 days."]);
    }

    public function disconnect(): RedirectResponse
    {
        $this->poster->saveToken('');
        $this->poster->saveSettings(['enabled' => false]);

        return back()->with('flash', ['type' => 'success', 'message' => 'LinkedIn token removed and posting switched off.']);
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
            return back()->with('flash', ['type' => 'warning', 'message' => 'Connect the LinkedIn Page before posting.']);
        }

        $post = $this->poster->postNext();
        if (! $post) {
            return back()->with('flash', ['type' => 'success', 'message' => 'The series is complete on LinkedIn: every post has been published.']);
        }

        return back()->with('flash', $post->isPosted()
            ? ['type' => 'success', 'message' => "Posted \"{$post->title}\" to LinkedIn."]
            : ['type' => 'danger', 'message' => "Posting \"{$post->title}\" failed: {$post->error}"]);
    }
}
