<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand (ADR-004): links a paid personnel payout item to its generated earnings-statement file
 * (Plan §63, §65; Phase 20H Increment 4). `earnings_statement_file_id` is a nullable FK to
 * `uploaded_files` (the 10F private file domain). It is deliberately NOT part of the Increment-2
 * item freeze guard's ROW() comparison, so `GenerateEarningsStatement` may set it once on a PAID item
 * while every snapshot column stays frozen. Once set it is never rewritten (idempotent + immutable
 * statement). No backfill. Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnel_payout_items', function (Blueprint $table): void {
            $table->foreignId('earnings_statement_file_id')
                ->nullable()
                ->after('status')
                ->constrained('uploaded_files')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personnel_payout_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('earnings_statement_file_id');
        });
    }
};
