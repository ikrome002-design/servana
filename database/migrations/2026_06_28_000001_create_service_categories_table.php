<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * service_categories — Branch-Manager catalogue grouping (Plan §13.7, §39; 15A).
 *
 * Branch-owned: merchant_id + branch_id with a composite FK to
 * merchant_branches(id, merchant_id) so the row's merchant can never disagree
 * with its branch. Branch-scoped active-name uniqueness via a partial unique
 * index; archival is soft (archived_at) and a referenced category is never hard
 * deleted (services.category_id is RESTRICT). UNIQUE (id, merchant_id) makes this
 * table a composite-FK target for services.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('name', 120);
            $table->integer('sort_order')->default(0);
            $table->timestampTz('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->unique(['id', 'merchant_id'], 'service_categories_id_merchant_id_unique');
        });

        // Branch-scoped active-name uniqueness (Plan §13.7).
        DB::statement(
            'CREATE UNIQUE INDEX service_categories_branch_active_name_unique
             ON service_categories (branch_id, name)
             WHERE archived_at IS NULL'
        );

        // DB-level tenant/branch consistency (ADR-002): merchant_id must match the parent branch.
        DB::statement(
            'ALTER TABLE service_categories
             ADD CONSTRAINT service_categories_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('service_categories');
    }
};
