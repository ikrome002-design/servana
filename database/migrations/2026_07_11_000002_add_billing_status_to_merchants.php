<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the merchant BILLING-access authority to `merchants` (Plan §13, §21, §22;
 * Phase 20B). Distinct from operational `merchants.status` (pending_setup/active/
 * suspended/deactivated): `merchants.billing_status` is the SOLE field the billing-status
 * gate reads (§22). It was deferred from Phase 17 (which documented the handoff) and lands
 * here with the subscription lifecycle.
 *
 * `billing_status` — five canonical values ({@see MerchantBillingStatus}); NOT NULL default
 * 'trialing' (Plan §13). Existing merchants receive the default (safe, Plan-defined backfill).
 * `billing_status_reason` — nullable text (Plan §13; mirrors merchants.suspension_reason
 * free-text convention). It records the structured terminal cause for the Gate B2 projection
 * (`subscription_cancelled`/`subscription_expired`) and other billing reasons; canonical
 * codes are represented in PHP (Increment 3) rather than by a rigid DB CHECK, since later
 * phases add further billing reason codes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table): void {
            $table->string('billing_status', 20)->default('trialing')->after('status');
            $table->text('billing_status_reason')->nullable()->after('billing_status');
        });

        // Literal values (parity with MerchantBillingStatus guarded by Phase20BEnumParityTest).
        DB::statement(
            "ALTER TABLE merchants
             ADD CONSTRAINT merchants_billing_status_check
             CHECK (billing_status IN ('trialing','read_only_grace','active','overdue','suspended_billing'))"
        );

        // Gate-lookup index (Plan §13 "Index (billing_status)").
        DB::statement('CREATE INDEX merchants_billing_status_index ON merchants (billing_status)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS merchants_billing_status_index');
        DB::statement('ALTER TABLE merchants DROP CONSTRAINT IF EXISTS merchants_billing_status_check');

        Schema::table('merchants', function (Blueprint $table): void {
            $table->dropColumn(['billing_status', 'billing_status_reason']);
        });
    }
};
