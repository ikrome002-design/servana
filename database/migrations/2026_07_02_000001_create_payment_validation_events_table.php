<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_validation_events — immutable, GROUP-LEVEL Finance validation decision
 * (Plan §13.15, §42, §80; ADR-0007; Gate A/B). Branch-owned. The ULID is the public
 * identifier; the internal bigint id is never exposed.
 *
 * One event per whole-group decision (validated | rejected | correction_required).
 * Parent is payment_recording_group_id (Gate A — NOT per component); components stay
 * traceable via payment_records.payment_recording_group_id. Append-only: there is no
 * UPDATE/DELETE route. A validated decision carries validated_amount_minor equal to
 * the group total; non-validated decisions carry null and a mandatory reason. A
 * partial unique index enforces at most one final validated event per group. Money is
 * integer minor units. See docs/architecture/state-machines/payment-recording-group.md
 * and docs/architecture/data-dictionary/invoicing-and-payments.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_validation_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('payment_recording_group_id')->constrained('payment_recording_groups')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('checker_user_id')->constrained('users')->restrictOnDelete();
            $table->string('decision', 24);
            $table->bigInteger('validated_amount_minor')->nullable();
            $table->string('reason', 500)->nullable();
            // Append-only: created_at only (no updated_at — the row is immutable).
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['payment_recording_group_id']);
            $table->index(['invoice_id']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['checker_user_id', 'created_at']);
            $table->unique(['id', 'merchant_id'], 'payment_validation_events_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE payment_validation_events ADD CONSTRAINT payment_validation_events_decision_check
             CHECK (decision IN ('validated','rejected','correction_required'))"
        );
        // validated_amount_minor present (>=0) iff validated; null otherwise (documented contract).
        DB::statement(
            "ALTER TABLE payment_validation_events ADD CONSTRAINT payment_validation_events_validated_amount_check
             CHECK ((decision = 'validated') = (validated_amount_minor IS NOT NULL) AND (validated_amount_minor IS NULL OR validated_amount_minor >= 0))"
        );
        // reason mandatory for non-validated decisions.
        DB::statement(
            "ALTER TABLE payment_validation_events ADD CONSTRAINT payment_validation_events_reason_check
             CHECK (decision = 'validated' OR (reason IS NOT NULL AND char_length(btrim(reason)) > 0))"
        );
        // At most one final validated event per group.
        DB::statement(
            "CREATE UNIQUE INDEX payment_validation_events_one_validated_per_group
             ON payment_validation_events (payment_recording_group_id) WHERE decision = 'validated'"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE payment_validation_events
             ADD CONSTRAINT payment_validation_events_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_validation_events
             ADD CONSTRAINT payment_validation_events_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE payment_validation_events
             ADD CONSTRAINT payment_validation_events_group_merchant_foreign
             FOREIGN KEY (payment_recording_group_id, merchant_id)
             REFERENCES payment_recording_groups (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_validation_events');
    }
};
