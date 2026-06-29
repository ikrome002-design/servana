<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * personnel_availability — HR-owned canonical schedule source (Plan §13.7, §80
 * Phase 15B; Corrections 16, 17).
 *
 * Branch-owned (no ulid; atomically replaced per staff member, not route-bound by
 * row). Recurring rows carry weekday (0=Sun … 6=Sat, same convention as
 * branch_operating_hours) with null date; exception rows carry an exact business
 * date with null weekday. Intervals are half-open [start_time, end_time) in branch
 * business time (Africa/Nairobi). `available=false` rows subtract breaks /
 * unavailable time from an otherwise-scheduled period.
 *
 * Structural invariants live in PostgreSQL, not only the app:
 *   - composite FKs force SAME-MERCHANT linkage to branch + staff_profiles
 *     (same-branch consistency is set by the domain action from the staff profile);
 *   - CHECK constraints enforce type↔weekday/date polarity, weekday range, and
 *     start < end (which also forbids cross-midnight / zero-length rows);
 *   - GiST exclusion constraints (btree_gist) reject SAME-POLARITY interval
 *     overlaps per staff member (opposite-polarity breaks-over-shifts are allowed,
 *     resolved deterministically by AvailabilityResolver).
 *
 * `personnel.availability.manage` (HR). Phase 16A consumes this via the shared
 * PersonnelSchedulingValidator; 15B creates no appointment table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // btree_gist supplies GiST opclasses for the scalar equality columns
        // (smallint/date/bool) used alongside the numrange overlap operator.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('personnel_availability', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('staff_profile_id')->constrained('staff_profiles')->restrictOnDelete();
            $table->smallInteger('weekday')->nullable(); // recurring only; 0=Sunday … 6=Saturday
            $table->date('date')->nullable();            // exception only; exact business date
            $table->time('start_time');
            $table->time('end_time');
            $table->string('type', 12); // 'recurring' | 'exception'
            $table->boolean('available');
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['staff_profile_id', 'weekday']);
            $table->index(['staff_profile_id', 'date']);
        });

        // Structural CHECKs (mirror the AvailabilityType enum + half-open semantics).
        DB::statement(
            "ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_type_check
             CHECK (type IN ('recurring', 'exception'))"
        );
        DB::statement(
            "ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_polarity_check
             CHECK (
                 (type = 'recurring' AND weekday IS NOT NULL AND date IS NULL)
                 OR (type = 'exception' AND date IS NOT NULL AND weekday IS NULL)
             )"
        );
        DB::statement(
            'ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_weekday_range_check
             CHECK (weekday IS NULL OR (weekday BETWEEN 0 AND 6))'
        );
        // start < end forbids zero-length AND cross-midnight rows (no end <= start).
        DB::statement(
            'ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_interval_check
             CHECK (start_time < end_time)'
        );

        // Same-polarity overlap prevention. Time-of-day → immutable half-open
        // numrange; `available` with `=` keeps polarities independent so a break
        // (available=false) may overlap a working interval (available=true).
        DB::statement(
            "ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_recurring_no_overlap
             EXCLUDE USING gist (
                 staff_profile_id WITH =,
                 weekday WITH =,
                 available WITH =,
                 numrange(
                     extract(epoch from start_time)::numeric,
                     extract(epoch from end_time)::numeric,
                     '[)'
                 ) WITH &&
             ) WHERE (type = 'recurring')"
        );
        DB::statement(
            "ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_exception_no_overlap
             EXCLUDE USING gist (
                 staff_profile_id WITH =,
                 date WITH =,
                 available WITH =,
                 numrange(
                     extract(epoch from start_time)::numeric,
                     extract(epoch from end_time)::numeric,
                     '[)'
                 ) WITH &&
             ) WHERE (type = 'exception')"
        );

        // Composite consistency: a row's merchant can never disagree with its
        // branch or its staff profile (R5 pattern; staff RESTRICT keeps history).
        DB::statement(
            'ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE personnel_availability
             ADD CONSTRAINT personnel_availability_staff_merchant_foreign
             FOREIGN KEY (staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_availability');
    }
};
