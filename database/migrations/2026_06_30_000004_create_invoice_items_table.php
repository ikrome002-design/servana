<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_items — service line items of a merchant-client invoice (Plan §13.8;
 * §80 Phase 17). Branch-owned. The ULID is the public identifier.
 *
 * Gate A: every Phase 17 item sources from a COMPLETED service_sessions row
 * (service_session_id NOT NULL, composite FK to the (id, merchant_id) target the
 * Phase 16C migration prepared). client/service/personnel/branch provenance is
 * derived from the locked completed session, never the browser. One invoice may
 * contain multiple completed sessions (one item each; same merchant/branch/client/
 * currency). UNIQUE(service_session_id) prevents duplicate invoicing of the same
 * completed service (re-invoicing a voided session is a documented correction
 * workflow, deferred — never a destructive rewrite).
 *
 * Snapshots (unit_price_minor, preferred_personnel_fee_minor, description,
 * eligible_for_commission) are frozen at finalization and never recalculated.
 * Money is integer minor units (Money value object). Structural invariants:
 * quantity > 0, non-negative price, line-total arithmetic coherence, uppercase-ISO
 * currency, composite-merchant FKs for tenant/branch/source/service/personnel
 * consistency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('service_session_id')->constrained('service_sessions')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->string('description', 255);
            $table->integer('quantity')->default(1);
            $table->bigInteger('unit_price_minor');
            $table->bigInteger('line_total_minor');
            // Gate D snapshot: null = no preferred fee on this item.
            $table->bigInteger('preferred_personnel_fee_minor')->nullable();
            // Commission EVIDENCE only — no ledger/earned/payable in Phase 17.
            $table->boolean('eligible_for_commission')->default(false);
            $table->char('currency', 3)->default('KES');
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index('invoice_id');
            $table->index('service_id');
            $table->index('staff_profile_id');
            // One completed session is invoiced at most once (duplicate-invoicing prevention).
            $table->unique('service_session_id', 'invoice_items_service_session_id_unique');
        });

        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_quantity_check CHECK (quantity > 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_unit_price_check CHECK (unit_price_minor >= 0)');
        DB::statement('ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_line_total_nonneg_check CHECK (line_total_minor >= 0)');
        DB::statement(
            'ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_line_total_arithmetic_check
             CHECK (line_total_minor = unit_price_minor * quantity)'
        );
        DB::statement(
            'ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_preferred_fee_check
             CHECK (preferred_personnel_fee_minor IS NULL OR preferred_personnel_fee_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE invoice_items ADD CONSTRAINT invoice_items_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE invoice_items
             ADD CONSTRAINT invoice_items_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE invoice_items
             ADD CONSTRAINT invoice_items_invoice_merchant_foreign
             FOREIGN KEY (invoice_id, merchant_id)
             REFERENCES invoices (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE invoice_items
             ADD CONSTRAINT invoice_items_service_session_merchant_foreign
             FOREIGN KEY (service_session_id, merchant_id)
             REFERENCES service_sessions (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE invoice_items
             ADD CONSTRAINT invoice_items_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE invoice_items
             ADD CONSTRAINT invoice_items_staff_profile_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
