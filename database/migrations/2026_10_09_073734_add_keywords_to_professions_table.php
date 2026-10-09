<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Words people use to describe the business ("hair", "braids", "tuckshop"),
 * so onboarding can match "What do you do?" free text to a starter bundle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professions', function (Blueprint $table) {
            $table->json('keywords')->nullable()->after('module_keys');
        });
    }

    public function down(): void
    {
        Schema::table('professions', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
