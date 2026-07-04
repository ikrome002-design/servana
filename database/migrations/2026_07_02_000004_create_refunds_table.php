<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * refunds — EXTERNAL refund records (Servana never moves funds) (Plan §44; Gate D/E;
 * Phase 18B). Branch-owned. The ULID is the public identifier.
 *
 * A refund is allocated to a concrete validated component (payment_record_id — Gate D,
 * mandatory). A multi-component refund is one atomic workflow of several rows sharing
 * refund_group_ulid. amount_minor is positive and (enforced transactionally) cannot
 * exceed the component's remaining refundable validated amount. Maker/checker
 * separation (approved_by != requested_by), fresh step-up on approve + finalize,
 * period-lock enforced. Finalization is additive/non-destructive: originals preserved,
 * invoice.validated_paid_minor reduced, per-component reversal handoff written. The
 * external reference is encrypted at rest and masked everywhere. Money is integer
 * minor units. See docs/architecture/state-machines/refund.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('payment_record_id')->constrained('payment_records')->restrictOnDelete();
            $table->char('refund_group_ulid', 26);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');
            $table->string('method', 24);
            $table->text('external_reference_encrypted')->nullable();
            $table->string('reason', 500);
            $table->string('status', 16)->default('requested');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('finalized_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['invoice_id']);
            $table->index(['payment_record_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['refund_group_ulid']);
            $table->unique(['id', 'merchant_id'], 'refunds_id_merchant_id_unique');
        });

        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT refunds_amount_check
             CHECK (amount_minor > 0)'
        );
        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT refunds_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_method_check
             CHECK (method IN ('cash','mpesa_offline','bank_transfer','card_terminal','voucher','other'))"
        );
        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_status_check
             CHECK (status IN ('requested','approved','finalized','rejected'))"
        );
        // Actor coherence with status.
        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_approved_by_check
             CHECK ((status IN ('approved','finalized')) = (approved_by IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_finalized_by_check
             CHECK ((status = 'finalized') = (finalized_by IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE refunds ADD CONSTRAINT refunds_rejected_by_check
             CHECK ((status = 'rejected') = (rejected_by IS NOT NULL))"
        );
        // Maker/checker: an approver can never be the requester.
        DB::statement(
            'ALTER TABLE refunds ADD CONSTRAINT refunds_maker_checker_check
             CHECK (approved_by IS NULL OR approved_by <> requested_by)'
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE refunds
             ADD CONSTRAINT refunds_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE refunds
             ADD CONSTRAINT refunds_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE refunds
             ADD CONSTRAINT refunds_payment_record_merchant_foreign
             FOREIGN KEY (payment_record_id, merchant_id)
             REFERENCES payment_records (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
