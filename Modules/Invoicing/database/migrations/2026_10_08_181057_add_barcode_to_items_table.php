<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products can carry the barcode printed on them (or one we make up), so a scanner at
     * the till finds the item. Uniqueness is checked on save, so deleted items free their code.
     */
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('barcode', 64)->nullable()->after('sku');
            $table->index(['workspace_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'barcode']);
            $table->dropColumn('barcode');
        });
    }
};
