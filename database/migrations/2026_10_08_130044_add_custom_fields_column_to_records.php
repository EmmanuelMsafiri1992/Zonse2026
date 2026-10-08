<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Records that can carry the workspace's own extra fields. */
    protected array $tables = ['contacts', 'tasks', 'tickets', 'appointments'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'custom_fields')) {
                Schema::table($name, fn (Blueprint $table) => $table->json('custom_fields')->nullable());
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (Schema::hasColumn($name, 'custom_fields')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('custom_fields'));
            }
        }
    }
};
