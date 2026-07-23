<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personnel_sms_recipients — the immutable per-recipient snapshot of a Personnel SMS campaign
 * (Plan §13.13 canonical DDL; §64; ADR-010). Canonical DDL:
 * docs/architecture/data-dictionary/messaging-sms.md; lifecycle:
 * docs/architecture/state-machines/personnel-sms-recipient.md.
 *
 * BRANCH-OWNED. §13.13 lists only the distinguishing columns, but repository convention for child
 * tables (`cash_up_lines`, `invoice_items`) is to carry merchant_id + branch_id with composite
 * consistency FKs so a recipient can never reference a client, session or campaign across a
 * merchant boundary at the DATABASE level, not merely in application code.
 *
 * CONTACT PROTECTION (ADR-010, Plan §19.4, §74) — the single most important property of this table:
 *   - `phone_encrypted` is the delivery snapshot ONLY. It is encrypted at rest (Eloquent
 *     `encrypted` cast), `$hidden` on the model, never placed in a Resource, log, audit context,
 *     OpenAPI example, URL or frontend state, and read solely by the delivery job immediately
 *     before handing the number to the provider adapter.
 *   - `phone_last_four` is the MAXIMUM display identifier anywhere in the product.
 *   - `eligibility_snapshot_json` carries only safe ids / statuses / reason codes. A CHECK forbids
 *     a `phone` key outright so a future contributor cannot smuggle a number in through jsonb.
 *   - There is deliberately NO column, index or constraint that would let this table be read as a
 *     phone list: no searchable phone index, no plaintext column, no export helper.
 *
 * IMMUTABILITY: `personnel_sms_recipients_guard` freezes every snapshot column at ALL times (the
 * row is delivery evidence) and lets only `delivery_status`, `provider_message_id`, `cost_minor`
 * and `updated_at` move; `personnel_sms_recipients_no_delete` blocks DELETE. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_sms_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('campaign_id')->constrained('personnel_sms_campaigns')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_session_id')->nullable()->constrained('service_sessions')->restrictOnDelete();
            // NULLABLE by design (Plan §74 data minimization): a recipient that is suppressed or
            // opted out at confirm is NEVER dispatched, so no delivery snapshot of their number is
            // taken at all. Only a dispatchable recipient carries one.
            $table->text('phone_encrypted')->nullable();
            $table->char('phone_last_four', 4);
            $table->jsonb('eligibility_snapshot_json');
            $table->string('consent_status_snapshot', 16);
            $table->string('delivery_status', 16)->default('pending');
            $table->string('provider_message_id', 64)->nullable();
            $table->bigInteger('cost_minor')->nullable();
            $table->timestampsTz();

            // One row per client per campaign — the dedupe key Plan §64 names.
            $table->unique(['campaign_id', 'client_id']);
            // Tenant-scoped read path (Plan §8.2 requires a branch-owned table to carry an index
            // beginning with merchant_id; TenantColumnCoverageTest enforces it).
            $table->index(['merchant_id', 'branch_id']);
            // The delivery work queue and the campaign roll-up read.
            $table->index(['campaign_id', 'delivery_status']);
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_campaign_merchant_foreign FOREIGN KEY (campaign_id, merchant_id) REFERENCES personnel_sms_campaigns (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_client_merchant_foreign FOREIGN KEY (client_id, merchant_id) REFERENCES clients (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');
        DB::statement('ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_session_merchant_foreign FOREIGN KEY (service_session_id, merchant_id) REFERENCES service_sessions (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_delivery_status_check
             CHECK (delivery_status IN ('pending','sent','delivered','failed','opted_out','suppressed'))"
        );
        DB::statement(
            "ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_consent_snapshot_check
             CHECK (consent_status_snapshot IN ('opted_in','opted_out','missing'))"
        );
        DB::statement(
            'ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_last_four_check
             CHECK (phone_last_four ~ \'^[0-9]{4}$\')'
        );
        DB::statement(
            'ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_phone_check
             CHECK (phone_encrypted IS NULL OR char_length(btrim(phone_encrypted)) > 0)'
        );
        // A dispatchable recipient MUST carry its delivery snapshot; a recipient excluded at
        // confirm carries none (Plan §74 data minimization).
        DB::statement(
            "ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_phone_required_check
             CHECK (phone_encrypted IS NOT NULL OR delivery_status IN ('opted_out','suppressed'))"
        );
        DB::statement(
            'ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_cost_nonneg_check
             CHECK (cost_minor IS NULL OR cost_minor >= 0)'
        );
        // ADR-010 at the storage layer: the eligibility snapshot is safe ids/statuses/reason codes
        // only. A `phone` key can never be written here, by anyone, ever. The function form
        // jsonb_exists() is used deliberately instead of the `?` operator, which PDO would treat
        // as a bound-parameter placeholder.
        DB::statement(
            "ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_snapshot_no_phone_check
             CHECK (jsonb_typeof(eligibility_snapshot_json) = 'object'
                AND NOT jsonb_exists(eligibility_snapshot_json, 'phone')
                AND NOT jsonb_exists(eligibility_snapshot_json, 'phone_encrypted')
                AND NOT jsonb_exists(eligibility_snapshot_json, 'msisdn')
                AND NOT jsonb_exists(eligibility_snapshot_json, 'phone_number'))"
        );
        // A recipient that was never dispatched carries no provider identity and no cost.
        DB::statement(
            "ALTER TABLE personnel_sms_recipients ADD CONSTRAINT personnel_sms_recipients_undispatched_check
             CHECK (delivery_status NOT IN ('pending','opted_out','suppressed')
                 OR (provider_message_id IS NULL AND cost_minor IS NULL))"
        );

        DB::statement(
            "CREATE OR REPLACE FUNCTION personnel_sms_recipients_guard() RETURNS trigger AS $$
             BEGIN
                 IF ROW(
                     NEW.merchant_id, NEW.branch_id, NEW.campaign_id, NEW.client_id,
                     NEW.service_session_id, NEW.phone_encrypted, NEW.phone_last_four,
                     NEW.eligibility_snapshot_json, NEW.consent_status_snapshot, NEW.created_at
                 ) IS DISTINCT FROM ROW(
                     OLD.merchant_id, OLD.branch_id, OLD.campaign_id, OLD.client_id,
                     OLD.service_session_id, OLD.phone_encrypted, OLD.phone_last_four,
                     OLD.eligibility_snapshot_json, OLD.consent_status_snapshot, OLD.created_at
                 ) THEN
                     RAISE EXCEPTION 'personnel_sms_recipients snapshot columns are immutable (only delivery_status, provider_message_id and cost_minor may transition)';
                 END IF;

                 IF OLD.delivery_status IN ('delivered','failed','opted_out','suppressed')
                    AND NEW.delivery_status IS DISTINCT FROM OLD.delivery_status THEN
                     RAISE EXCEPTION 'personnel_sms_recipients.delivery_status is terminal at % and cannot change', OLD.delivery_status;
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER personnel_sms_recipients_guard_trigger
             BEFORE UPDATE ON personnel_sms_recipients
             FOR EACH ROW EXECUTE FUNCTION personnel_sms_recipients_guard()'
        );

        DB::statement(
            "CREATE OR REPLACE FUNCTION personnel_sms_recipients_no_delete() RETURNS trigger AS $$
             BEGIN
                 RAISE EXCEPTION 'personnel_sms_recipients rows are delivery evidence and are never deleted';
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER personnel_sms_recipients_no_delete_trigger
             BEFORE DELETE ON personnel_sms_recipients
             FOR EACH ROW EXECUTE FUNCTION personnel_sms_recipients_no_delete()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS personnel_sms_recipients_no_delete_trigger ON personnel_sms_recipients');
        DB::statement('DROP TRIGGER IF EXISTS personnel_sms_recipients_guard_trigger ON personnel_sms_recipients');
        DB::statement('DROP FUNCTION IF EXISTS personnel_sms_recipients_no_delete()');
        DB::statement('DROP FUNCTION IF EXISTS personnel_sms_recipients_guard()');
        Schema::dropIfExists('personnel_sms_recipients');
    }
};
