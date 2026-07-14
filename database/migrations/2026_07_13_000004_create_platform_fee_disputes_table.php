<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_fee_disputes — platform-side dispute case over a percentage platform-fee charge
 * (Plan §13.10 [Correction 3]; Phase 20E). Canonical DDL: docs/architecture/data-dictionary/
 * billing-and-wallet.md; lifecycle: docs/architecture/state-machines/platform-fee-dispute.md.
 *
 * TENANT-OWNED (merchant_id required; branch_id nullable; BelongsToMerchant). Targets a ledger
 * entry and/or a subscription invoice (at least one). status ∈ {open,under_review,resolved,
 * rejected} — NO `escalated`. A money-changing resolution creates a platform_fee_adjustments
 * row; it never rewrites a ledger amount. No destructive deletion (DELETE blocked by trigger).
 * Evidence uses the private uploaded_files domain. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_fee_disputes', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('platform_fee_ledger_entry_id')->nullable()->constrained('platform_fee_ledger_entries')->restrictOnDelete();
            $table->foreignId('subscription_invoice_id')->nullable()->constrained('subscription_invoices')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 16)->default('open');
            $table->foreignId('assigned_reviewer')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('evidence_file_id')->nullable()->constrained('uploaded_files')->restrictOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['merchant_id', 'status'], 'platform_fee_disputes_merchant_status_index');
            $table->index('assigned_reviewer', 'platform_fee_disputes_reviewer_index');
        });

        DB::statement(
            "ALTER TABLE platform_fee_disputes ADD CONSTRAINT platform_fee_disputes_status_check
             CHECK (status IN ('open','under_review','resolved','rejected'))"
        );
        DB::statement(
            'ALTER TABLE platform_fee_disputes ADD CONSTRAINT platform_fee_disputes_reason_check
             CHECK (char_length(btrim(reason)) > 0)'
        );
        // At least one dispute target must be present.
        DB::statement(
            'ALTER TABLE platform_fee_disputes ADD CONSTRAINT platform_fee_disputes_target_check
             CHECK (platform_fee_ledger_entry_id IS NOT NULL OR subscription_invoice_id IS NOT NULL)'
        );
        // Resolution coherence: resolved/rejected carry resolver + time + note; open/under_review
        // do not carry resolution metadata.
        DB::statement(
            "ALTER TABLE platform_fee_disputes ADD CONSTRAINT platform_fee_disputes_resolution_coherence_check
             CHECK (
                 (status IN ('resolved','rejected') AND resolved_by IS NOT NULL AND resolved_at IS NOT NULL AND resolution_note IS NOT NULL)
                 OR (status IN ('open','under_review') AND resolved_by IS NULL AND resolved_at IS NULL)
             )"
        );

        // No destructive deletion (resolution/rejection are terminal states, not deletes).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION platform_fee_disputes_block_delete() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'platform_fee_disputes cannot be deleted (resolve or reject instead)';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER platform_fee_disputes_no_delete
                BEFORE DELETE ON platform_fee_disputes
                FOR EACH ROW EXECUTE FUNCTION platform_fee_disputes_block_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS platform_fee_disputes_no_delete ON platform_fee_disputes;');
        DB::unprepared('DROP FUNCTION IF EXISTS platform_fee_disputes_block_delete();');
        Schema::dropIfExists('platform_fee_disputes');
    }
};
