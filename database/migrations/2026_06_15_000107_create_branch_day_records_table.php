<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_day_records — open/pause/close/reopen state per branch business date
 * (Plan §7.2, Scope §3.3 Day Opening/Closing). An unclosed day is a branch
 * closure blocker (BranchClosureGuard). The day-close PDF/summary is a later
 * phase; `summary` is a JSON seam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_day_records', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->date('business_date');
            $table->string('status', 20)->default('not_opened');
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('reopened_reason')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'business_date']);
        });

        DB::statement(
            "ALTER TABLE branch_day_records ADD CONSTRAINT branch_day_records_status_check
             CHECK (status IN ('not_opened','open','paused','closed','reopened'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_day_records');
    }
};
