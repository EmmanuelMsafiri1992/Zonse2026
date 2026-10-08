<?php

namespace App\Models;

use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings. workspace_id NULL = platform-wide setting.
 */
class Setting extends Model
{
    protected $fillable = ['workspace_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public static function get(string $key, mixed $default = null, ?int $workspaceId = null): mixed
    {
        $workspaceId ??= app(WorkspaceContext::class)->id();
        $row = static::where('workspace_id', $workspaceId)->where('key', $key)->first();

        return $row ? $row->value['v'] ?? $default : $default;
    }

    public static function set(string $key, mixed $value, ?int $workspaceId = null): void
    {
        $workspaceId ??= app(WorkspaceContext::class)->id();
        static::updateOrCreate(
            ['workspace_id' => $workspaceId, 'key' => $key],
            ['value' => ['v' => $value]]
        );
    }

    public static function platform(string $key, mixed $default = null): mixed
    {
        $row = static::whereNull('workspace_id')->where('key', $key)->first();

        return $row ? $row->value['v'] ?? $default : $default;
    }
}
