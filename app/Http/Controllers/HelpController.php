<?php

namespace App\Http\Controllers;

use App\Models\HelpFeedback;
use App\Support\Help\HelpArticle;
use App\Support\Help\HelpCentre;
use App\Support\Help\Tours;
use App\Tenancy\WorkspaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The help centre: guides, search, "was this helpful?", release notes and guided tours. */
class HelpController extends Controller
{
    public function __construct(protected HelpCentre $help, protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->get();
        $query = trim((string) $request->query('q', ''));

        return view('help.index', [
            'query' => $query,
            'results' => $query !== '' ? $this->help->search($query, $workspace) : null,
            'categories' => $this->help->categories($workspace),
            'latestRelease' => $this->help->releases()->first(),
            'unreadReleases' => $this->help->unreadReleases($request->user()),
            'tours' => collect(array_keys(Tours::TOURS))
                ->map(fn (string $key) => Tours::available($key, $request->user(), $workspace))
                ->filter()
                ->values(),
            'doneTours' => $request->user()->help_tours ?? [],
        ]);
    }

    public function show(Request $request, string $slug): View
    {
        $workspace = $this->context->get();
        $article = $this->help->article($slug, $workspace) ?? abort(404);
        $siblings = $this->help->articles($workspace)->where('category', $article->category)->values();
        $position = $siblings->search(fn (HelpArticle $item) => $item->slug === $article->slug);

        return view('help.show', [
            'article' => $article,
            'category' => HelpCentre::CATEGORIES[$article->category] ?? ['label' => 'Help', 'icon' => 'book-open'],
            'related' => $siblings->reject(fn (HelpArticle $item) => $item->slug === $article->slug)->take(5),
            'previous' => $position > 0 ? $siblings[$position - 1] : null,
            'next' => $siblings[$position + 1] ?? null,
            'feedback' => HelpFeedback::query()->where('user_id', $request->user()->id)->where('article', $article->slug)->first(),
        ]);
    }

    public function feedback(Request $request, string $slug): RedirectResponse
    {
        $article = $this->help->article($slug, $this->context->get()) ?? abort(404);
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        HelpFeedback::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'article' => $article->slug],
            ['workspace_id' => $this->context->get()?->id, 'helpful' => (bool) $data['helpful'], 'comment' => $data['comment'] ?? null],
        );

        return redirect()->to(route('help.show', $article->slug).'#feedback')->with('flash', [
            'type' => 'success',
            'message' => $data['helpful'] ? 'Thanks, glad it helped.' : 'Thanks. We will use your note to improve this guide.',
        ]);
    }

    /** What's new. Opening it clears the unread badge. */
    public function releases(Request $request): View
    {
        $user = $request->user();
        $seenBefore = $user->release_notes_seen_at ?? $user->created_at;
        $user->forceFill(['release_notes_seen_at' => now()])->save();

        return view('help.releases', ['releases' => $this->help->releases(), 'seenBefore' => $seenBefore]);
    }

    /** The tour was finished or closed: do not start it by itself again. */
    public function tourDone(Request $request, string $tour): JsonResponse
    {
        abort_unless(isset(Tours::TOURS[$tour]), 404);
        $data = $request->validate(['outcome' => ['required', Rule::in(['completed', 'dismissed'])]]);
        Tours::record($request->user(), $tour, $data['outcome']);

        return response()->json(['ok' => true]);
    }

    /** Take a tour again: open its page with the tour running. */
    public function tourStart(Request $request, string $tour): RedirectResponse
    {
        $definition = Tours::available($tour, $request->user(), $this->context->get()) ?? abort(404);
        Tours::reset($request->user(), $tour);

        return redirect()->route(Tours::TOURS[$definition['key']]['route']);
    }

    /** Platform staff: how readers rate each guide. */
    public function feedbackReport(Request $request): View
    {
        abort_unless($request->user()->is_super_admin, 403);
        $titles = $this->help->articles()->mapWithKeys(fn (HelpArticle $article) => [$article->slug => $article->title]);

        $ratings = HelpFeedback::query()
            ->selectRaw('article, count(*) as answers, sum(case when helpful then 1 else 0 end) as helpful')
            ->groupBy('article')
            ->get()
            ->map(fn ($row) => ['article' => $row->article, 'title' => $titles[$row->article] ?? $row->article, 'answers' => (int) $row->answers, 'helpful' => (int) $row->helpful])
            ->sortBy(fn (array $row) => $row['helpful'] / max($row['answers'], 1))
            ->values();

        return view('help.feedback', [
            'ratings' => $ratings,
            'comments' => HelpFeedback::query()->with('user')->whereNotNull('comment')->latest('updated_at')->limit(50)->get(),
            'titles' => $titles,
        ]);
    }
}
