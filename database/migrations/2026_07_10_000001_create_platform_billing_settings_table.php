<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_billing_settings — platform-scoped, effective-dated billing configuration
 * (Plan §13.9, §47, §50; Phase 20A). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/platform-billing-settings.md.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (registered in TenantOwnership::EXEMPT).
 * An append-only, effective-dated version series — exactly one CURRENT version at any
 * instant (greatest effective_from <= now()); an update inserts a NEW version and never
 * mutates a prior one. Financial primitives (billing_mode, trial, grace, currency) are
 * first-class columns; only documented keys live in `settings`. billing_mode mirrors the
 * BillingMode enum (BillingEnumParityTest). Money/day counts are non-negative integers;
 * currency is uppercase ISO. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_billing_settings', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('billing_mode', 64);
            $table->integer('default_trial_days');
            $table->integer('grace_days');
            $table->char('currency', 3);
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('effective_from');
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            // Exactly one version per effective instant (single active row via effective dating).
            $table->unique('effective_from', 'platform_billing_settings_effective_from_unique');
            // Current-version lookup (greatest effective_from <= now()).
            $table->index('effective_from', 'platform_billing_settings_effective_from_index');
        });

        // billing_mode ∈ canonical BillingMode (parity-guarded).
        DB::statement(
            "ALTER TABLE platform_billing_settings ADD CONSTRAINT platform_billing_settings_billing_mode_check
             CHECK (billing_mode IN ('fixed_amount','percentage_on_merchant_client_invoice','fixed_amount_plus_percentage_on_merchant_client_invoice'))"
        );
        DB::statement(
            'ALTER TABLE platform_billing_settings ADD CONSTRAINT platform_billing_settings_trial_days_check
             CHECK (default_trial_days >= 0)'
        );
        DB::statement(
            'ALTER TABLE platform_billing_settings ADD CONSTRAINT platform_billing_settings_grace_days_check
             CHECK (grace_days >= 0)'
        );
        DB::statement(
            'ALTER TABLE platform_billing_settings ADD CONSTRAINT platform_billing_settings_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        // settings is a JSON object (no undocumented scalar/array financial rules).
        DB::statement(
            "ALTER TABLE platform_billing_settings ADD CONSTRAINT platform_billing_settings_settings_object_check
             CHECK (jsonb_typeof(settings) = 'object')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_billing_settings');
    }
};
