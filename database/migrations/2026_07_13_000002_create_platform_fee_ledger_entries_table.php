<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_fee_ledger_entries — append-only percentage platform-fee ledger (Plan §13.10,
 * §51; Phase 20E). Canonical DDL: docs/architecture/data-dictionary/billing-and-wallet.md;
 * lifecycle: docs/architecture/state-machines/platform-fee-ledger-entry.md.
 *
 * TENANT-OWNED (merchant_id required; branch_id optional/nullable — like financial_period_locks;
 * BelongsToMerchant). The original `earned` fact is created at Finance validation (billability
 * authority); `reversal`/`adjustment` are ADDITIVE rows referencing the original. Append-only at
 * the database: a trigger blocks all DELETE and blocks any UPDATE of monetary/snapshot columns —
 * only `status` and the aggregation link `subscription_invoice_item_id` may transition (guardrail
 * §6.5; Plan §953). Money is integer minor units. No backfill. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('source_invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('source_invoice_item_id')->nullable()->constrained('invoice_items')->restrictOnDelete();
            $table->string('entry_type', 16);
            $table->string('status', 16)->default('pending');
            $table->string('billing_mode_snapshot', 64);
            $table->string('service_fee_tier_snapshot', 24);
            $table->string('fee_basis_type', 48);
            $table->bigInteger('fee_basis_amount_minor');
            $table->integer('percentage_rate_snapshot');
            $table->integer('shared_split_snapshot')->nullable();
            $table->bigInteger('gross_platform_fee_minor');
            $table->bigInteger('client_shifted_amount_minor');
            $table->bigInteger('merchant_absorbed_amount_minor');
            $table->bigInteger('merchant_liability_minor');
            $table->char('currency', 3);
            $table->foreignId('effective_configuration_id')->constrained('platform_fee_configurations')->restrictOnDelete();
            $table->foreignId('subscription_invoice_item_id')->nullable()->constrained('subscription_invoice_items')->restrictOnDelete();
            $table->foreignId('reversed_entry_id')->nullable()->constrained('platform_fee_ledger_entries')->restrictOnDelete();
            // Immutable validation-source identity: the Phase 18B group validation event that made this
            // liability billable (earned rows). NULL for reversal/adjustment rows (traced via reversed_entry_id).
            $table->foreignId('source_validation_event_id')->nullable()->constrained('payment_validation_events')->restrictOnDelete();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestampTz('billable_at')->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'status'], 'platform_fee_ledger_entries_merchant_status_index');
            $table->index('source_invoice_id', 'platform_fee_ledger_entries_source_invoice_index');
            $table->index('subscription_invoice_item_id', 'platform_fee_ledger_entries_rollup_index');
            $table->index('source_validation_event_id', 'platform_fee_ledger_entries_validation_source_index');
        });

        // Replay/idempotency uniqueness (application key: per validation allocation for earned; per
        // source correction event for reversal/adjustment). Partial — null keys are unconstrained.
        DB::statement(
            'CREATE UNIQUE INDEX platform_fee_ledger_entries_idempotency_unique
             ON platform_fee_ledger_entries (idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );

        // Structural replay invariant matching earned-entry granularity: exactly one earned entry per
        // (validation event, source invoice item). NULLS NOT DISTINCT (PG16) so invoice-level rows
        // (source_invoice_item_id IS NULL) also collide on replay of the same validation event. Scoped
        // to earned rows that carry a validation event, so reversal/adjustment and factory rows without
        // a validation event are unconstrained. This is the DB guarantee; idempotency_key is an
        // additional application control, not a substitute.
        DB::statement(
            "CREATE UNIQUE INDEX platform_fee_ledger_entries_validation_source_unique
             ON platform_fee_ledger_entries (source_validation_event_id, source_invoice_item_id)
             NULLS NOT DISTINCT
             WHERE (entry_type = 'earned' AND source_validation_event_id IS NOT NULL)"
        );

        DB::statement(
            "ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_entry_type_check
             CHECK (entry_type IN ('earned','reversal','adjustment'))"
        );
        DB::statement(
            "ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_status_check
             CHECK (status IN ('pending','aggregated','invoiced','reversed','adjusted'))"
        );
        DB::statement(
            "ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_tier_snapshot_check
             CHECK (service_fee_tier_snapshot IN ('customer_centric','shared','business_centric'))"
        );
        DB::statement(
            "ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_fee_basis_type_check
             CHECK (fee_basis_type IN ('merchant_client_invoice_service_subtotal','merchant_client_invoice_total','net_after_discount','invoice_item_subtotal','validated_paid_amount'))"
        );
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_rate_range_check
             CHECK (percentage_rate_snapshot BETWEEN 0 AND 10000)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_split_range_check
             CHECK (shared_split_snapshot IS NULL OR (shared_split_snapshot BETWEEN 0 AND 10000))'
        );
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_basis_nonneg_check
             CHECK (fee_basis_amount_minor >= 0)'
        );
        // Non-negative amounts; the original earned fee is never negative (corrections are
        // additive reversal/adjustment rows whose amounts may net the balance elsewhere).
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_amounts_nonneg_check
             CHECK (gross_platform_fee_minor >= 0 AND client_shifted_amount_minor >= 0 AND merchant_absorbed_amount_minor >= 0)'
        );
        // Tier split invariant: client_shifted + merchant_absorbed = gross.
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_split_sum_check
             CHECK (client_shifted_amount_minor + merchant_absorbed_amount_minor = gross_platform_fee_minor)'
        );
        // Liability invariant: merchant liability is always the full gross fee.
        DB::statement(
            'ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_liability_check
             CHECK (merchant_liability_minor = gross_platform_fee_minor)'
        );
        // Provenance: reversal/adjustment rows reference an original; earned rows do not.
        DB::statement(
            "ALTER TABLE platform_fee_ledger_entries ADD CONSTRAINT platform_fee_ledger_entries_provenance_check
             CHECK (
                 (entry_type = 'earned' AND reversed_entry_id IS NULL)
                 OR (entry_type IN ('reversal','adjustment') AND reversed_entry_id IS NOT NULL)
             )"
        );

        // Append-only guard: block DELETE always; on UPDATE only status + aggregation link may
        // change — every monetary/snapshot column is immutable.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_fee_ledger_entries_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'platform_fee_ledger_entries is append-only (DELETE blocked)';
                END IF;

                IF ROW(
                    NEW.ulid, NEW.merchant_id, NEW.branch_id, NEW.source_invoice_id, NEW.source_invoice_item_id,
                    NEW.entry_type, NEW.billing_mode_snapshot, NEW.service_fee_tier_snapshot, NEW.fee_basis_type,
                    NEW.fee_basis_amount_minor, NEW.percentage_rate_snapshot, NEW.shared_split_snapshot,
                    NEW.gross_platform_fee_minor, NEW.client_shifted_amount_minor, NEW.merchant_absorbed_amount_minor,
                    NEW.merchant_liability_minor, NEW.currency, NEW.effective_configuration_id, NEW.reversed_entry_id,
                    NEW.source_validation_event_id, NEW.idempotency_key, NEW.billable_at, NEW.created_at
                ) IS DISTINCT FROM ROW(
                    OLD.ulid, OLD.merchant_id, OLD.branch_id, OLD.source_invoice_id, OLD.source_invoice_item_id,
                    OLD.entry_type, OLD.billing_mode_snapshot, OLD.service_fee_tier_snapshot, OLD.fee_basis_type,
                    OLD.fee_basis_amount_minor, OLD.percentage_rate_snapshot, OLD.shared_split_snapshot,
                    OLD.gross_platform_fee_minor, OLD.client_shifted_amount_minor, OLD.merchant_absorbed_amount_minor,
                    OLD.merchant_liability_minor, OLD.currency, OLD.effective_configuration_id, OLD.reversed_entry_id,
                    OLD.source_validation_event_id, OLD.idempotency_key, OLD.billable_at, OLD.created_at
                ) THEN
                    RAISE EXCEPTION 'platform_fee_ledger_entries monetary/snapshot columns are immutable (only status + subscription_invoice_item_id may transition)';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER platform_fee_ledger_entries_no_monetary_update
                BEFORE UPDATE ON platform_fee_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION platform_fee_ledger_entries_guard();

            CREATE TRIGGER platform_fee_ledger_entries_no_delete
                BEFORE DELETE ON platform_fee_ledger_entries
                FOR EACH ROW EXECUTE FUNCTION platform_fee_ledger_entries_guard();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS platform_fee_ledger_entries_no_monetary_update ON platform_fee_ledger_entries;');
        DB::unprepared('DROP TRIGGER IF EXISTS platform_fee_ledger_entries_no_delete ON platform_fee_ledger_entries;');
        DB::unprepared('DROP FUNCTION IF EXISTS platform_fee_ledger_entries_guard();');
        Schema::dropIfExists('platform_fee_ledger_entries');
    }
};
