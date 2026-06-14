<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchants — tenant root (Plan §7.1, Scope §3.2/§5.1).
 *
 * Created by Merchant Administrator self-registration only. There is no
 * platform/Super-Admin path to create merchants (Scope §3.1). A merchant starts
 * `pending_setup`; CompleteFirstTimeSetup flips it to `active`.
 *
 * Status and service-fee-tier are enum-backed in PHP (MerchantStatus /
 * ServiceFeeTier) AND constrained in the database with CHECKs (CLAUDE.md
 * guardrail 7). `service_fee_tier` is nullable until first-time setup selects it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name', 160);
            // Public slug; lowercased + unique (citext deferred — no extension
            // enabled, normalization is enforced in the registration action).
            $table->string('slug', 180)->unique();
            $table->string('status', 20)->default('pending_setup');
            $table->string('service_fee_tier', 20)->nullable();
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            // Drives the §3.2 inactivity rule (last platform-fee payment). Wired
            // to the Billing Engine in Phase 20; nullable seam now.
            $table->timestamp('last_fee_payment_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('last_fee_payment_at');
        });

        DB::statement(
            "ALTER TABLE merchants ADD CONSTRAINT merchants_status_check
             CHECK (status IN ('pending_setup','active','suspended','deactivated'))"
        );
        DB::statement(
            "ALTER TABLE merchants ADD CONSTRAINT merchants_service_fee_tier_check
             CHECK (service_fee_tier IS NULL OR service_fee_tier IN ('customer_centric','split_tier','business_centric'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
