<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * appointments — Front-Office-owned booked appointments (Plan §13.7, §36, §25.2;
 * §80 Phase 16A; Corrections 16, 17, 22).
 *
 * Branch-owned. The ULID is the public identifier + searchable reference (no
 * human-readable number scheme exists); the internal bigint id is never exposed.
 * `starts_at`/`ends_at` are timestamptz; `ends_at` is computed from the selected
 * service's `duration_minutes` snapshot at scheduling time (a later service-duration
 * change never mutates an existing appointment). Personnel may be unassigned at
 * creation; assignment/transfer/confirmation run the Phase 15B
 * PersonnelSchedulingValidator (this migration creates no logic — see the actions).
 *
 * Structural invariants live in PostgreSQL, not only the app:
 *   - composite FKs force SAME-MERCHANT linkage to branch, client, service, and
 *     both staff profiles (same-branch consistency is asserted by the actions +
 *     the scheduling validator);
 *   - CHECK constraints enforce the seven Phase-16A states, `starts_at < ends_at`,
 *     and timestamp↔status coherence;
 *   - a btree_gist EXCLUDE constraint rejects two overlapping ACTIVE appointments
 *     for the SAME assigned personnel member (half-open [starts_at, ends_at);
 *     back-to-back allowed; different personnel allowed) — the final concurrency
 *     authority for double-booking, mapped to 409 appointment_schedule_conflict.
 *
 * `queued`/`in_service` states and queue/session links are deferred to 16B/16C by
 * expand-and-contract; this table carries no queue/session/invoice columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        // btree_gist supplies the GiST opclass for the scalar `=` (bigint
        // assigned_personnel_staff_profile_id) used alongside the tstzrange overlap.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->foreignId('preferred_personnel_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('assigned_personnel_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status', 24)->default('scheduled');
            $table->text('cancellation_reason')->nullable();
            $table->text('transfer_reason')->nullable();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'starts_at', 'status']);
            $table->index(['client_id', 'starts_at']);
            $table->index(['assigned_personnel_staff_profile_id', 'starts_at']);
            $table->index(['preferred_personnel_staff_profile_id', 'starts_at']);
            // Composite-FK target for any future child (queue_entries.appointment_id, 16B).
            $table->unique(['id', 'merchant_id'], 'appointments_id_merchant_id_unique');
        });

        // Phase-16A state set (queued/in_service deferred 16B/16C; expand-and-contract).
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_status_check
             CHECK (status IN ('scheduled','confirmed','checked_in','rescheduled','cancelled','cancelled_with_reason','no_show'))"
        );
        // Half-open interval; forbids zero-length / inverted ranges.
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_interval_check CHECK (starts_at < ends_at)');
        // Timestamp ↔ status coherence.
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_checked_in_at_check
             CHECK ((checked_in_at IS NOT NULL) = (status IN ('checked_in','cancelled_with_reason')))"
        );
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_no_show_at_check
             CHECK ((no_show_at IS NOT NULL) = (status = 'no_show'))"
        );
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_cancelled_at_check
             CHECK ((cancelled_at IS NOT NULL) = (status IN ('cancelled','cancelled_with_reason')))"
        );
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_cancel_reason_check
             CHECK (status <> 'cancelled_with_reason' OR cancellation_reason IS NOT NULL)"
        );

        // Double-booking prevention: no two overlapping ACTIVE appointments for the
        // same assigned personnel member. Half-open tstzrange so back-to-back
        // appointments do NOT overlap; partial WHERE so unassigned + terminal/
        // non-reserving appointments never block. The database is the final
        // concurrency authority (application validation is best-effort).
        DB::statement(
            "ALTER TABLE appointments
             ADD CONSTRAINT appointments_personnel_no_overlap
             EXCLUDE USING gist (
                 assigned_personnel_staff_profile_id WITH =,
                 tstzrange(starts_at, ends_at, '[)') WITH &&
             )
             WHERE (
                 assigned_personnel_staff_profile_id IS NOT NULL
                 AND status IN ('scheduled','confirmed','checked_in')
             )"
        );

        // Composite consistency: an appointment's merchant can never disagree with
        // its branch, client, service, or assigned/preferred personnel (R5 pattern;
        // client/service/staff RESTRICT keeps history).
        DB::statement(
            'ALTER TABLE appointments
             ADD CONSTRAINT appointments_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE appointments
             ADD CONSTRAINT appointments_client_merchant_foreign
             FOREIGN KEY (client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE appointments
             ADD CONSTRAINT appointments_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE appointments
             ADD CONSTRAINT appointments_assigned_personnel_merchant_foreign
             FOREIGN KEY (assigned_personnel_staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE appointments
             ADD CONSTRAINT appointments_preferred_personnel_merchant_foreign
             FOREIGN KEY (preferred_personnel_staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
