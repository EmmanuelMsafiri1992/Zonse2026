<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An enabled app the plan does not cover is billed as an add-on. The price is
 * locked when it becomes an add-on so later catalogue price changes don't
 * surprise existing customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_module', function (Blueprint $table) {
            $table->boolean('is_addon')->default(false)->after('module_id');
            $table->decimal('addon_monthly', 10, 2)->nullable()->after('is_addon');
            $table->decimal('addon_yearly', 10, 2)->nullable()->after('addon_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_module', function (Blueprint $table) {
            $table->dropColumn(['is_addon', 'addon_monthly', 'addon_yearly']);
        });
    }
};
