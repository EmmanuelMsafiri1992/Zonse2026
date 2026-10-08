<?php

namespace App\Support;

use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Facades\DB;

/**
 * Per-workspace document numbering. Each key (invoice, quote, ticket, …) has
 * its own counter so numbers never skip or collide, even under concurrency.
 */
class Sequence
{
    /** Reserve and return the next number for a key, e.g. "INV-0001". */
    public static function next(string $key, string $defaultPrefix = '', ?int $workspaceId = null): string
    {
        $workspaceId ??= app(WorkspaceContext::class)->getOrFail()->id;

        return DB::transaction(function () use ($key, $defaultPrefix, $workspaceId) {
            $row = DB::table('sequences')->where('workspace_id', $workspaceId)->where('key', $key)->lockForUpdate()->first();

            if (! $row) {
                DB::table('sequences')->insert([
                    'workspace_id' => $workspaceId, 'key' => $key, 'prefix' => $defaultPrefix,
                    'next_number' => 1, 'padding' => 4, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $row = DB::table('sequences')->where('workspace_id', $workspaceId)->where('key', $key)->lockForUpdate()->first();
            }

            DB::table('sequences')->where('id', $row->id)->update(['next_number' => $row->next_number + 1, 'updated_at' => now()]);

            return $row->prefix.str_pad((string) $row->next_number, (int) $row->padding, '0', STR_PAD_LEFT);
        });
    }

    /** Change the prefix or the next number for a key (used by module settings pages). */
    public static function configure(string $key, string $prefix, ?int $nextNumber = null, ?int $workspaceId = null): void
    {
        $workspaceId ??= app(WorkspaceContext::class)->getOrFail()->id;
        $values = ['prefix' => $prefix, 'updated_at' => now()];
        if ($nextNumber !== null && $nextNumber > 0) {
            $values['next_number'] = $nextNumber;
        }

        DB::table('sequences')->updateOrInsert(
            ['workspace_id' => $workspaceId, 'key' => $key],
            $values + ['padding' => 4, 'created_at' => now()],
        );
    }

    /** @return array{prefix: string, next_number: int} */
    public static function current(string $key, string $defaultPrefix = '', ?int $workspaceId = null): array
    {
        $workspaceId ??= app(WorkspaceContext::class)->getOrFail()->id;
        $row = DB::table('sequences')->where('workspace_id', $workspaceId)->where('key', $key)->first();

        return ['prefix' => $row->prefix ?? $defaultPrefix, 'next_number' => (int) ($row->next_number ?? 1)];
    }
}
