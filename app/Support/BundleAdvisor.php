<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\Profession;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Onboarding's "What do you do?" helper: turns a plain description of the
 * business into ranked starter bundles (professions), and picks the plan that
 * costs least for the apps chosen, counting paid add-ons (see ModuleBilling).
 */
class BundleAdvisor
{
    /** Words in profession names too generic to count as a match on their own. */
    protected const GENERIC_WORDS = ['company', 'owner', 'business', 'department', 'ride', 'and', 'online'];

    public function __construct(protected ModuleBilling $billing) {}

    /**
     * Professions whose keywords or name appear in the description, best first.
     *
     * @return Collection<int, Profession>
     */
    public function match(string $description, int $limit = 3): Collection
    {
        $text = ' '.Str::of($description)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->squish().' ';
        if (trim($text) === '') {
            return collect();
        }

        return Profession::orderBy('sort_order')->get()
            ->map(function (Profession $profession) use ($text) {
                $score = 0;
                foreach ($profession->keywords ?? [] as $keyword) {
                    $score += $this->mentions($text, $keyword) ? 3 : 0;
                }
                foreach (preg_split('/[^a-z]+/', strtolower($profession->name)) as $word) {
                    $generic = strlen($word) < 3 || in_array($word, self::GENERIC_WORDS, true);
                    $score += ! $generic && $this->mentions($text, $word) ? 2 : 0;
                }
                $profession->setAttribute('match_score', $score);

                return $profession;
            })
            ->filter(fn (Profession $p) => $p->match_score > 0)
            ->sortByDesc('match_score')
            ->take($limit)
            ->values();
    }

    /**
     * The plan with the lowest total (plan + add-ons) for the workspace's enabled apps.
     *
     * @param  Collection<int, Plan>  $plans
     * @return array{plan: Plan|null, totals: array<int, float>}
     */
    public function recommendPlan(Workspace $workspace, Collection $plans, string $cycle = 'monthly'): array
    {
        $totals = $plans->mapWithKeys(fn (Plan $plan) => [
            $plan->id => Money::round($plan->priceFor($cycle) + array_sum(array_column($this->billing->quoteSwitch($workspace, $plan), $cycle))),
        ])->all();

        // Cheapest wins; on a tie the earlier (smaller) plan in the list is kept.
        $best = $plans->reduce(fn (?Plan $carry, Plan $plan) => $carry === null || $totals[$plan->id] < $totals[$carry->id] ? $plan : $carry);

        return ['plan' => $best, 'totals' => $totals];
    }

    /** Whole-word match, allowing plurals ("salons", "pharmacies"). */
    protected function mentions(string $text, string $phrase): bool
    {
        $phrase = trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($phrase)));
        if ($phrase === '') {
            return false;
        }

        $forms = [preg_quote($phrase, '/').'(s|es)?'];
        if (str_ends_with($phrase, 'y')) {
            $forms[] = preg_quote(substr($phrase, 0, -1), '/').'ies';
        }

        return preg_match('/ ('.implode('|', $forms).') /', $text) === 1;
    }
}
