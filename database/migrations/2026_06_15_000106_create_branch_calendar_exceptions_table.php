<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_calendar_exceptions — date-specific closures / modified hours
 * (Plan §7.2, Scope §3.3). Emergency closures immediately block new queues and
 * appointments (enforced by the queue/scheduling phases that read this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_calendar_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 30);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'date', 'type']);
        });

        DB::statement(
            "ALTER TABLE branch_calendar_exceptions ADD CONSTRAINT branch_calendar_exceptions_type_check
             CHECK (type IN ('public_holiday','special_closure','emergency_closure','modified_hours'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_calendar_exceptions');
    }
};
