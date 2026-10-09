<?php

namespace App\Blueprints\Logic;

use App\Blueprints\AppLogic;
use App\Blueprints\Entity;
use App\Models\Record;
use Illuminate\Support\Carbon;

/**
 * Knowledge base & wiki: category names and article titles within a category are unique, a
 * public article cannot sit in a hidden category, and a published article must say something.
 * Articles count their words and reading time, and ones untouched for six months are flagged
 * as stale so they get checked.
 */
class WikiLogic extends AppLogic
{
    public const STALE_DAYS = 180;

    public const MIN_PUBLISHED_WORDS = 20;

    public const WORDS_PER_MINUTE = 200;

    public function validate(Entity $entity, array $payload, ?Record $existing): array
    {
        $data = $payload['data'];
        $errors = [];

        if ($entity->key === 'categories') {
            if ($this->records('categories')->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->exists()) {
                $errors['title'] = 'There is already a category called '.$payload['title'].'.';
            }
            if ($existing && $payload['status'] === 'hidden' && $this->linked('articles', 'category', $existing)->where('status', 'published')->where('data->visibility', 'public')->exists()) {
                $errors['status'] = 'Make the public articles in this category internal before hiding it.';
            }

            return $errors;
        }

        $category = ! empty($data['category']) ? $this->records('categories')->find($data['category']) : null;
        if ($category && ($data['visibility'] ?? null) === 'public' && $category->status === 'hidden') {
            $errors['data.visibility'] = 'The category '.$category->title.' is hidden, so its articles can only be internal.';
        }
        if ($this->records('articles')->where('title', $payload['title'])->when($existing, fn ($query) => $query->whereKeyNot($existing->id))->get()
            ->contains(fn (Record $other) => (int) $other->value('category') === (int) ($data['category'] ?? 0))) {
            $errors['title'] = 'An article called '.$payload['title'].' is already in this category.';
        }
        if ($payload['status'] === 'published' && $this->words((string) ($data['body'] ?? '')) < self::MIN_PUBLISHED_WORDS) {
            $errors['data.body'] = 'Write at least '.self::MIN_PUBLISHED_WORDS.' words before publishing.';
        }

        return $errors;
    }

    public function words(string $text): int
    {
        return str_word_count(strip_tags($text));
    }

    public function saving(Record $record): void
    {
        if ($record->entity === 'categories') {
            $articles = $record->exists ? $this->linked('articles', 'category', $record)->get() : collect();
            $this->put($record, ['_articles' => $articles->count(), '_published' => $articles->where('status', 'published')->count()]);

            return;
        }

        if ($record->isDirty('data') || ! $record->exists) {
            $record->occurs_on = today();
        }
        $words = $this->words((string) $record->value('body'));
        $this->put($record, [
            '_words' => $words,
            '_read_minutes' => max(1, (int) ceil($words / self::WORDS_PER_MINUTE)),
            '_published_on' => $record->status === 'published' ? ($record->value('_published_on') ?? today()->toDateString()) : null,
        ]);
    }

    public function saved(Record $record): void
    {
        if ($record->entity === 'articles') {
            $this->recalculate($this->parent($record, 'category'));
            $this->recalculate($this->previousParent($record, 'category'));
        }
    }

    public function deleted(Record $record): void
    {
        if ($record->entity === 'articles') {
            $this->recalculate($this->parent($record, 'category'));
        }
    }

    public function isStale(Record $article): bool
    {
        return $article->status === 'published' && $article->occurs_on && $article->occurs_on->lt(today()->subDays(self::STALE_DAYS));
    }

    public function recordCards(Record $record): array
    {
        if ($record->entity === 'categories') {
            return [['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Articles', 'icon' => 'book-open-text', 'empty' => 'No articles yet.',
                'rows' => $this->linked('articles', 'category', $record)->orderBy('title')->get()->map(fn (Record $article) => [
                    'label' => $article->title, 'sub' => ucfirst((string) $article->value('visibility')), 'value' => ucfirst($article->status), 'href' => $article->url(),
                ])->values()->all(),
            ]]];
        }

        $cards = [['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Article', 'icon' => 'book-open-text', 'stats' => [
            ['label' => 'Words', 'value' => (string) (int) $record->value('_words')],
            ['label' => 'Reading time', 'value' => (int) $record->value('_read_minutes').' min'],
            ['label' => 'Last updated', 'value' => $record->occurs_on?->format('d M Y') ?? '—'],
        ]]]];
        if ($this->isStale($record)) {
            $cards[] = ['view' => 'apps.logic.alert-card', 'data' => ['tone' => 'warning', 'icon' => 'history', 'title' => 'Might be out of date',
                'body' => 'Nobody has updated this article in over six months. Check it is still right.']];
        }

        return $cards;
    }

    public function homeCards(): array
    {
        $articles = $this->records('articles')->get();
        $published = $articles->where('status', 'published');

        return [
            ['view' => 'apps.logic.stats-card', 'data' => ['title' => 'Knowledge base', 'icon' => 'book', 'stats' => [
                ['label' => 'Published', 'value' => (string) $published->count()],
                ['label' => 'Public', 'value' => (string) $published->where('data.visibility', 'public')->count()],
                ['label' => 'Drafts', 'value' => (string) $articles->where('status', 'draft')->count()],
                ['label' => 'Stale', 'value' => (string) $published->filter(fn (Record $article) => $this->isStale($article))->count(), 'tone' => 'warning'],
            ]]],
            ['view' => 'apps.logic.list-card', 'data' => [
                'title' => 'Recently updated', 'icon' => 'history', 'empty' => 'No articles yet.',
                'rows' => $articles->sortByDesc(fn (Record $article) => $article->occurs_on?->toDateString().sprintf('%010d', $article->id))->take(8)->map(fn (Record $article) => [
                    'label' => $article->title, 'sub' => ucfirst($article->status), 'value' => $article->occurs_on?->format('d M') ?? '', 'href' => $article->url(),
                ])->values()->all(),
            ]],
        ];
    }

    public function reports(Carbon $from, Carbon $to): array
    {
        $articles = $this->records('articles')->get();
        $categories = $this->records('categories')->pluck('title', 'id');

        $byCategory = $articles->groupBy(fn (Record $article) => $categories[$article->value('category')] ?? 'No category')->sortKeys()->map(fn ($group, $category) => [
            $category, $group->where('status', 'published')->count(), $group->where('status', 'draft')->count(), $group->where('data.visibility', 'public')->where('status', 'published')->count(),
            $group->sum(fn (Record $article) => (int) $article->value('_words')),
        ])->values()->all();

        $stale = $articles->filter(fn (Record $article) => $this->isStale($article))->sortBy('occurs_on')->map(fn (Record $article) => [
            $article->title, $categories[$article->value('category')] ?? '—', $article->occurs_on->format('d M Y'), $article->assignee?->name ?? '—',
        ])->values()->all();

        return [
            ['title' => 'Articles by category', 'columns' => ['Category', 'Published', 'Drafts', 'Public', 'Words'], 'rows' => $byCategory],
            ['title' => 'Stale articles', 'columns' => ['Article', 'Category', 'Last updated', 'Owner'], 'rows' => $stale],
        ];
    }
}
