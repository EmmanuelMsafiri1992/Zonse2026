<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give the activity log a real workspace column so the audit log can filter
     * and page through a workspace's history without scanning JSON.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('workspace_id')->nullable()->after('id');
            $table->index(['workspace_id', 'created_at']);
        });

        DB::table('activity_log')->whereNull('workspace_id')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $workspaceId = json_decode((string) $row->properties, true)['workspace_id'] ?? null;
                if ($workspaceId) {
                    DB::table('activity_log')->where('id', $row->id)->update(['workspace_id' => $workspaceId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'created_at']);
            $table->dropColumn('workspace_id');
        });
    }
};
