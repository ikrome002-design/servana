<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * invoice_number_sequences — gap-free per-merchant invoice numbering counter
 * (Plan §13.8/§13.15 Correction 3, §40; §80 Phase 17). Tenant-owned
 * (merchant_id, NO branch_id — numbering is merchant-wide; Scope "Branch Invoice
 * and Receipt Numbering Rules": merchant-wide uniqueness with an optional branch
 * prefix, e.g. KIL-INV-000124).
 *
 * The counter is consumed ONLY inside a successful invoice-finalization
 * transaction: the row is locked FOR UPDATE, `next_value` is returned, then
 * incremented. A rolled-back finalization consumes no number (the increment rolls
 * back). Numbers are never reused — a voided invoice keeps its number. The next
 * number is NEVER derived from MAX(invoice_number)+1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->restrictOnDelete();
            $table->string('scope', 40);
            $table->bigInteger('next_value')->default(1);
            // Optional merchant-level override of the literal `INV` segment; the
            // branch prefix itself is derived from merchant_branches.code at format
            // time (merchant-wide counter keeps numbers unique across branches).
            $table->string('prefix', 20)->nullable();
            $table->timestampsTz();

            $table->unique(['merchant_id', 'scope']);
        });

        DB::statement(
            "ALTER TABLE invoice_number_sequences ADD CONSTRAINT invoice_number_sequences_scope_check
             CHECK (scope IN ('merchant_client_invoice'))"
        );
        DB::statement(
            'ALTER TABLE invoice_number_sequences ADD CONSTRAINT invoice_number_sequences_next_value_check
             CHECK (next_value > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_sequences');
    }
};
