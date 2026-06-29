<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * walk_ins — Front-Office-owned walk-in clients (Plan §13.7, §37; §80 Phase 16B).
 *
 * Branch-owned. The ULID is the public identifier; the internal bigint id is never
 * exposed. Creating a walk-in atomically attaches/creates a branch-scoped client
 * (via the existing Phase 15A client action — no duplicated logic), references an
 * active service, records the assignment intent + optional preferred request, and
 * (in the same transaction) spawns exactly one queue_entries row. Historical
 * walk-ins are retained (no hard-delete API).
 *
 * Structural invariants live in PostgreSQL: composite FKs force same-merchant
 * linkage to branch, client, service, and preferred personnel; CHECK constraints
 * enforce the three assignment modes and that a preferred request carries a
 * preferred personnel id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walk_ins', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            // Nullable in schema, but no ACTIVE queue entry exists without a valid
            // branch-scoped client (no anonymous queue entries).
            $table->foreignId('client_id')->nullable()->constrained('clients')->restrictOnDelete();
            $table->foreignId('service_id')->constrained('services')->restrictOnDelete();
            $table->string('assignment_mode', 24)->default('next_available');
            $table->foreignId('preferred_personnel_staff_profile_id')->nullable()->constrained('staff_profiles')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'created_at']);
            // Composite-FK target for queue_entries.walk_in_id.
            $table->unique(['id', 'merchant_id'], 'walk_ins_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE walk_ins ADD CONSTRAINT walk_ins_assignment_mode_check
             CHECK (assignment_mode IN ('next_available','manual','preferred_personnel'))"
        );
        DB::statement(
            "ALTER TABLE walk_ins ADD CONSTRAINT walk_ins_preferred_personnel_check
             CHECK (assignment_mode <> 'preferred_personnel' OR preferred_personnel_staff_profile_id IS NOT NULL)"
        );

        // Composite consistency: a walk-in's merchant can never disagree with its
        // branch, client, service, or preferred personnel (R5 pattern).
        DB::statement(
            'ALTER TABLE walk_ins
             ADD CONSTRAINT walk_ins_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE walk_ins
             ADD CONSTRAINT walk_ins_client_merchant_foreign
             FOREIGN KEY (client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE walk_ins
             ADD CONSTRAINT walk_ins_service_merchant_foreign
             FOREIGN KEY (service_id, merchant_id)
             REFERENCES services (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE walk_ins
             ADD CONSTRAINT walk_ins_preferred_personnel_merchant_foreign
             FOREIGN KEY (preferred_personnel_staff_profile_id, merchant_id)
             REFERENCES staff_profiles (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('walk_ins');
    }
};
