<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * sms_delivery_attempts — append-only provider attempt history for one SMS recipient (Plan §13.13
 * canonical DDL; §64; §24.5 redaction). Canonical DDL:
 * docs/architecture/data-dictionary/messaging-sms.md.
 *
 * ONE ROW PER ATTEMPT. Scope is inherited via `recipient_id` (which is itself branch-owned with
 * composite consistency FKs) and the table is never route-bound — no API surface exposes an
 * attempt — so it is classified EXEMPT in TenantOwnership, exactly like `re_event_deliveries`
 * (21R-A) and `file_scan_events` (10F).
 *
 * RETRY POLICY IS STORED, NOT INFERRED: `result_class` is the decision input, so the
 * transient-vs-permanent policy is provable from the row without a live provider (the 21R-A
 * `re_event_deliveries.response_class` precedent). `status` records the decision that was taken.
 * `next_retry_at` is set only for a transient failure that has retries left.
 *
 * REDACTION (Plan §24.5, ADR-010): `provider_message_redacted` is bounded to 512 characters by the
 * column AND passed through SmsProviderPayloadRedactor before persistence, so no credential, API
 * key, sender id, MSISDN or message body can be stored here. Request headers, payloads and the
 * recipient's phone number are never stored at all — a CHECK additionally rejects any value that
 * still contains a run of 7+ digits, so a leaked number cannot survive a redactor regression.
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_delivery_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recipient_id')->constrained('personnel_sms_recipients')->restrictOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('provider', 32);
            $table->string('status', 24);
            $table->string('result_class', 32);
            $table->string('provider_code', 64)->nullable();
            $table->string('provider_message_redacted', 512)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('attempted_at');
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // One monotonic attempt sequence per recipient.
            $table->unique(['recipient_id', 'attempt_number']);
            $table->index(['recipient_id', 'attempted_at']);
            // Backs the retry sweep.
            $table->index('next_retry_at');
        });

        // Literal statements (no string interpolation into SQL; repo convention + rawSqlConcat rule).
        DB::statement(
            "ALTER TABLE sms_delivery_attempts ADD CONSTRAINT sms_delivery_attempts_status_check
             CHECK (status IN ('accepted','transient_failure','permanent_failure'))"
        );
        DB::statement(
            "ALTER TABLE sms_delivery_attempts ADD CONSTRAINT sms_delivery_attempts_result_class_check
             CHECK (result_class IN ('accepted','invalid_recipient','opted_out','rate_limited','insufficient_balance','provider_error','transport_error','unauthorized','unexpected'))"
        );
        DB::statement(
            'ALTER TABLE sms_delivery_attempts ADD CONSTRAINT sms_delivery_attempts_attempt_number_check
             CHECK (attempt_number >= 1)'
        );
        // Only a transient failure may schedule a retry.
        DB::statement(
            "ALTER TABLE sms_delivery_attempts ADD CONSTRAINT sms_delivery_attempts_next_retry_check
             CHECK (next_retry_at IS NULL OR status = 'transient_failure')"
        );
        // ADR-010 / Plan §24.5 backstop: a redacted provider message can never contain a phone
        // number. Any run of 7 or more consecutive digits is rejected outright.
        DB::statement(
            'ALTER TABLE sms_delivery_attempts ADD CONSTRAINT sms_delivery_attempts_redaction_check
             CHECK (provider_message_redacted IS NULL OR provider_message_redacted !~ \'[0-9]{7}\')'
        );

        // Attempt history is append-only evidence: never updated, never deleted.
        DB::statement(
            "CREATE OR REPLACE FUNCTION sms_delivery_attempts_append_only() RETURNS trigger AS $$
             BEGIN
                 RAISE EXCEPTION 'sms_delivery_attempts rows are append-only';
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER sms_delivery_attempts_append_only_trigger
             BEFORE UPDATE OR DELETE ON sms_delivery_attempts
             FOR EACH ROW EXECUTE FUNCTION sms_delivery_attempts_append_only()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS sms_delivery_attempts_append_only_trigger ON sms_delivery_attempts');
        DB::statement('DROP FUNCTION IF EXISTS sms_delivery_attempts_append_only()');
        Schema::dropIfExists('sms_delivery_attempts');
    }
};
