<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personnel_sms_campaigns — Personnel bulk SMS to personally served clients (Plan §13.13 canonical
 * DDL; §64; §80 Phase 21S; ADR-010 personnel contact protection; ADR-005 integer minor units).
 * Canonical DDL: docs/architecture/data-dictionary/messaging-sms.md; lifecycle:
 * docs/architecture/state-machines/personnel-sms-campaign.md.
 *
 * BRANCH-OWNED + PERSONNEL OWN-SCOPE. `staff_profile_id` is derived from the authenticated
 * membership at draft time and is NEVER client-supplied; a composite FK to
 * staff_profiles(id, merchant_id) makes a cross-merchant subject impossible at the database level.
 *
 * CONTACT PROTECTION (ADR-010): this table holds NO recipient contact at all — not even a masked
 * one. Recipients live in personnel_sms_recipients. The message body is encrypted at rest
 * (Eloquent `encrypted` cast) because a personnel-authored message may name a client; Plan §24.5
 * forbids logging the decrypted value and the audit context deliberately never carries it.
 *
 * MONEY: `estimated_cost_minor` / `final_cost_minor` are integer minor units only (ADR-005); the
 * authoritative billable amount is `sms_billing_entries.amount_minor`, not this snapshot.
 *
 * IMMUTABILITY: `personnel_sms_campaigns_guard` freezes every composition/pricing snapshot column
 * the moment the campaign leaves `draft`, and rejects any status change out of the three terminal
 * states (`completed`, `failed`, `cancelled`). The application state machine mirrors this; the
 * trigger is the backstop. Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_sms_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->text('message_body_encrypted');
            // §13.13 reserves this column for a future message-template catalogue. No template
            // substrate exists in Phase 21S (templates are explicitly out of scope), so a CHECK
            // pins it to NULL rather than allowing a dangling reference. The phase that adds the
            // catalogue drops the CHECK and adds the FK (expand/contract, ADR-004).
            $table->unsignedBigInteger('message_template_id')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('message_character_count');
            $table->unsignedInteger('segment_count');
            $table->bigInteger('estimated_cost_minor');
            $table->bigInteger('final_cost_minor')->nullable();
            $table->char('currency', 3);
            $table->string('status', 20)->default('draft');
            $table->string('failure_reason_code', 32)->nullable();
            $table->timestampTz('consent_snapshot_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('queued_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            // Backs the composite consistency FK from personnel_sms_recipients + sms_billing_entries.
            $table->unique(['id', 'merchant_id'], 'personnel_sms_campaigns_id_merchant_id_unique');
            // Own-scope campaign list (the personnel index) and the branch work queue.
            $table->index(['staff_profile_id', 'status'], 'personnel_sms_campaigns_staff_status_index');
            $table->index(['merchant_id', 'branch_id', 'status']);
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement('ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_branch_merchant_foreign FOREIGN KEY (branch_id, merchant_id) REFERENCES merchant_branches (id, merchant_id) ON DELETE CASCADE ON UPDATE CASCADE');
        DB::statement('ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_staff_merchant_foreign FOREIGN KEY (staff_profile_id, merchant_id) REFERENCES staff_profiles (id, merchant_id) ON DELETE RESTRICT ON UPDATE CASCADE');

        DB::statement(
            "ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_status_check
             CHECK (status IN ('draft','confirmed','queued','sending','completed','partially_failed','failed','cancelled'))"
        );
        DB::statement(
            'ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_currency_check
             CHECK (currency = upper(currency) AND char_length(currency) = 3)'
        );
        DB::statement(
            'ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_cost_nonneg_check
             CHECK (estimated_cost_minor >= 0 AND (final_cost_minor IS NULL OR final_cost_minor >= 0))'
        );
        DB::statement(
            'ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_body_check
             CHECK (char_length(btrim(message_body_encrypted)) > 0)'
        );
        DB::statement(
            'ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_segments_check
             CHECK (segment_count >= 1 AND message_character_count >= 1)'
        );
        // Phase 21S ships no message-template catalogue.
        DB::statement(
            'ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_template_check
             CHECK (message_template_id IS NULL)'
        );
        // A draft is never confirmed; anything past draft (except a cancellation, which may have
        // been confirmed first or cancelled straight out of draft) always carries its confirmation
        // timestamp and its consent snapshot instant.
        DB::statement(
            "ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_confirmed_at_check
             CHECK ((status = 'draft' AND confirmed_at IS NULL)
                 OR (status = 'cancelled')
                 OR (status NOT IN ('draft','cancelled') AND confirmed_at IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_consent_snapshot_check
             CHECK (status IN ('draft','cancelled') OR consent_snapshot_at IS NOT NULL)"
        );
        // A confirmed campaign always has at least one eligible recipient (confirm rejects an
        // all-suppressed selection with 422 rather than queueing an empty send).
        DB::statement(
            "ALTER TABLE personnel_sms_campaigns ADD CONSTRAINT personnel_sms_campaigns_recipient_count_check
             CHECK (status IN ('draft','cancelled') OR recipient_count >= 1)"
        );

        // Snapshot immutability past draft + terminal-state finality.
        DB::statement(
            "CREATE OR REPLACE FUNCTION personnel_sms_campaigns_guard() RETURNS trigger AS $$
             BEGIN
                 IF OLD.status IN ('completed','failed','cancelled')
                    AND NEW.status IS DISTINCT FROM OLD.status THEN
                     RAISE EXCEPTION 'personnel_sms_campaigns.status is terminal at % and cannot change', OLD.status;
                 END IF;

                 IF NEW.ulid IS DISTINCT FROM OLD.ulid
                    OR NEW.merchant_id IS DISTINCT FROM OLD.merchant_id
                    OR NEW.branch_id IS DISTINCT FROM OLD.branch_id
                    OR NEW.staff_profile_id IS DISTINCT FROM OLD.staff_profile_id
                    OR NEW.created_by IS DISTINCT FROM OLD.created_by THEN
                     RAISE EXCEPTION 'personnel_sms_campaigns ownership columns are immutable';
                 END IF;

                 IF OLD.status <> 'draft' THEN
                     IF ROW(
                         NEW.message_body_encrypted, NEW.message_template_id, NEW.recipient_count,
                         NEW.message_character_count, NEW.segment_count, NEW.estimated_cost_minor,
                         NEW.currency, NEW.consent_snapshot_at, NEW.confirmed_at, NEW.created_at
                     ) IS DISTINCT FROM ROW(
                         OLD.message_body_encrypted, OLD.message_template_id, OLD.recipient_count,
                         OLD.message_character_count, OLD.segment_count, OLD.estimated_cost_minor,
                         OLD.currency, OLD.consent_snapshot_at, OLD.confirmed_at, OLD.created_at
                     ) THEN
                         RAISE EXCEPTION 'personnel_sms_campaigns composition/pricing snapshot is immutable once confirmed';
                     END IF;
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER personnel_sms_campaigns_guard_trigger
             BEFORE UPDATE ON personnel_sms_campaigns
             FOR EACH ROW EXECUTE FUNCTION personnel_sms_campaigns_guard()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS personnel_sms_campaigns_guard_trigger ON personnel_sms_campaigns');
        DB::statement('DROP FUNCTION IF EXISTS personnel_sms_campaigns_guard()');
        Schema::dropIfExists('personnel_sms_campaigns');
    }
};
