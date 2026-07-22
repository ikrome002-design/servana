<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * referral_snapshots — Citrus Refer & Earn referral capture evidence (Plan §13.17 canonical DDL;
 * §58A.1 capture; §25.6 snapshot machine; ADR-013; Phase 21R-A). Canonical DDL:
 * docs/architecture/data-dictionary/refer-earn-integration.md; lifecycle:
 * docs/architecture/state-machines/referral-snapshot.md.
 *
 * ONE ROW PER MERCHANT, EVER (unique merchant_id). Written INSIDE the public self-registration
 * transaction, where no TenantContext exists — so `merchant_id` is always supplied explicitly and
 * the table is classified EXEMPT in TenantOwnership (no merchant-facing route exists at all).
 *
 * DATA MINIMIZATION (Plan §9 rule 23, §74): the raw submitted code is encrypted at rest as evidence;
 * `code_normalized` is the only searchable form and is NULL when the code was malformed. There is
 * deliberately NO referrer identity column of any kind — Servana holds the code and R&E's public
 * attribution id, nothing else. `landing_metadata` is an allowlisted, non-PII utm-style bag
 * (App\Domain\Integrations\ReferEarn\Support\LandingMetadataAllowlist).
 *
 * IMMUTABILITY: `confirmed`, `rejected`, `invalid_format` and `expired_unconfirmed` are terminal and
 * trigger-enforced — the DB rejects any status change out of them, and rejects capture-evidence
 * rewrites (merchant, raw code, normalized code, channel, captured_at, landing metadata) at any time.
 * The application state machine mirrors this; the trigger is the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->unique()->constrained('merchants')->restrictOnDelete();
            $table->text('raw_code_encrypted');
            $table->string('code_normalized', 64)->nullable();
            $table->string('capture_channel', 16);
            $table->timestampTz('captured_at');
            $table->jsonb('landing_metadata')->nullable();
            $table->string('snapshot_status', 24);
            $table->string('re_validation_result_code', 64)->nullable();
            $table->string('re_attribution_public_id', 64)->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('last_transition_at');
            $table->timestampsTz();

            $table->index('code_normalized');
            $table->index(['snapshot_status', 'last_transition_at']);
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement(
            "ALTER TABLE referral_snapshots ADD CONSTRAINT referral_snapshots_capture_channel_check
             CHECK (capture_channel IN ('query_param','manual_entry','central_redirect'))"
        );
        DB::statement(
            "ALTER TABLE referral_snapshots ADD CONSTRAINT referral_snapshots_status_check
             CHECK (snapshot_status IN ('captured','invalid_format','validating','validated','rejected','confirmed','expired_unconfirmed'))"
        );
        // invalid_format is the ONLY state with a null normalized code, and it can never be confirmed.
        DB::statement(
            "ALTER TABLE referral_snapshots ADD CONSTRAINT referral_snapshots_normalized_code_check
             CHECK ((snapshot_status = 'invalid_format' AND code_normalized IS NULL)
                 OR (snapshot_status <> 'invalid_format' AND code_normalized IS NOT NULL))"
        );
        DB::statement(
            "ALTER TABLE referral_snapshots ADD CONSTRAINT referral_snapshots_confirmed_at_check
             CHECK ((snapshot_status = 'confirmed' AND confirmed_at IS NOT NULL)
                 OR (snapshot_status <> 'confirmed' AND confirmed_at IS NULL))"
        );
        DB::statement(
            'ALTER TABLE referral_snapshots ADD CONSTRAINT referral_snapshots_raw_code_check
             CHECK (char_length(btrim(raw_code_encrypted)) > 0)'
        );

        // Terminal-state + capture-evidence immutability (Plan §13.17 "Immutable after
        // 'confirmed'/'rejected'; status may not regress; trigger-enforced").
        DB::statement(
            "CREATE OR REPLACE FUNCTION referral_snapshots_guard() RETURNS trigger AS $$
             BEGIN
                 IF OLD.snapshot_status IN ('confirmed','rejected','invalid_format','expired_unconfirmed')
                    AND NEW.snapshot_status IS DISTINCT FROM OLD.snapshot_status THEN
                     RAISE EXCEPTION 'referral_snapshots.snapshot_status is terminal at % and cannot change', OLD.snapshot_status;
                 END IF;

                 IF NEW.merchant_id IS DISTINCT FROM OLD.merchant_id
                    OR NEW.ulid IS DISTINCT FROM OLD.ulid
                    OR NEW.raw_code_encrypted IS DISTINCT FROM OLD.raw_code_encrypted
                    OR NEW.code_normalized IS DISTINCT FROM OLD.code_normalized
                    OR NEW.capture_channel IS DISTINCT FROM OLD.capture_channel
                    OR NEW.captured_at IS DISTINCT FROM OLD.captured_at
                    OR NEW.landing_metadata IS DISTINCT FROM OLD.landing_metadata THEN
                     RAISE EXCEPTION 'referral_snapshots capture evidence is immutable';
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER referral_snapshots_guard_trigger
             BEFORE UPDATE ON referral_snapshots
             FOR EACH ROW EXECUTE FUNCTION referral_snapshots_guard()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS referral_snapshots_guard_trigger ON referral_snapshots');
        DB::statement('DROP FUNCTION IF EXISTS referral_snapshots_guard()');
        Schema::dropIfExists('referral_snapshots');
    }
};
