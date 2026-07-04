<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * commission_handoff_events — durable, immutable, idempotent per-component seam for
 * Phase 20G (Gate C/E; Plan §5.3 REM-COMP-001 seam only). Branch-owned. The ULID is
 * the public identifier.
 *
 * Written in the SAME transaction as a group validation (kind=validated_allocation)
 * and as a refund finalization (kind=reversal). It identifies the invoice item,
 * service, personnel (staff profile), payment record, validated/reversed amount,
 * currency, source event and effective time. It carries NO commission rate, earned
 * row, or payable liability — those belong to Phases 20F/20G. This is explicitly NOT a
 * commission ledger; commission_rules/commission_ledger/earnings/payout tables are not
 * created in Phase 18B. Partial unique indexes make each (source, component) idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_handoff_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->foreignId('payment_validation_event_id')->nullable()->constrained('payment_validation_events')->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->constrained('refunds')->restrictOnDelete();
            $table->foreignId('payment_record_id')->constrained('payment_records')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3)->default('KES');
            $table->timestampTz('effective_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['merchant_id', 'consumed_at']);
            $table->index(['payment_record_id']);
        });

        DB::statement(
            "ALTER TABLE commission_handoff_events ADD CONSTRAINT commission_handoff_events_kind_check
             CHECK (kind IN ('validated_allocation','reversal'))"
        );
        // Source coherence with kind.
        DB::statement(
            "ALTER TABLE commission_handoff_events ADD CONSTRAINT commission_handoff_events_source_check
             CHECK (
                 (kind = 'validated_allocation' AND payment_validation_event_id IS NOT NULL AND refund_id IS NULL)
                 OR (kind = 'reversal' AND refund_id IS NOT NULL AND payment_validation_event_id IS NULL)
             )"
        );
        DB::statement(
            'ALTER TABLE commission_handoff_events ADD CONSTRAINT commission_handoff_events_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        // Idempotent per (source, component).
        DB::statement(
            "CREATE UNIQUE INDEX commission_handoff_events_validation_component_unique
             ON commission_handoff_events (payment_validation_event_id, payment_record_id) WHERE kind = 'validated_allocation'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX commission_handoff_events_reversal_component_unique
             ON commission_handoff_events (refund_id, payment_record_id) WHERE kind = 'reversal'"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE commission_handoff_events
             ADD CONSTRAINT commission_handoff_events_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE commission_handoff_events
             ADD CONSTRAINT commission_handoff_events_payment_record_merchant_foreign
             FOREIGN KEY (payment_record_id, merchant_id)
             REFERENCES payment_records (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_handoff_events');
    }
};
