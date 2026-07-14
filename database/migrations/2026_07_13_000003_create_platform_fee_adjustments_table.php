<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_fee_adjustments — append-only correction evidence over a percentage platform-fee
 * ledger entry (Plan §13.10; Phase 20E). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md.
 *
 * TENANT-OWNED (merchant_id required; branch_id nullable; BelongsToMerchant). Created by void /
 * refund / correction / dispute-resolution flows; it never rewrites a ledger amount. Fully
 * immutable after insert: a trigger blocks every UPDATE and DELETE (guardrail §6.5). Period-lock
 * and maker/checker are enforced at the action layer. Money is integer minor units. No backfill.
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('platform_fee_ledger_entry_id')->constrained('platform_fee_ledger_entries')->restrictOnDelete();
            $table->string('adjustment_type', 24);
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->text('reason');
            $table->string('source_reference', 191)->nullable();
            $table->date('effective_date');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestampTz('created_at')->nullable();

            $table->index(['merchant_id', 'platform_fee_ledger_entry_id'], 'platform_fee_adjustments_entry_index');
        });

        DB::statement(
            'CREATE UNIQUE INDEX platform_fee_adjustments_idempotency_unique
             ON platform_fee_adjustments (idempotency_key)
             WHERE idempotency_key IS NOT NULL'
        );

        DB::statement(
            "ALTER TABLE platform_fee_adjustments ADD CONSTRAINT platform_fee_adjustments_type_check
             CHECK (adjustment_type IN ('reversal','partial_refund','correction','dispute_resolution'))"
        );
        DB::statement(
            'ALTER TABLE platform_fee_adjustments ADD CONSTRAINT platform_fee_adjustments_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_adjustments ADD CONSTRAINT platform_fee_adjustments_amount_nonzero_check
             CHECK (amount_minor <> 0)'
        );
        DB::statement(
            'ALTER TABLE platform_fee_adjustments ADD CONSTRAINT platform_fee_adjustments_reason_check
             CHECK (char_length(btrim(reason)) > 0)'
        );
        // Sign coherence: reversals and refunds reduce liability (negative); corrections and
        // dispute resolutions may go either way.
        DB::statement(
            "ALTER TABLE platform_fee_adjustments ADD CONSTRAINT platform_fee_adjustments_sign_check
             CHECK (
                 (adjustment_type IN ('reversal','partial_refund') AND amount_minor < 0)
                 OR (adjustment_type IN ('correction','dispute_resolution'))
             )"
        );

        // Fully immutable after insert.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_fee_adjustments_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'platform_fee_adjustments is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER platform_fee_adjustments_no_update
                BEFORE UPDATE ON platform_fee_adjustments
                FOR EACH ROW EXECUTE FUNCTION platform_fee_adjustments_block_mutation();

            CREATE TRIGGER platform_fee_adjustments_no_delete
                BEFORE DELETE ON platform_fee_adjustments
                FOR EACH ROW EXECUTE FUNCTION platform_fee_adjustments_block_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS platform_fee_adjustments_no_update ON platform_fee_adjustments;');
        DB::unprepared('DROP TRIGGER IF EXISTS platform_fee_adjustments_no_delete ON platform_fee_adjustments;');
        DB::unprepared('DROP FUNCTION IF EXISTS platform_fee_adjustments_block_mutation();');
        Schema::dropIfExists('platform_fee_adjustments');
    }
};
