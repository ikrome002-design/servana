<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_reference_checks — durable duplicate-reference detection record (Plan
 * §13.8, §13.15 Correction 3, §41; §80 Phase 18A). Branch-owned. The ULID is the
 * public identifier + route key (the Finance override binds {paymentReferenceCheck}).
 *
 * Gate C — the database is the concurrency authority. A PARTIAL UNIQUE INDEX on
 * (merchant_id, method, reference_normalized) WHERE result='unique' AND
 * reference_normalized IS NOT NULL lets the first accepted reference reserve the one
 * 'unique' slot per (merchant, method); later components with the same normalized
 * reference cannot insert a second 'unique' row and are instead recorded as
 * 'duplicate_suspected' (outside the predicate → persists) with a safe
 * matched_payment_record_id, or 'override_approved' after a canonical Finance
 * override (reason + step-up). Every attempt is durable; silent unapproved reuse is
 * impossible; the original reference is never edited. cash produces NO check row.
 * reference_normalized is $hidden — no full/normalized reference reaches an
 * API/audit/log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reference_checks', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('payment_record_id')->constrained('payment_records')->restrictOnDelete();
            $table->string('method', 20);
            $table->string('reference_normalized', 191);
            $table->string('result', 24);
            $table->foreignId('matched_payment_record_id')->nullable()->constrained('payment_records')->restrictOnDelete();
            $table->timestampTz('checked_at');
            $table->foreignId('override_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('override_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index('payment_record_id');
            $table->index('matched_payment_record_id');
        });

        // Gate C — one 'unique' reservation per (merchant, method, normalized reference);
        // duplicate_suspected / override_approved rows fall OUTSIDE the predicate so every
        // attempt is durable and the reservation stays race-safe.
        DB::statement(
            "CREATE UNIQUE INDEX payment_reference_checks_unique_reservation
             ON payment_reference_checks (merchant_id, method, reference_normalized)
             WHERE result = 'unique' AND reference_normalized IS NOT NULL"
        );

        DB::statement(
            "ALTER TABLE payment_reference_checks ADD CONSTRAINT payment_reference_checks_method_check
             CHECK (method IN ('cash','mpesa_offline','bank_transfer','card_terminal','voucher','split_payment','other'))"
        );
        DB::statement(
            "ALTER TABLE payment_reference_checks ADD CONSTRAINT payment_reference_checks_result_check
             CHECK (result IN ('unique','duplicate_suspected','override_approved'))"
        );
        // A matched record is required for duplicate_suspected/override_approved and
        // forbidden for a clean unique reservation.
        DB::statement(
            "ALTER TABLE payment_reference_checks ADD CONSTRAINT payment_reference_checks_matched_coherence_check
             CHECK ((result = 'unique') = (matched_payment_record_id IS NULL))"
        );
        // Override actor + sanitized reason required iff (and only if) the result is an override.
        DB::statement(
            "ALTER TABLE payment_reference_checks ADD CONSTRAINT payment_reference_checks_override_coherence_check
             CHECK ((result = 'override_approved') = (override_by IS NOT NULL AND override_reason IS NOT NULL))"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE payment_reference_checks
             ADD CONSTRAINT payment_reference_checks_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_reference_checks
             ADD CONSTRAINT payment_reference_checks_record_merchant_foreign
             FOREIGN KEY (payment_record_id, merchant_id)
             REFERENCES payment_records (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_reference_checks
             ADD CONSTRAINT payment_reference_checks_matched_merchant_foreign
             FOREIGN KEY (matched_payment_record_id, merchant_id)
             REFERENCES payment_records (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reference_checks');
    }
};
