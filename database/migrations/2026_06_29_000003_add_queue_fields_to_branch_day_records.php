<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_day_records — Phase 16B queue operational configuration (Plan §37, §80).
 *
 * The Branch Day aggregate is the queue-config anchor (§80 names only `walk_ins` +
 * `queue_entries` — there is deliberately NO `queue_configurations` table). These
 * three forward-only columns hold the Branch-Manager-set queue settings:
 *
 *   - queue_is_open                  — meaningful only while status = 'open'; a
 *                                      paused/closed/not_opened day is effectively
 *                                      a closed queue regardless of this flag.
 *   - queue_capacity                 — positive when set; null = no explicit cap.
 *   - queue_default_assignment_mode  — next_available | manual (preferred_personnel
 *                                      is a per-client request, never a default).
 *
 * Forward-only: no shipped migration edited. Existing rows default to a closed
 * queue with next_available mode (safe).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_day_records', function (Blueprint $table): void {
            $table->boolean('queue_is_open')->default(false)->after('summary');
            $table->integer('queue_capacity')->nullable()->after('queue_is_open');
            $table->string('queue_default_assignment_mode', 24)->default('next_available')->after('queue_capacity');
        });

        DB::statement(
            'ALTER TABLE branch_day_records ADD CONSTRAINT branch_day_records_queue_capacity_check
             CHECK (queue_capacity IS NULL OR queue_capacity > 0)'
        );
        DB::statement(
            "ALTER TABLE branch_day_records ADD CONSTRAINT branch_day_records_queue_mode_check
             CHECK (queue_default_assignment_mode IN ('next_available','manual'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE branch_day_records DROP CONSTRAINT IF EXISTS branch_day_records_queue_capacity_check');
        DB::statement('ALTER TABLE branch_day_records DROP CONSTRAINT IF EXISTS branch_day_records_queue_mode_check');

        Schema::table('branch_day_records', function (Blueprint $table): void {
            $table->dropColumn(['queue_is_open', 'queue_capacity', 'queue_default_assignment_mode']);
        });
    }
};
