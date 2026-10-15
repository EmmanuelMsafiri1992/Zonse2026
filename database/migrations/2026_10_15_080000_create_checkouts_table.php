<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per attempt to pay Zonseob itself: a plan period or a pack of AI tokens. The amount
 * is quoted in USD and charged in the gateway's currency; the purchase is applied exactly once,
 * when the gateway (or a platform admin, for bank transfers) confirms the money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 20); // subscription|tokens
            $table->json('subject'); // what was bought: plan + cycle, or pack + tokens
            $table->string('gateway', 20); // paynow|stripe|manual
            $table->decimal('base_amount', 14, 2);
            $table->string('base_currency', 3)->default('USD');
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->string('status', 20)->default('pending'); // pending|paid|failed|cancelled
            $table->string('gateway_reference', 191)->nullable();
            $table->string('poll_url', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index(['user_id', 'purpose']);
            $table->index(['gateway', 'gateway_reference']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checkouts');
    }
};
