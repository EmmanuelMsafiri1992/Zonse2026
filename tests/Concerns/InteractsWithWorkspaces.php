<?php

namespace Tests\Concerns;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\ProfessionSeeder;

trait InteractsWithWorkspaces
{
    protected function seedCatalogue(): void
    {
        $this->seed([CatalogueSeeder::class, ProfessionSeeder::class, PlanSeeder::class]);
    }

    /**
     * Creates an onboarded workspace owned by a fresh user and makes it their current workspace.
     *
     * @return array{0: User, 1: Workspace}
     */
    protected function ownerWithWorkspace(string $planKey = 'business'): array
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_id' => $owner->id]);
        $workspace->members()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $workspace->branches()->create(['name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $owner->switchWorkspace($workspace);

        if ($plan = Plan::where('key', $planKey)->first()) {
            $workspace->subscriptions()->create([
                'plan_id' => $plan->id,
                'status' => $plan->isFree() ? 'active' : 'trialing',
                'billing_cycle' => 'monthly',
                'amount' => $plan->price_monthly,
                'currency' => 'USD',
                'gateway' => 'manual',
                'trial_ends_at' => $plan->isFree() ? null : now()->addDays($plan->trial_days),
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        }

        return [$owner->fresh(), $workspace->fresh()];
    }

    /** Adds a user to the workspace with the given role and makes it their current workspace. */
    protected function memberOf(Workspace $workspace, string $role = 'member'): User
    {
        $user = User::factory()->create();
        $workspace->members()->attach($user->id, ['role' => $role, 'joined_at' => now()]);
        $user->switchWorkspace($workspace);

        return $user->fresh();
    }
}
