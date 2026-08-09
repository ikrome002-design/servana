<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * platform_sms_billing_rules — the single effective-dated SMS pricing authority
 * (COR-UI08-001 §9; Phase UI-08). Canonical DDL:
 * docs/architecture/data-dictionary/billing-and-wallet.md; lifecycle:
 * docs/architecture/state-machines/platform-sms-billing-rule.md.
 *
 * WHY A DEDICATED TABLE (proven, not assumed). COR-UI08-001 prefers extending the existing
 * `platform_billing_settings.settings` map and permits this table only on proof. The proof:
 * every settings row is a COMPLETE configuration snapshot and resolution picks exactly one row,
 * TWO authorities (UpdatePlatformBillingSettings, UpdatePlatformSettings) write that same series
 * each carrying the other's fields forward at now(), and `UNIQUE(effective_from)` couples the two
 * scheduling streams. A future-dated SMS row would therefore snapshot the other fields at
 * AUTHORING time and silently revert any billing-mode/trial/grace/currency/settings change that
 * landed in between — a financial regression, because those fields drive subscription invoicing.
 * `/billing/sms` requires a scheduled next rule, so immediate-only writes are not an option either.
 *
 * PLATFORM-OWNED: no merchant_id / no branch_id (registered in TenantOwnership).
 *
 * NO CURRENCY COLUMN. Currency stays the single authority it already is — the effective
 * `platform_billing_settings` version — so this table cannot introduce a second one.
 *
 * APPEND-ONLY. `platform_sms_billing_rules_guard` freezes every pricing/ownership column and
 * permits only a pending -> cancelled transition, and only while `effective_from > now()`. An
 * already-effective rule is permanent history; a scheduled one may be withdrawn with actor and
 * reason before it takes effect. DELETE always raises.
 *
 * A pricing change NEVER recalculates a charged row: `sms_billing_entries` snapshots
 * quantity/unit_cost/amount/currency and is itself trigger-frozen. Forward-only (ADR-004);
 * integer minor units (ADR-005).
 */
