<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_recording_groups — durable merchant-client payment recording group
 * (Plan §13.8, §13.15 Correction 7, §41; §80 Phase 18A). Branch-owned. The ULID is
 * the public identifier + route key; the internal bigint id is never exposed.
 *
 * One group = one recording workflow: a single-method payment is a group of one
 * concrete component; a split/multi-method payment is one group with multiple
 * concrete component payment_records. total_amount_minor = Σ(component amounts) and
 * currency is single (both enforced transactionally under the invoice row lock and
 * proven with concurrency tests — cross-row sums are not ordinary CHECKs). The group
 * is the unit of Finance validation (Phase 18B); recording NEVER changes an invoice
 * balance or status.
 *
 * Status carries the full forward-compatible set for schema fidelity; Phase 18A
 * production actions only reach the recording-owned states ('recorded',
 * 'pending_validation'). Validation/rejection/correction/reversal are Phase 18B and
 * are unreachable from any Phase 18A route. Full lifecycle:
 * docs/architecture/state-machines/payment-recording-group.md. Money is integer
 * minor units (Money value object).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_recording_groups', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('maker_user_id')->constrained('users')->restrictOnDelete();
            $table->bigInteger('total_amount_minor');
            $table->char('currency', 3)->default('KES');
            // Links the group to the R4 idempotency evidence (financial_mutation).
            $table->foreignId('idempotency_key_id')->nullable()->constrained('idempotency_keys')->nullOnDelete();
            $table->string('status', 24)->default('recorded');
            $table->timestampTz('recorded_at')->nullable();
            $table->timestampTz('submitted_for_validation_at')->nullable();
            // Phase 18B — never written in Phase 18A.
            $table->timestampTz('validated_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['invoice_id', 'status']);
            // Composite-FK target for component payment_records.
            $table->unique(['id', 'merchant_id'], 'payment_recording_groups_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_status_check
             CHECK (status IN ('draft','recorded','pending_validation','validated','rejected','correction_required','reversed'))"
        );
        DB::statement(
            'ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_total_check
             CHECK (total_amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        // recorded_at set for any non-draft group.
        DB::statement(
            "ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_recorded_at_check
             CHECK (status = 'draft' OR recorded_at IS NOT NULL)"
        );
        // submitted_for_validation_at set iff the group has left the recording hold.
        DB::statement(
            "ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_submitted_check
             CHECK ((status IN ('pending_validation','validated','rejected','correction_required','reversed')) = (submitted_for_validation_at IS NOT NULL))"
        );
        // Phase-18B timestamps only ever accompany their states (never set in 18A).
        DB::statement(
            "ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_validated_at_check
             CHECK (validated_at IS NULL OR status IN ('validated','reversed'))"
        );
        DB::statement(
            "ALTER TABLE payment_recording_groups ADD CONSTRAINT payment_recording_groups_rejected_at_check
             CHECK (rejected_at IS NULL OR status = 'rejected')"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE payment_recording_groups
             ADD CONSTRAINT payment_recording_groups_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_recording_groups
             ADD CONSTRAINT payment_recording_groups_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_recording_groups');
    }
};
