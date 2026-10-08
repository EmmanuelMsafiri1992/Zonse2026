<?php

namespace App\Tenancy;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply to every tenant-owned model.
 *
 *  - Adds a global scope that limits queries to the active workspace.
 *  - Fills workspace_id automatically on create.
 *  - Exposes ->workspace() and ::forWorkspace($id) / ::allWorkspaces().
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope('workspace', function (Builder $builder) {
            $context = app(WorkspaceContext::class);
            if ($context->has()) {
                $builder->where($builder->qualifyColumn('workspace_id'), $context->id());
            }
        });

        static::creating(function (Model $model) {
            if (empty($model->workspace_id)) {
                $model->workspace_id = app(WorkspaceContext::class)->id();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function scopeForWorkspace(Builder $query, int|Workspace $workspace): Builder
    {
        $id = $workspace instanceof Workspace ? $workspace->id : $workspace;

        return $query->withoutGlobalScope('workspace')->where($query->qualifyColumn('workspace_id'), $id);
    }

    public function scopeAllWorkspaces(Builder $query): Builder
    {
        return $query->withoutGlobalScope('workspace');
    }
}
