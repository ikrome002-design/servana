<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * service_sessions — the unit of service delivery (Plan §13.7, §25.2; §80 Phase
 * 16C). Branch-owned. The ULID is the public identifier; the internal bigint id is
 * never exposed.
 *
 * A service session always originates from a queue entry (`queue_entry_id`): the
 * queue `called → in_service` transition creates and starts exactly one session,
 * and `in_service → completed` completes it (Gate A — appointment provenance is
 * preserved through `queue_entries.appointment_id`; there is NO `appointment_id`
 * column and NO direct appointment → session path in 16C). The performed
 * `service_id` is snapshotted from the locked source queue entry (Gate B). The full
 * Service Session lifecycle lives in docs/architecture/state-machines/service-session.md.
 *
 * Structural invariants live in PostgreSQL, not only the app:
 *   - composite FKs force same-merchant linkage to branch, source queue entry,
 *     client, service, and personnel;
 *   - CHECK constraints enforce the four states, status↔timestamp coherence, and a
 *     required cancellation reason;
 *   - a PARTIAL UNIQUE index on (staff_profile_id) WHERE status IN
 *     ('pending','in_progress') makes the database the final authority that a
 *     personnel member has at most one active session (duplicate-active
 *     protection), and UNIQUE (queue_entry_id) makes a queue entry produce at most
 *     one session.
 *
 * Phase 16C creates NO invoice, payment, receipt, commission ledger, commission
 * rule, compensation plan, or preferred-personnel fee; completion yields a typed
 * NON-PAYABLE commission preview only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            // Source: the queue entry that produced this session. Nullable per the
            // canonical §13.7 summary (forward-compatible), but always set in 16C.
            $table->foreignId('queue_entry_id')->nullable()->constrained('queue_entries')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            // Immutable preferred-personnel execution evidence (NOT a fee): null = no
            // preferred request on the source; true = honoured; false = overridden.
            $table->boolean('preferred_personnel_honored')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['staff_profile_id', 'status']);
            $table->index('client_id');
            // One session per queue entry (NULLs allowed → only non-null enforced).
            $table->unique('queue_entry_id', 'service_sessions_queue_entry_id_unique');
            // Composite-FK target for Phase 17 invoice_items.service_session_id.
            $table->unique(['id', 'merchant_id'], 'service_sessions_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE service_sessions ADD CONSTRAINT service_sessions_status_check
             CHECK (status IN ('pending','in_progress','completed','cancelled'))"
        );

        // Status ↔ timestamp coherence.
        DB::statement(
            "ALTER TABLE service_sessions ADD CONSTRAINT service_sessions_started_at_check
             CHECK (status NOT IN ('in_progress','completed') OR started_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE service_sessions ADD CONSTRAINT service_sessions_completed_at_check
             CHECK ((completed_at IS NOT NULL) = (status = 'completed'))"
        );
        DB::statement(
            "ALTER TABLE service_sessions ADD CONSTRAINT service_sessions_cancelled_at_check
             CHECK ((cancelled_at IS NOT NULL) = (status = 'cancelled'))"
        );
        DB::statement(
            "ALTER TABLE service_sessions ADD CONSTRAINT service_sessions_cancellation_reason_check
             CHECK (status <> 'cancelled' OR cancellation_reason IS NOT NULL)"
        );
        // A completed session must have been started.
        DB::statement(
            'ALTER TABLE service_sessions ADD CONSTRAINT service_sessions_completed_started_check
             CHECK (completed_at IS NULL OR started_at IS NOT NULL)'
        );

        // Duplicate-active protection: at most one active (pending/in_progress)
        // session per personnel member. PostgreSQL is the concurrency authority.
        DB::statement(
            "CREATE UNIQUE INDEX service_sessions_active_staff_unique
             ON service_sessions (staff_profile_id)
             WHERE status IN ('pending','in_progress')"
        );

        // Composite consistency (same-merchant linkage; R5 pattern). Literal
        // statements; no string interpolation (the Larastan raw-SQL guard).
        DB::statement(
            'ALTER TABLE service_sessions
             ADD CONSTRAINT service_sessions_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE service_sessions
             ADD CONSTRAINT service_sessions_queue_entry_merchant_foreign
             FOREIGN KEY (queue_entry_id, merchant_id)
             REFERENCES queue_entries (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE service_sessions
             ADD CONSTRAINT service_sessions_client_merchant_foreign
             FOREIGN KEY (client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE service_sessions
             ADD CONSTRAINT service_sessions_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE service_sessions
             ADD CONSTRAINT service_sessions_staff_profile_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_sessions');
    }
};
