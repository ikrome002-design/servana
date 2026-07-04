<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * receipt_number_sequences — per-merchant gap-free receipt numbering counter
 * (Plan §13.15 Correction; §43; Phase 18B). Tenant-owned (no branch_id — numbering is
 * merchant-wide, mirroring invoice_number_sequences).
 *
 * The next receipt number is allocated under SELECT … FOR UPDATE inside the
 * receipt-issuance transaction; MAX(receipt_number)+1 is NEVER used. Numbers are
 * gap-free on committed issuance and never reused; a rolled-back issuance consumes no
 * number (the increment rolls back with the transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->string('scope', 16)->default('receipt');
            $table->bigInteger('next_value')->default(1);
            $table->string('prefix', 16)->nullable();
            $table->timestampsTz();

            $table->unique(['merchant_id', 'scope']);
        });

        DB::statement(
            "ALTER TABLE receipt_number_sequences ADD CONSTRAINT receipt_number_sequences_scope_check
             CHECK (scope IN ('receipt'))"
        );
        DB::statement(
            'ALTER TABLE receipt_number_sequences ADD CONSTRAINT receipt_number_sequences_next_value_check
             CHECK (next_value >= 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('receipt_number_sequences');
    }
};
