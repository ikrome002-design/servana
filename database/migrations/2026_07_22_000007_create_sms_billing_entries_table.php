<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sms_billing_entries — the authoritative billable-SMS queue for a Personnel campaign (Plan §13.13
 * canonical DDL; §64 "roll up billable SMS charge to Servana billing"; ADR-005 integer minor
 * units). Canonical DDL: docs/architecture/data-dictionary/messaging-sms.md; lifecycle:
 * docs/architecture/state-machines/sms-billing-entry.md.
 *
 * BRANCH-OWNED (§13.13 lists merchant_id + branch_id). `amount_minor = quantity * unit_cost_minor`
 * is enforced by a CHECK, so a float-derived or hand-edited amount cannot exist in the database.
 *
 * SERVANA MOVES NO MONEY HERE. This table records what is OWED for SMS; it creates no Wallet
 * payment resource, no payment attempt and no provider call (ADR-012 — money movement is Wallet's
 * truth and is 20D-W work behind a closed Gate W). `billing_invoice_line_id` is the nullable seam
 * to `subscription_invoice_items`: the phase that rolls SMS charges into a subscription invoice
 * sets it and moves `billable -> invoiced`. Until then a `billable` row is a standing liability
 * queue, which is exactly what §64 asks for.
 *
 * NO DOUBLE BILLING: a partial unique index allows at most ONE live entry per campaign
 * (`provisional` / `billable` / `invoiced`), so a duplicate confirm, a job retry or a redelivery
 * cannot create a second charge; `credited` / `cancelled` correction rows may coexist.
 *
 * IMMUTABILITY: `sms_billing_entries_guard` freezes every monetary/ownership column and lets only
 * `status` and `billing_invoice_line_id` transition; `credited` and `cancelled` are terminal.
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_billing_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('personnel_sms_campaigns')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_cost_minor');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16)->default('provisional');
            $table->foreignId('billing_invoice_line_id')->nullable()->constrained('subscription_invoice_items')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'status']);
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_campaign_merchant_foreign FOREIGN KEY (campaign_id, merchant_id) REFERENCES personnel_sms_campaigns (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_status_check
             CHECK (status IN ('provisional','billable','invoiced','credited','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_amounts_check
             CHECK (quantity >= 0 AND unit_cost_minor >= 0 AND amount_minor >= 0)'
        );
        // ADR-005: the amount is never independently supplied — it IS quantity * unit cost.
        DB::statement(
            'ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_amount_product_check
             CHECK (amount_minor = quantity * unit_cost_minor)'
        );
        // Only an invoiced entry carries an invoice line.
        DB::statement(
            "ALTER TABLE sms_billing_entries ADD CONSTRAINT sms_billing_entries_invoice_line_check
             CHECK (billing_invoice_line_id IS NULL OR status IN ('invoiced','credited'))"
        );
        // At most one LIVE billable entry per campaign — the structural no-double-billing guarantee.
        DB::statement(
            "CREATE UNIQUE INDEX sms_billing_entries_live_campaign_unique
             ON sms_billing_entries (campaign_id)
             WHERE status IN ('provisional','billable','invoiced')"
        );

        DB::statement(
            "CREATE OR REPLACE FUNCTION sms_billing_entries_guard() RETURNS trigger AS $$
             BEGIN
                 IF ROW(
                     NEW.ulid, NEW.merchant_id, NEW.branch_id, NEW.campaign_id, NEW.quantity,
                     NEW.unit_cost_minor, NEW.amount_minor, NEW.currency, NEW.created_at
                 ) IS DISTINCT FROM ROW(
                     OLD.ulid, OLD.merchant_id, OLD.branch_id, OLD.campaign_id, OLD.quantity,
                     OLD.unit_cost_minor, OLD.amount_minor, OLD.currency, OLD.created_at
                 ) THEN
                     RAISE EXCEPTION 'sms_billing_entries monetary/ownership columns are immutable (only status and billing_invoice_line_id may transition)';
                 END IF;

                 IF OLD.status IN ('credited','cancelled')
                    AND NEW.status IS DISTINCT FROM OLD.status THEN
                     RAISE EXCEPTION 'sms_billing_entries.status is terminal at % and cannot change', OLD.status;
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER sms_billing_entries_guard_trigger
             BEFORE UPDATE ON sms_billing_entries
             FOR EACH ROW EXECUTE FUNCTION sms_billing_entries_guard()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sms_billing_entries_guard_trigger ON sms_billing_entries');
        DB::statement('DROP FUNCTION IF EXISTS sms_billing_entries_guard()');
        DB::statement('DROP INDEX IF EXISTS sms_billing_entries_live_campaign_unique');
        Schema::dropIfExists('sms_billing_entries');
    }
};
