<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** App record steps ("payables.bills.approve") need longer subject keys than invoices and quotes did. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['approval_rules', 'approval_requests'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('subject', 100)->change();
            });
        }
    }

    public function down(): void
    {
        foreach (['approval_rules', 'approval_requests'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('subject', 40)->change();
            });
        }
    }
};
