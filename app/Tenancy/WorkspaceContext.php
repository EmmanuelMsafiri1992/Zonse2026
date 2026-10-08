<?php

namespace App\Tenancy;

use App\Models\Branch;
use App\Models\Workspace;

/**
 * Holds the workspace (tenant) the current request is operating in.
 * Registered as a singleton; set by the SetWorkspaceContext middleware,
 * console commands or jobs that need to run inside a workspace.
 */
class WorkspaceContext
{
    protected ?Workspace $workspace = null;

    protected ?Branch $branch = null;

    public function set(?Workspace $workspace, ?Branch $branch = null): static
    {
        $this->workspace = $workspace;
        $this->branch = $branch;

        return $this;
    }

    public function clear(): void
    {
        $this->set(null);
    }

    public function has(): bool
    {
        return $this->workspace !== null;
    }

    public function get(): ?Workspace
    {
        return $this->workspace;
    }

    public function getOrFail(): Workspace
    {
        if (! $this->workspace) {
            throw new \RuntimeException('No workspace is active for this request.');
        }

        return $this->workspace;
    }

    public function id(): ?int
    {
        return $this->workspace?->id;
    }

    public function branch(): ?Branch
    {
        return $this->branch;
    }

    public function branchId(): ?int
    {
        return $this->branch?->id;
    }

    public function hasModule(string $key): bool
    {
        if (! $this->workspace) {
            return false;
        }

        return $this->workspace->hasModule($key);
    }

    /**
     * Run a callback inside a workspace, restoring the previous context after.
     */
    public function run(Workspace $workspace, callable $callback): mixed
    {
        $previous = [$this->workspace, $this->branch];
        $this->set($workspace);

        try {
            return $callback($workspace);
        } finally {
            $this->set($previous[0], $previous[1]);
        }
    }
}
