<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App records carry extra fields too. Their kind is named "app.entity" (e.g. "clinic.patient"),
     * so the entity column needs room for the longer names.
     */
    public function up(): void
    {
        Schema::table('custom_fields', fn (Blueprint $table) => $table->string('entity', 100)->change());

        if (! Schema::hasColumn('records', 'custom_fields')) {
            Schema::table('records', fn (Blueprint $table) => $table->json('custom_fields')->nullable());
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('records', 'custom_fields')) {
            Schema::table('records', fn (Blueprint $table) => $table->dropColumn('custom_fields'));
        }
    }
};
