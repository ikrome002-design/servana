<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_flagged_events — the Audit-role review record over a single branch-scoped
 * audit_logs row (Plan §13.2, §80; Phase 19). Branch-owned; the ULID is the public
 * identifier.
 *
 * The flag NEVER mutates the audited source row: audit_logs remains append-only and
 * hash-chain protected (ADR-008), and audit_log_id is ON DELETE RESTRICT (audit rows
 * are never deleted anyway — a trigger blocks it). Only review metadata (status,
 * review_notes, assigned_to, resolved_by) changes, through the flagged-event state
 * machine. There is no destructive delete and no soft-delete that hides review history.
 *
 * Only branch-scoped audit rows (non-null merchant_id AND branch_id) are flaggable, so
 * the Audit role — which sees only its assigned branches — owns a coherent queue. See
 * docs/architecture/state-machines/audit-flagged-event.md and
 * docs/architecture/data-dictionary/audit-files-notifications.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_flagged_events', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('audit_log_id')->constrained('audit_logs')->restrictOnDelete();
            $table->string('status', 16)->default('open');
            $table->string('review_notes', 2000)->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
            $table->index(['audit_log_id']);
            $table->unique(['id', 'merchant_id'], 'audit_flagged_events_id_merchant_id_unique');
        });

        // Lifecycle CHECK mirrors AuditFlaggedEventStatus.
        DB::statement(
            "ALTER TABLE audit_flagged_events ADD CONSTRAINT audit_flagged_events_status_check
             CHECK (status IN ('open','under_review','resolved','dismissed','reopened'))"
        );
        // A terminal review outcome (resolved/dismissed) carries a resolver + notes.
        DB::statement(
            "ALTER TABLE audit_flagged_events ADD CONSTRAINT audit_flagged_events_resolution_check
             CHECK ((status IN ('resolved','dismissed')) = (resolved_by IS NOT NULL AND review_notes IS NOT NULL))"
        );
        // An event under review is assigned to a reviewer.
        DB::statement(
            "ALTER TABLE audit_flagged_events ADD CONSTRAINT audit_flagged_events_assignment_check
             CHECK (status <> 'under_review' OR assigned_to IS NOT NULL)"
        );

        // Composite tenant consistency: the flag's branch belongs to its merchant (R5).
        DB::statement(
            'ALTER TABLE audit_flagged_events
             ADD CONSTRAINT audit_flagged_events_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_flagged_events');
    }
};
