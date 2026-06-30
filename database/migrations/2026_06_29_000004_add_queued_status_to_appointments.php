<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * appointments — Phase 16B forward-only expand: add the `queued` status (Plan
 * §25.2, §37, §80; the only new appointment transition is `checked_in → queued`).
 *
 * Expand-and-contract: the status CHECK is dropped and re-added with `queued`
 * appended. No existing state is removed and no row is touched (every existing
 * status stays valid). `queued` is non-reserving — the personnel double-booking
 * exclusion WHERE clause (scheduled|confirmed|checked_in) is intentionally
 * unchanged, so a queued appointment no longer reserves the interval.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_check');
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_status_check
             CHECK (status IN ('scheduled','confirmed','checked_in','rescheduled','cancelled','cancelled_with_reason','no_show','queued'))"
        );

        // A queued appointment was checked in first, so `checked_in_at` remains set:
        // widen the timestamp↔status coherence CHECK to include `queued`.
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_checked_in_at_check');
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_checked_in_at_check
             CHECK ((checked_in_at IS NOT NULL) = (status IN ('checked_in','cancelled_with_reason','queued')))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_checked_in_at_check');
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_checked_in_at_check
             CHECK ((checked_in_at IS NOT NULL) = (status IN ('checked_in','cancelled_with_reason')))"
        );

        DB::statement('ALTER TABLE appointments DROP CONSTRAINT appointments_status_check');
        DB::statement(
            "ALTER TABLE appointments ADD CONSTRAINT appointments_status_check
             CHECK (status IN ('scheduled','confirmed','checked_in','rescheduled','cancelled','cancelled_with_reason','no_show'))"
        );
    }
};
