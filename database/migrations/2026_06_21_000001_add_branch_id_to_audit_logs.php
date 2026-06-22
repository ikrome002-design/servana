<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R2 — add branch_id to audit_logs (Plan §70, ADR-008; forward-only expand).
 *
 * Branch-owned audit events (branch lifecycle, day open/close, branch assignment,
 * branch-scoped staff lifecycle) must be filterable by branch so a branch-scoped
 * Audit user reads ONLY their assigned branch (Scope §4.8). Merchant- and
 * platform-level events keep branch_id NULL. Nullable + nullOnDelete: deleting a
 * branch must never mutate/delete an immutable audit row (the append-only trigger
 * would block a cascade delete anyway), so the FK nulls the reference instead.
 *
 * branch_id is part of the canonical hash chain (AuditChainHasher) for rows
 * written after this migration. No production deployment exists yet (Phase 25),
 * so there are no historical rows to backfill; migrate:fresh rebuilds cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('merchant_id')
                ->constrained('merchant_branches')
                ->nullOnDelete();

            $table->index(['merchant_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['merchant_id', 'branch_id']);
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
