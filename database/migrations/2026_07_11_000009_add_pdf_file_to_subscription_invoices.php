<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive expand (Phase 20B; ADR-004): links a subscription invoice to its current generated PDF in
 * the Phase 10F private-file domain (Plan §49, §65). `file_id` points at the current `uploaded_files`
 * row (purpose `billing_invoice_pdf`); `pdf_version` counts regenerations (each regeneration writes a
 * new file version and revokes the prior one). Both are nullable/default — an invoice may have no PDF
 * yet. These are technical projection columns, NOT part of the immutable financial snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->foreignId('file_id')->nullable()->after('due_at')->constrained('uploaded_files')->restrictOnDelete();
            $table->integer('pdf_version')->default(0)->after('file_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('file_id');
            $table->dropColumn('pdf_version');
        });
    }
};
