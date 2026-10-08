<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->decimal('rate', 5, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('service');
            $table->string('name', 160);
            $table->string('sku', 60)->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 20)->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('cost', 14, 2)->nullable();
            $table->foreignId('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'name']);
        });

        $document = function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->string('status', 20)->default('draft');
            $table->date('issue_date');
            $table->string('currency_code', 3);
            $table->string('reference', 120)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 14, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        };

        Schema::create('invoices', function (Blueprint $table) use ($document) {
            $document($table);
            $table->date('due_date');
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('balance', 14, 2)->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('quote_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'due_date']);
        });

        Schema::create('quotes', function (Blueprint $table) use ($document) {
            $document($table);
            $table->date('valid_until')->nullable();
            $table->foreignId('invoice_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'status']);
        });

        $lines = function (Blueprint $table, string $parent) {
            $table->id();
            $table->foreignId($parent.'_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit', 20)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        };

        Schema::create('invoice_lines', fn (Blueprint $table) => $lines($table, 'invoice'));
        Schema::create('quote_lines', fn (Blueprint $table) => $lines($table, 'quote'));

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->string('number', 30);
            $table->decimal('amount', 14, 2);
            $table->string('currency_code', 3);
            $table->date('paid_on');
            $table->string('method', 20)->default('cash');
            $table->string('reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'number']);
            $table->index(['workspace_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('quote_lines');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('items');
        Schema::dropIfExists('tax_rates');
    }
};
