<?php

namespace App\Social;

use App\Blueprints\BlueprintRegistry;
use App\Models\Module;
use App\Models\SocialPost;
use App\Models\Suite;
use Illuminate\Support\Collection;

/**
 * The order of the series: the introduction, then every suite in catalogue order, each followed
 * by its apps. Only apps that exist (installed code modules or blueprint apps) are included, so
 * the page never advertises something a visitor cannot switch on.
 */
class PostPlanner
{
    /** @var list<PostDraft>|null */
    protected ?array $sequence = null;

    public function __construct(protected PostWriter $writer, protected BlueprintRegistry $blueprints) {}

    /** @return list<PostDraft> */
    public function sequence(): array
    {
        if ($this->sequence !== null) {
            return $this->sequence;
        }

        $suites = Suite::query()->with('modules')->orderBy('sort_order')->get()
            ->map(fn (Suite $suite) => [$suite, $suite->modules->filter(fn (Module $m) => $this->isPostable($m))->values()])
            ->filter(fn (array $pair) => $pair[1]->isNotEmpty())
            ->values();

        $appCount = (int) $suites->sum(fn (array $pair) => $pair[1]->count());
        $sequence = $this->writer->intros($appCount, $suites->count());

        foreach ($suites as [$suite, $modules]) {
            $sequence[] = $this->writer->suite($suite, $modules);
            foreach ($modules as $module) {
                $sequence[] = $this->writer->app($module, $this->blueprints->get($module->key), $suite);
            }
        }

        return $this->sequence = $sequence;
    }

    /** The first post in the series not yet published on the channel. */
    public function next(string $channel = 'facebook'): ?PostDraft
    {
        return $this->remaining($channel)->first();
    }

    /** @return Collection<int, PostDraft> */
    public function remaining(string $channel = 'facebook'): Collection
    {
        $done = SocialPost::query()->where('channel', $channel)->where('status', SocialPost::STATUS_POSTED)
            ->get(['kind', 'key'])->map(fn (SocialPost $p) => $p->kind.':'.$p->key)->flip();

        return collect($this->sequence())->reject(fn (PostDraft $d) => $done->has($d->id()))->values();
    }

    public function find(string $kind, string $key): ?PostDraft
    {
        return collect($this->sequence())->first(fn (PostDraft $d) => $d->kind === $kind && $d->key === $key);
    }

    protected function isPostable(Module $module): bool
    {
        if ($module->key === 'dashboard') {
            return false;
        }

        return $module->is_installed || $this->blueprints->has($module->key);
    }
}
