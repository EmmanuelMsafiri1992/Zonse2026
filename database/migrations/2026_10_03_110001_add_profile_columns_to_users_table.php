<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->string('locale', 10)->default('en')->after('avatar_path');
            $table->string('timezone', 60)->nullable()->after('locale');
            $table->foreignId('current_workspace_id')->nullable()->after('timezone')
                ->constrained('workspaces')->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('current_workspace_id');
            $table->timestamp('last_seen_at')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_workspace_id');
            $table->dropColumn(['phone', 'avatar_path', 'locale', 'timezone', 'is_super_admin', 'last_seen_at']);
        });
    }
};
