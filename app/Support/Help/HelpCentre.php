<?php

namespace App\Support\Help;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The help centre's content: articles and release notes written as Markdown files under resources/help,
 * each starting with a front-matter block of "key: value" lines between --- markers.
 *
 * Articles: title, category, summary, keywords, routes (route-name patterns the article explains,
 * comma separated, "*" wildcards allowed), module (only shown when that module is on) and order.
 * Release notes: version, date (Y-m-d), title. Links can point at a page with route:name
 * or at another article with help:slug.
 */
class HelpCentre
{
    public const CATEGORIES = [
        'getting-started' => ['label' => 'Getting started', 'icon' => 'rocket'],
        'sales' => ['label' => 'Customers & sales', 'icon' => 'receipt'],
        'apps' => ['label' => 'Apps', 'icon' => 'layout-grid'],
        'team' => ['label' => 'Team & security', 'icon' => 'shield-check'],
        'settings' => ['label' => 'Settings & data', 'icon' => 'settings'],
    ];

    /** @var array<string, Collection<int, HelpArticle|ReleaseNote>> */
    protected array $loaded = [];

    public function __construct(protected ?string $path = null)
    {
        $this->path ??= resource_path('help');
    }

    /**
     * Every article the workspace can use, in reading order.
     *
     * @return Collection<int, HelpArticle>
     */
    public function articles(?Workspace $workspace = null): Collection
    {
        /** @var Collection<int, HelpArticle> $articles */
        $articles = $this->loaded['articles'] ??= collect($this->files('articles'))
            ->map(fn (string $file) => HelpArticle::fromFile($file, ...$this->parse($file)))
            ->sortBy(fn (HelpArticle $article) => [array_search($article->category, array_keys(self::CATEGORIES), true) === false ? 99 : array_search($article->category, array_keys(self::CATEGORIES), true), $article->order, $article->title])
            ->values();

        return $articles->filter(fn (HelpArticle $article) => $article->module === null || ($workspace?->hasModule($article->module) ?? true))->values();
    }

    public function article(string $slug, ?Workspace $workspace = null): ?HelpArticle
    {
        return $this->articles($workspace)->first(fn (HelpArticle $article) => $article->slug === $slug);
    }

    /**
     * Articles grouped under the known categories, in category order.
     *
     * @return array<string, array{label: string, icon: string, articles: Collection<int, HelpArticle>}>
     */
    public function categories(?Workspace $workspace = null): array
    {
        $groups = [];
        foreach ($this->articles($workspace)->groupBy('category') as $key => $articles) {
            $groups[$key] = (self::CATEGORIES[$key] ?? ['label' => Str::headline($key), 'icon' => 'book-open']) + ['articles' => $articles->values()];
        }

        return collect(self::CATEGORIES)->keys()->filter(fn (string $key) => isset($groups[$key]))
            ->mapWithKeys(fn (string $key) => [$key => $groups[$key]])
            ->union($groups)
            ->all();
    }

    /**
     * Articles about the page on screen, matched on its route name.
     *
     * @return Collection<int, HelpArticle>
     */
    public function forRoute(?string $routeName, ?Workspace $workspace = null, int $limit = 3): Collection
    {
        if (! $routeName) {
            return collect();
        }

        return $this->articles($workspace)
            ->filter(fn (HelpArticle $article) => collect($article->routes)->contains(fn (string $pattern) => Str::is($pattern, $routeName)))
            ->sortBy(fn (HelpArticle $article) => collect($article->routes)->contains($routeName) ? 0 : 1)
            ->take($limit)
            ->values();
    }

    /**
     * Articles holding every word of the query, best match first.
     *
     * @return Collection<int, HelpArticle>
     */
    public function search(string $query, ?Workspace $workspace = null): Collection
    {
        $words = collect(preg_split('/[^\pL\pN]+/u', Str::lower(Str::ascii($query)), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $word) => mb_strlen($word) >= 2)->unique()->values();
        if ($words->isEmpty()) {
            return collect();
        }

        return $this->articles($workspace)
            ->map(function (HelpArticle $article) use ($words) {
                $fields = [
                    [Str::lower(Str::ascii($article->title)), 6],
                    [Str::lower(Str::ascii(implode(' ', $article->keywords))), 4],
                    [Str::lower(Str::ascii($article->summary)), 3],
                    [Str::lower(Str::ascii($article->body)), 1],
                ];
                $score = 0;
                foreach ($words as $word) {
                    $wordScore = 0;
                    foreach ($fields as [$text, $weight]) {
                        $wordScore += min(substr_count($text, $word), 3) * $weight;
                    }
                    if ($wordScore === 0) {
                        return null;
                    }
                    $score += $wordScore;
                }

                return [$article, $score];
            })
            ->filter()
            ->sortByDesc(fn (array $match) => $match[1])
            ->map(fn (array $match) => $match[0])
            ->values();
    }

    /**
     * Release notes, newest first.
     *
     * @return Collection<int, ReleaseNote>
     */
    public function releases(): Collection
    {
        /** @var Collection<int, ReleaseNote> */
        return $this->loaded['releases'] ??= collect($this->files('releases'))
            ->map(fn (string $file) => ReleaseNote::fromFile($file, ...$this->parse($file)))
            ->sortByDesc(fn (ReleaseNote $note) => $note->date->format('Y-m-d').' '.str_pad($note->version, 20, '0', STR_PAD_LEFT))
            ->values();
    }

    /** Releases out since the user last opened "What's new" (or since they joined). */
    public function unreadReleases(User $user): int
    {
        $since = $user->release_notes_seen_at ?? $user->created_at;

        return $this->releases()->filter(fn (ReleaseNote $note) => $note->isNewSince($since))->count();
    }

    /** Markdown to safe HTML, with route:name and help:slug links resolved. */
    public function render(string $markdown): string
    {
        $markdown = preg_replace_callback('/\]\((route|help):([A-Za-z0-9_.\-]+)\)/', function (array $match) {
            $url = match ($match[1]) {
                'route' => Route::has($match[2]) && ! Route::getRoutes()->getByName($match[2])->parameterNames() ? route($match[2]) : '#',
                default => route('help.show', $match[2]),
            };

            return ']('.$url.')';
        }, $markdown);

        return Str::markdown($markdown, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** @return list<string> */
    protected function files(string $folder): array
    {
        $files = glob($this->path.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'*.md') ?: [];
        sort($files);

        return $files;
    }

    /**
     * Split a file into its front matter and Markdown body.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    protected function parse(string $file): array
    {
        $content = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $meta = [];
        if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $content, $match)) {
            foreach (explode("\n", $match[1]) as $line) {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $meta[trim($key)] = trim($value);
                }
            }
            $content = $match[2];
        }

        return [$meta, trim($content)];
    }

    /** Parse a front-matter date, falling back to the file's age. */
    public static function date(?string $value, string $file): Carbon
    {
        try {
            return $value ? Carbon::parse($value) : Carbon::createFromTimestamp((int) filemtime($file));
        } catch (\Throwable) {
            return Carbon::createFromTimestamp((int) filemtime($file));
        }
    }
}
