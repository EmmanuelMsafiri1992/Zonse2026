<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core platform tables: workspaces (tenants), membership, branches,
 * the module catalogue, professions, plans and subscriptions.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Catalogue: suites & modules -------------------------------
        Schema::create('suites', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('icon', 60)->default('layers');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('suite_id')->constrained()->cascadeOnDelete();
            $table->string('ref', 10)->unique();          // catalogue ref e.g. "7.1"
            $table->string('key', 60)->unique();          // code alias e.g. "clinic"
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('icon', 60)->default('box');
            $table->boolean('is_core')->default(false);   // always on for every workspace
            $table->boolean('is_installed')->default(false); // a code module exists for it
            $table->string('status', 20)->default('coming_soon'); // available|beta|coming_soon
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->json('depends')->nullable();          // array of module keys
            $table->json('tags')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('professions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->string('icon', 60)->default('briefcase');
            $table->string('group', 60)->nullable();      // Health, Finance, Retail...
            $table->json('module_keys')->nullable();      // recommended bundle
            $table->boolean('is_featured')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // ---- Plans -------------------------------------------------------
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->string('tagline', 160)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('price_monthly', 10, 2)->default(0);
            $table->decimal('price_yearly', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->json('limits')->nullable();           // users, branches, storage_mb, records
            $table->boolean('includes_all_modules')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('plan_module', function (Blueprint $table) {
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->primary(['plan_id', 'module_id']);
        });

        // ---- Workspaces (tenants) ---------------------------------------
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 160);
            $table->string('slug', 80)->unique();
            $table->string('type', 20)->default('business'); // business|organisation|individual
            $table->foreignId('profession_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('email', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('website', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country_code', 2)->default('ZW');
            $table->string('currency_code', 3)->default('USD');
            $table->string('locale', 10)->default('en');
            $table->string('timezone', 60)->default('Africa/Harare');
            $table->string('logo_path')->nullable();
            $table->string('tax_number', 60)->nullable();
            $table->json('settings')->nullable();
            $table->unsignedTinyInteger('onboarding_step')->default(1); // 1 profile, 2 profession, 3 modules, 4 plan, 0 done
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 20)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['workspace_id', 'is_default']);
        });

        Schema::create('workspace_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('role', 40)->default('member');   // owner|admin|member (+ spatie roles for fine grain)
            $table->string('job_title', 120)->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 160);
            $table->string('role', 40)->default('member');
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['workspace_id', 'email']);
        });

        Schema::create('workspace_module', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('module_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'module_id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('trialing'); // trialing|active|past_due|cancelled|expired
            $table->string('billing_cycle', 10)->default('monthly'); // monthly|yearly
            $table->decimal('amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->string('gateway', 30)->default('manual');
            $table->string('gateway_ref', 120)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 120);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('workspace_module');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('plan_module');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('professions');
        Schema::dropIfExists('modules');
        Schema::dropIfExists('suites');
    }
};
