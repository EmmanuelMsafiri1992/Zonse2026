<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices can now come from an app record (a clinic visit, a lease, a school fee), and
     * products can track how many are in stock (the point of sale counts them down).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('record_id')->nullable()->after('quote_id')->constrained('records')->nullOnDelete();
            $table->string('period', 20)->nullable()->after('record_id');
            $table->index(['record_id', 'period']);
        });

        Schema::table('items', function (Blueprint $table) {
            $table->decimal('stock_qty', 12, 3)->nullable()->after('cost');
            $table->decimal('reorder_level', 12, 3)->nullable()->after('stock_qty');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['stock_qty', 'reorder_level']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['record_id', 'period']);
            $table->dropConstrainedForeignId('record_id');
            $table->dropColumn('period');
        });
    }
};