return new class extends Migration
{
    /** The day sms_billing_entries was created — no usage row can predate it. */
    private const GENESIS_EFFECTIVE_FROM = '2026-07-22 00:00:00+00';

    public function up(): void
    {
        Schema::create('platform_sms_billing_rules', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Price per SEGMENT per RECIPIENT — the same basis Phase 21S already bills on.
            $table->bigInteger('unit_cost_minor');
            // Disclosure only: sms_billing_entries CHECKs amount_minor = quantity * unit_cost_minor,
            // so tax is never folded into a charged amount. NULL at launch.
            $table->integer('tax_basis_points')->nullable();
            $table->bigInteger('usage_warning_threshold_units')->nullable();
            $table->integer('usage_anomaly_threshold_basis_points')->nullable();
            $table->timestampTz('effective_from');
            $table->string('reason', 500);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestampsTz();

            // The overlap guarantee: two rules can never claim the same instant, so "which rule
            // applies at T?" always has exactly one answer.
            $table->unique('effective_from', 'platform_sms_billing_rules_effective_from_unique');
            $table->index('effective_from', 'platform_sms_billing_rules_effective_from_index');
        });

        DB::statement(
            'ALTER TABLE platform_sms_billing_rules ADD CONSTRAINT platform_sms_billing_rules_unit_cost_check
             CHECK (unit_cost_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE platform_sms_billing_rules ADD CONSTRAINT platform_sms_billing_rules_tax_check
             CHECK (tax_basis_points IS NULL OR (tax_basis_points >= 0 AND tax_basis_points <= 10000))'
        );
        DB::statement(
            'ALTER TABLE platform_sms_billing_rules ADD CONSTRAINT platform_sms_billing_rules_warning_threshold_check
             CHECK (usage_warning_threshold_units IS NULL OR usage_warning_threshold_units >= 0)'
        );
        DB::statement(
            'ALTER TABLE platform_sms_billing_rules ADD CONSTRAINT platform_sms_billing_rules_anomaly_threshold_check
             CHECK (usage_anomaly_threshold_basis_points IS NULL OR usage_anomaly_threshold_basis_points >= 0)'
        );
        // A cancellation is either fully recorded or absent — never half-stated.
        DB::statement(
            'ALTER TABLE platform_sms_billing_rules ADD CONSTRAINT platform_sms_billing_rules_cancellation_check
             CHECK ((cancelled_at IS NULL) = (cancelled_by_user_id IS NULL)
                AND (cancelled_at IS NULL) = (cancellation_reason IS NULL))'
        );

        // Append-only guard. Literal statement (no interpolation; repo rawSqlConcat rule).
        DB::statement(
            "CREATE OR REPLACE FUNCTION platform_sms_billing_rules_guard() RETURNS trigger AS $$
             BEGIN
                 IF (TG_OP = 'DELETE') THEN
                     RAISE EXCEPTION 'platform_sms_billing_rules is append-only: a pricing rule is never deleted';
                 END IF;

                 IF (NEW.unit_cost_minor IS DISTINCT FROM OLD.unit_cost_minor
                     OR NEW.tax_basis_points IS DISTINCT FROM OLD.tax_basis_points
                     OR NEW.usage_warning_threshold_units IS DISTINCT FROM OLD.usage_warning_threshold_units
                     OR NEW.usage_anomaly_threshold_basis_points IS DISTINCT FROM OLD.usage_anomaly_threshold_basis_points
                     OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                     OR NEW.reason IS DISTINCT FROM OLD.reason
                     OR NEW.created_by_user_id IS DISTINCT FROM OLD.created_by_user_id
                     OR NEW.ulid IS DISTINCT FROM OLD.ulid
                     OR NEW.id IS DISTINCT FROM OLD.id) THEN
                     RAISE EXCEPTION 'platform_sms_billing_rules pricing columns are immutable: schedule a new version instead';
                 END IF;

                 IF (OLD.cancelled_at IS NOT NULL AND NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at) THEN
                     RAISE EXCEPTION 'a cancelled SMS pricing rule is terminal';
                 END IF;

                 IF (OLD.cancelled_at IS NULL AND NEW.cancelled_at IS NOT NULL AND OLD.effective_from <= now()) THEN
                     RAISE EXCEPTION 'an already-effective SMS pricing rule cannot be cancelled';
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER platform_sms_billing_rules_guard
             BEFORE UPDATE OR DELETE ON platform_sms_billing_rules
             FOR EACH ROW EXECUTE FUNCTION platform_sms_billing_rules_guard()'
        );

        $this->backfillGenesisRule();
    }

    /**
     * Exactly one genesis rule, carrying the value that actually produced every existing
     * sms_billing_entries snapshot, effective from the day that table was created. Nothing is
     * recalculated — this makes the whole existing history resolvable, truthfully.
     *
     * `created_by_user_id` is the lowest-id platform staff user (the seeded Super Administrator).
     * When no platform user exists yet — a fresh database whose seeders have not run — the rule is
     * skipped rather than attributed to a fabricated actor; the first scheduled rule then becomes
     * the genesis, and ResolveEffectiveSmsBillingRule fails closed until one exists.
     */
    private function backfillGenesisRule(): void
    {
        $actorId = DB::table('users')
            ->where('is_platform_staff', true)
            ->orderBy('id')
            ->value('id');

        if ($actorId === null) {
            return;
        }

        $unitCost = (int) config('sms.pricing.unit_cost_minor', 0);

        DB::table('platform_sms_billing_rules')->insert([
            'ulid' => (string) Str::ulid(),
            'unit_cost_minor' => max($unitCost, 0),
            'tax_basis_points' => null,
            'usage_warning_threshold_units' => null,
            'usage_anomaly_threshold_basis_points' => null,
            'effective_from' => self::GENESIS_EFFECTIVE_FROM,
            'reason' => 'Genesis rule (COR-UI08-001): the configured launch unit cost, carried forward unchanged so every existing SMS billing entry resolves to the rule that produced its snapshot.',
            'created_by_user_id' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS platform_sms_billing_rules_guard ON platform_sms_billing_rules');
        DB::statement('DROP FUNCTION IF EXISTS platform_sms_billing_rules_guard()');
        Schema::dropIfExists('platform_sms_billing_rules');
    }
};
