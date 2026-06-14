<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_status_histories — append-only merchant lifecycle trail
 * (Scope §5.1 "Merchant status history").
 *
 * Records every merchant status transition (pending_setup → active on setup
 * completion now; suspend/deactivate transitions land with the Super Admin
 * governance phase). Append-only by convention here; the tamper-evident
 * hash-chained audit_logs table is Phase 8/19 and will subsume richer events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['merchant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_status_histories');
    }
};
