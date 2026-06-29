<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * queue_entries — the operational branch queue (Plan §13.7, §25.2, §37; §80
 * Phase 16B). Branch-owned. The ULID is the public identifier; the internal bigint
 * id is never exposed.
 *
 * A queue entry originates from EXACTLY ONE source — a walk-in (`walk_in_id`) or a
 * checked-in appointment (`appointment_id`) — enforced by a source-XOR CHECK plus
 * per-source UNIQUE indexes (a walk-in/appointment converts at most once). The full
 * Queue Entry lifecycle lives in docs/architecture/state-machines/queue-entry.md.
 *
 * Structural invariants live in PostgreSQL, not only the app:
 *   - composite FKs force same-merchant linkage to branch, source, client, service,
 *     and all four staff-profile columns;
 *   - CHECK constraints enforce the eight states, the three assignment modes, a
 *     positive position, status↔timestamp coherence, required reasons, and the
 *     wait-override value/reason pairing;
 *   - a PARTIAL UNIQUE index on (branch_id, position) WHERE status in the
 *     active-ordered set (waiting/assigned/called) makes active positions unique
 *     per branch (the database is the final authority under concurrent creates /
 *     reorders, which take one advisory queue lock).
 *
 * Phase 16B creates NO service session, invoice, payment, receipt, commission
 * preview, or invoice trigger; `in_service`/`completed` are queue states only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            // Source (exactly one): walk-in XOR appointment. RESTRICT keeps history.
            $table->foreignId('walk_in_id')->nullable()->constrained('walk_ins')->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('preferred_personnel_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->string('assignment_mode', 24)->default('next_available');
            $table->string('status', 24)->default('waiting');
            $table->integer('position');
            $table->timestampTz('queued_at');
            $table->timestampTz('assigned_at')->nullable();
            $table->timestampTz('called_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->timestampTz('transferred_at')->nullable();
            $table->foreignId('transferred_from_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('transferred_to_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->text('transfer_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('preferred_personnel_override_reason')->nullable();
            $table->integer('estimated_wait_minutes')->default(0);
            $table->integer('estimated_wait_override_minutes')->nullable();
            $table->text('estimated_wait_override_reason')->nullable();
            $table->foreignId('estimated_wait_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status', 'position']);
            $table->index(['branch_id', 'queued_at']);
            $table->index(['client_id', 'queued_at']);
            $table->index(['service_id', 'status']);
            $table->index(['staff_profile_id', 'status', 'position']);
            $table->index('appointment_id');
            $table->index('walk_in_id');
            // One queue entry per source (NULLs allowed → only non-null enforced).
            $table->unique('walk_in_id', 'queue_entries_walk_in_id_unique');
            $table->unique('appointment_id', 'queue_entries_appointment_id_unique');
            // Composite-FK target for any future child.
            $table->unique(['id', 'merchant_id'], 'queue_entries_id_merchant_id_unique');
        });

        // Source XOR: exactly one of walk_in_id / appointment_id is set.
        DB::statement(
            'ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_source_xor_check
             CHECK ((walk_in_id IS NOT NULL) <> (appointment_id IS NOT NULL))'
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_status_check
             CHECK (status IN ('waiting','assigned','called','in_service','completed','transferred','cancelled','no_show'))"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_assignment_mode_check
             CHECK (assignment_mode IN ('next_available','manual','preferred_personnel'))"
        );
        DB::statement('ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_position_check CHECK (position > 0)');

        // Status ↔ timestamp coherence.
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_assigned_at_check
             CHECK (status NOT IN ('assigned','called','in_service','completed') OR assigned_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_called_at_check
             CHECK (status NOT IN ('called','in_service','completed') OR called_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_started_at_check
             CHECK (status NOT IN ('in_service','completed') OR started_at IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_completed_at_check
             CHECK ((completed_at IS NOT NULL) = (status = 'completed'))"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_cancelled_at_check
             CHECK ((cancelled_at IS NOT NULL) = (status = 'cancelled'))"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_cancellation_reason_check
             CHECK (status <> 'cancelled' OR cancellation_reason IS NOT NULL)"
        );
        DB::statement(
            "ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_no_show_at_check
             CHECK ((no_show_at IS NOT NULL) = (status = 'no_show'))"
        );
        // Transfer metadata coherence: a recorded transfer carries a reason. The
        // `transferred_from` personnel may be null when the source was an unassigned
        // `waiting` entry (no current personnel to transfer from).
        DB::statement(
            'ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_transfer_meta_check
             CHECK (transferred_at IS NULL OR transfer_reason IS NOT NULL)'
        );
        // Manual wait override value and reason appear together.
        DB::statement(
            'ALTER TABLE queue_entries ADD CONSTRAINT queue_entries_wait_override_check
             CHECK ((estimated_wait_override_minutes IS NULL) = (estimated_wait_override_reason IS NULL))'
        );

        // Active-ordered position uniqueness per branch (waiting/assigned/called).
        DB::statement(
            "CREATE UNIQUE INDEX queue_entries_branch_active_position_unique
             ON queue_entries (branch_id, position)
             WHERE status IN ('waiting','assigned','called')"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_walk_in_merchant_foreign
             FOREIGN KEY (walk_in_id, merchant_id)
             REFERENCES walk_ins (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_appointment_merchant_foreign
             FOREIGN KEY (appointment_id, merchant_id)
             REFERENCES appointments (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_client_merchant_foreign
             FOREIGN KEY (client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        // Each staff-profile column must link to the same merchant (literal
        // statements; no string interpolation — the Larastan raw-SQL guard).
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_staff_profile_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_preferred_personnel_merchant_foreign
             FOREIGN KEY (preferred_personnel_staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_transferred_from_merchant_foreign
             FOREIGN KEY (transferred_from_staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE queue_entries
             ADD CONSTRAINT queue_entries_transferred_to_merchant_foreign
             FOREIGN KEY (transferred_to_staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};
