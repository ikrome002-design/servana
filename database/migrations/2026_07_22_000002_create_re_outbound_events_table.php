<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * re_outbound_events — transactional outbox for Servana→Citrus R&E facts (Plan §13.17 canonical DDL;
 * §58A.2 emission/delivery; §25.6 outbox machine; §9 rule 22 outbound event integrity; ADR-013/015;
 * Phase 21R-A). Canonical DDL: docs/architecture/data-dictionary/refer-earn-integration.md;
 * lifecycle: docs/architecture/state-machines/re-outbound-event.md.
 *
 * OUTBOX PATTERN: a row is inserted in the SAME DB transaction as the source domain change, so the
 * fact and its event commit or roll back together. `event_id` is a ULID generated once at insert and
 * is stable across every retry; `content_sha256` is computed at insert over the canonical JSON body
 * (sorted keys, no insignificant whitespace) so the same event always signs and delivers the same
 * bytes. `sequence_no` is per-merchant monotonic — R&E workers partition by merchant.
 *
 * APPEND-ONLY PAYLOAD (Plan §9 rule 22: "mutating a queued outbox payload after insert is forbidden
 * and prevented by an append-only trigger"). The trigger freezes every identity/content column and
 * lets ONLY the delivery-progress columns move. Deletion is blocked outright: an emitted fact is
 * evidence.
 *
 * `merchant_id` is nullable strictly because §13.17 reserves null for product-level events; NO
 * product-level event exists at launch (asserted in tests), which is why this table is classified
 * EXEMPT in TenantOwnership rather than TENANT_OWNED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_outbound_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('event_id', 26)->unique();
            $table->string('event_type', 64);
            $table->string('event_version', 8);
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->restrictOnDelete();
            $table->char('merchant_public_id', 26)->nullable();
            $table->unsignedBigInteger('sequence_no');
            $table->jsonb('payload');
            $table->char('content_sha256', 64);
            $table->timestampTz('occurred_at');
            $table->string('delivery_status', 16)->default('pending');
            $table->timestampTz('delivered_at')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['merchant_id', 'sequence_no']);
            $table->index(['delivery_status', 'next_attempt_at']);
            $table->index(['merchant_id', 'event_type']);
        });

        DB::statement(
            "ALTER TABLE re_outbound_events ADD CONSTRAINT re_outbound_events_delivery_status_check
             CHECK (delivery_status IN ('pending','delivering','delivered','dead_letter','superseded'))"
        );
        // §58B.1 catalogue — Phase 21R-A owns the five merchant.* types ONLY. subscription.*/activity.*
        // are Phase 21R-B and are rejected by the database until that phase widens this constraint.
        DB::statement(
            "ALTER TABLE re_outbound_events ADD CONSTRAINT re_outbound_events_event_type_check
             CHECK (event_type IN ('merchant.registration_started','merchant.admin_created','merchant.setup_completed','merchant.status_changed','merchant.identity_snapshot_changed'))"
        );
        DB::statement(
            "ALTER TABLE re_outbound_events ADD CONSTRAINT re_outbound_events_content_sha256_check
             CHECK (content_sha256 ~ '^[0-9a-f]{64}$')"
        );
        // Null merchant is reserved for product-level events (none at launch); when a merchant is set
        // its denormalized public id must be set too, because the payload is built from it.
        DB::statement(
            'ALTER TABLE re_outbound_events ADD CONSTRAINT re_outbound_events_merchant_public_id_check
             CHECK ((merchant_id IS NULL AND merchant_public_id IS NULL)
                 OR (merchant_id IS NOT NULL AND merchant_public_id IS NOT NULL))'
        );
        DB::statement(
            "ALTER TABLE re_outbound_events ADD CONSTRAINT re_outbound_events_delivered_at_check
             CHECK (delivery_status <> 'delivered' OR delivered_at IS NOT NULL)"
        );

        // Append-only: identity + content are frozen after insert; only delivery progress may move.
        DB::statement(
            "CREATE OR REPLACE FUNCTION re_outbound_events_append_only() RETURNS trigger AS $$
             BEGIN
                 IF NEW.ulid IS DISTINCT FROM OLD.ulid
                    OR NEW.event_id IS DISTINCT FROM OLD.event_id
                    OR NEW.event_type IS DISTINCT FROM OLD.event_type
                    OR NEW.event_version IS DISTINCT FROM OLD.event_version
                    OR NEW.merchant_id IS DISTINCT FROM OLD.merchant_id
                    OR NEW.merchant_public_id IS DISTINCT FROM OLD.merchant_public_id
                    OR NEW.sequence_no IS DISTINCT FROM OLD.sequence_no
                    OR NEW.payload IS DISTINCT FROM OLD.payload
                    OR NEW.content_sha256 IS DISTINCT FROM OLD.content_sha256
                    OR NEW.occurred_at IS DISTINCT FROM OLD.occurred_at
                    OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                     RAISE EXCEPTION 're_outbound_events rows are append-only; only delivery progress may change';
                 END IF;

                 -- 'delivered' and 'dead_letter' are terminal. §25.6 reserves 'superseded' for
                 -- schema-version replacement replays, so that is the ONLY exit from a terminal
                 -- state; 'superseded' itself is final.
                 IF OLD.delivery_status IN ('delivered','dead_letter')
                    AND NEW.delivery_status NOT IN (OLD.delivery_status, 'superseded') THEN
                     RAISE EXCEPTION 're_outbound_events.delivery_status is terminal at % and may only move to superseded', OLD.delivery_status;
                 END IF;

                 IF OLD.delivery_status = 'superseded'
                    AND NEW.delivery_status IS DISTINCT FROM OLD.delivery_status THEN
                     RAISE EXCEPTION 're_outbound_events.delivery_status is terminal at superseded';
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER re_outbound_events_append_only_trigger
             BEFORE UPDATE ON re_outbound_events
             FOR EACH ROW EXECUTE FUNCTION re_outbound_events_append_only()'
        );

        DB::statement(
            "CREATE OR REPLACE FUNCTION re_outbound_events_no_delete() RETURNS trigger AS $$
             BEGIN
                 RAISE EXCEPTION 're_outbound_events rows are never deleted';
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER re_outbound_events_no_delete_trigger
             BEFORE DELETE ON re_outbound_events
             FOR EACH ROW EXECUTE FUNCTION re_outbound_events_no_delete()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS re_outbound_events_no_delete_trigger ON re_outbound_events');
        DB::statement('DROP TRIGGER IF EXISTS re_outbound_events_append_only_trigger ON re_outbound_events');
        DB::statement('DROP FUNCTION IF EXISTS re_outbound_events_no_delete()');
        DB::statement('DROP FUNCTION IF EXISTS re_outbound_events_append_only()');
        Schema::dropIfExists('re_outbound_events');
    }
};
