<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('help_tours')->nullable()->after('notification_preferences');
            $table->timestamp('release_notes_seen_at')->nullable()->after('help_tours');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['help_tours', 'release_notes_seen_at']);
        });
    }
};
