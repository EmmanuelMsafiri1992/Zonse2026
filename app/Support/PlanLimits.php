<?php

namespace App\Support;

use App\Models\Workspace;

/**
 * The seat and branch caps a workspace's plan carries. A missing limit, or -1, means unlimited;
 * a workspace with no plan yet is never capped.
 */
class PlanLimits
{
    public function __construct(protected Workspace $workspace) {}

    public static function for(Workspace $workspace): self
    {
        return new self($workspace);
    }

    public function planName(): string
    {
        return $this->workspace->plan()?->name ?? 'current';
    }

    /** How many people may belong to the workspace; null means unlimited. */
    public function seatsAllowed(): ?int
    {
        return $this->allowance('users');
    }

    /** Members plus invitations still waiting to be accepted. */
    public function seatsUsed(): int
    {
        return $this->membersCount() + $this->workspace->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->count();
    }

    public function canInvite(): bool
    {
        $allowed = $this->seatsAllowed();

        return $allowed === null || $this->seatsUsed() < $allowed;
    }

    /** Whether one more person may join right now, ignoring other pending invitations. */
    public function canJoin(): bool
    {
        $allowed = $this->seatsAllowed();

        return $allowed === null || $this->membersCount() < $allowed;
    }

    public function seatsMessage(): string
    {
        $allowed = (int) $this->seatsAllowed();

        return sprintf('Your %s plan includes %d team %s. Upgrade your plan to add more people.', $this->planName(), $allowed, $allowed === 1 ? 'member' : 'members');
    }

    /** How many branches the workspace may have; null means unlimited. */
    public function branchesAllowed(): ?int
    {
        return $this->allowance('branches');
    }

    public function branchesUsed(): int
    {
        return $this->workspace->branches()->count();
    }

    public function canAddBranch(): bool
    {
        $allowed = $this->branchesAllowed();

        return $allowed === null || $this->branchesUsed() < $allowed;
    }

    public function branchesMessage(): string
    {
        $allowed = (int) $this->branchesAllowed();

        return sprintf('Your %s plan includes %d %s. Upgrade your plan to add more branches.', $this->planName(), $allowed, $allowed === 1 ? 'branch' : 'branches');
    }

    protected function membersCount(): int
    {
        return $this->workspace->members()->count();
    }

    protected function allowance(string $key): ?int
    {
        $limit = $this->workspace->plan()?->limit($key);

        return $limit === null || (int) $limit < 0 ? null : (int) $limit;
    }
}
