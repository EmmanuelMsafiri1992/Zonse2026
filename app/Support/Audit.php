<?php

namespace App\Support;

use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

/**
 * Writes workspace-level events (sign-ins, settings, team and data changes) to
 * the activity log, next to the record changes logged by RecordsActivity.
 */
class Audit
{
    /** Audit log categories, stored as the activity's log name. */
    public const CATEGORIES = [
        'default' => 'Records',
        'access' => 'Sign-in & security',
        'settings' => 'Settings & team',
        'data' => 'Data export',
    ];

    /** @param  array<string, mixed>  $properties */
    public static function log(string $category, string $event, string $description, ?Model $subject = null, array $properties = [], ?Workspace $workspace = null): ?Activity
    {
        $workspace ??= app(WorkspaceContext::class)->get();
        if (! $workspace) {
            return null;
        }

        $logger = activity($category)->event($event)
            ->withProperties(array_merge(['workspace_id' => $workspace->id, 'ip' => request()?->ip()], $properties));

        if ($subject) {
            $logger->performedOn($subject);
        }

        return $logger->log($description);
    }

    public static function categoryLabel(?string $category): string
    {
        return self::CATEGORIES[$category ?? 'default'] ?? ucfirst((string) $category);
    }
}
