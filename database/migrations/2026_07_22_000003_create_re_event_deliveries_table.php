<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * re_event_deliveries — append-only delivery-attempt history for the R&E outbox (Plan §13.17
 * canonical DDL; §58A.2; Phase 21R-A). Canonical DDL:
 * docs/architecture/data-dictionary/refer-earn-integration.md.
 *
 * ONE ROW PER ATTEMPT — the full history, because R&E dedupes by event id + hash while Servana keeps
 * retrying the same id and body. Scope is inherited via `re_outbound_event_id` (never route-bound),
 * so the table carries no merchant column and is classified EXEMPT in TenantOwnership.
 *
 * REDACTION (Plan §24.5): `response_body_truncated_redacted` is bounded to 512 characters AND passed
 * through App\Domain\Integrations\ReferEarn\Support\DeliveryResponseRedactor before persistence, so
 * no credential, signature, nonce, token, MSISDN, email or referral code can be stored here. Request
 * headers, signatures and payloads are never stored at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_event_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('re_outbound_event_id')->constrained('re_outbound_events')->restrictOnDelete();
            $table->timestampTz('attempted_at');
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->string('response_class', 24);
            $table->string('error_code', 64)->nullable();
            $table->string('response_body_truncated_redacted', 512)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['re_outbound_event_id', 'attempted_at']);
        });

        DB::statement(
            "ALTER TABLE re_event_deliveries ADD CONSTRAINT re_event_deliveries_response_class_check
             CHECK (response_class IN ('accepted','payload_mismatch','unauthorized','schema_rejected','rate_limited','server_error','transport_error','unexpected'))"
        );

        // Attempt history is append-only evidence: never updated, never deleted.
        DB::statement(
            "CREATE OR REPLACE FUNCTION re_event_deliveries_append_only() RETURNS trigger AS $$
             BEGIN
                 RAISE EXCEPTION 're_event_deliveries rows are append-only';
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER re_event_deliveries_append_only_trigger
             BEFORE UPDATE OR DELETE ON re_event_deliveries
             FOR EACH ROW EXECUTE FUNCTION re_event_deliveries_append_only()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS re_event_deliveries_append_only_trigger ON re_event_deliveries');
        DB::statement('DROP FUNCTION IF EXISTS re_event_deliveries_append_only()');
        Schema::dropIfExists('re_event_deliveries');
    }
};
