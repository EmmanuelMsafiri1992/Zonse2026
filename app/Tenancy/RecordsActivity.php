<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Model;

/**
 * Writes create / update / delete events to the activity log, tagged with the
 * workspace so the dashboard timeline can show them.
 *
 * Models may define activityLabel() ("Invoice INV-0004"), activityUrl() and a
 * $activityAttributes list that limits which changed columns count as an update.
 */
trait RecordsActivity
{
    protected static bool $activityPaused = false;

    public static function bootRecordsActivity(): void
    {
        static::created(fn (Model $model) => $model->recordActivity('created'));
        static::updated(fn (Model $model) => $model->recordActivity('updated'));
        static::deleted(fn (Model $model) => $model->recordActivity('deleted'));
    }

    /** @param  array<string, mixed>  $properties */
    public function recordActivity(string $event, ?string $description = null, array $properties = []): void
    {
        if (static::$activityPaused) {
            return;
        }

        if ($event === 'updated') {
            $changes = array_diff_key($this->getChanges(), array_flip(['updated_at', 'created_at', 'deleted_at']));
            if (isset($this->activityAttributes)) {
                $changes = array_intersect_key($changes, array_flip($this->activityAttributes));
            }
            if ($changes === []) {
                return;
            }
            $properties['changed'] = array_keys($changes);
        }

        activity()
            ->performedOn($this)
            ->event($event)
            ->withProperties(array_merge([
                'workspace_id' => $this->workspace_id,
                'label' => $this->activityLabel(),
                'url' => method_exists($this, 'activityUrl') && $this->exists ? $this->activityUrl() : null,
            ], $properties))
            ->log($description ?? $this->activityLabel().' '.$event);
    }

    public function activityLabel(): string
    {
        return class_basename($this).' #'.$this->getKey();
    }

    /** Pause activity logging while running a callback (bulk imports, seeders). */
    public static function withoutActivity(callable $callback): mixed
    {
        $previous = static::$activityPaused;
        static::$activityPaused = true;

        try {
            return $callback();
        } finally {
            static::$activityPaused = $previous;
        }
    }
}
