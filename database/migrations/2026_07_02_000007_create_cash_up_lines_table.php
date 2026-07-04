<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cash_up_lines — per-method line of a branch cash-up (Plan §45; Phase 18B).
 * Branch-owned via its cash-up parent (no ulid — child evidence row, §13.8 pattern).
 *
 * One line per concrete payment method per cash-up (never split_payment). expected_minor
 * is server-derived (Gate H); counted_minor is Branch Manager input; variance_minor =
 * counted - expected. Header totals equal Σ line totals. Money is integer minor units.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_up_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('cash_up_id')->constrained('branch_cash_ups')->cascadeOnDelete();
            $table->string('method', 24);
            $table->bigInteger('expected_minor')->default(0);
            $table->bigInteger('counted_minor')->default(0);
            $table->bigInteger('variance_minor')->default(0);
            $table->timestampsTz();

            $table->unique(['cash_up_id', 'method']);
            $table->index(['merchant_id', 'branch_id']);
            $table->index(['cash_up_id']);
        });

        DB::statement(
            "ALTER TABLE cash_up_lines ADD CONSTRAINT cash_up_lines_method_check
             CHECK (method IN ('cash','mpesa_offline','bank_transfer','card_terminal','voucher','other'))"
        );

        // Composite consistency (same-merchant linkage; R5 pattern).
        DB::statement(
            'ALTER TABLE cash_up_lines
             ADD CONSTRAINT cash_up_lines_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE cash_up_lines
             ADD CONSTRAINT cash_up_lines_cash_up_merchant_foreign
             FOREIGN KEY (cash_up_id, merchant_id)
             REFERENCES branch_cash_ups (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_up_lines');
    }
};
