<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per attempt to pay an invoice through a gateway (Paynow, Stripe). The Payment
     * is only written once the gateway confirms the money, and never twice for one attempt.
     */
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('gateway', 20);
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->string('status', 20)->default('pending');
            $table->string('gateway_reference', 191)->nullable();
            $table->string('poll_url', 500)->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'invoice_id']);
            $table->index(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};
