<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * services — Branch-Manager service catalogue (Plan §13.7, §39; 15A).
 *
 * Branch-owned. Money is integer minor units (price_minor) with a CHECKed
 * uppercase ISO-4217 currency (KES default); duration in whole minutes.
 * `preferred_personnel_fee_minor` is the LEGACY fixed seam (§13.7/§39): retained
 * read-only during expand-and-contract, never editable by Branch Manager, no API
 * field; the platform fee rule lives in preferred_personnel_fee_rules (Phase
 * 20A) and is NOT created here. Status is a backed enum + DB CHECK; archival goes
 * through a domain action. Composite FKs force same-tenant linkage to the branch
 * and the category. UNIQUE (id, merchant_id) makes services a composite-FK target
 * for service_personnel_eligibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->bigInteger('price_minor');
            $table->char('currency', 3)->default('KES');
            $table->integer('duration_minutes');
            // LEGACY fixed seam (§13.7/§39): internal, non-editable, superseded by rules (Phase 20A).
            $table->bigInteger('preferred_personnel_fee_minor')->nullable();
            $table->string('status', 16)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index(['branch_id', 'status']);
            $table->unique(['id', 'merchant_id'], 'services_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE services ADD CONSTRAINT services_status_check
             CHECK (status IN ('active','archived'))"
        );
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_price_minor_check CHECK (price_minor >= 0)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_duration_check CHECK (duration_minutes > 0)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_currency_check CHECK (char_length(currency) = 3)');
        DB::statement(
            'ALTER TABLE services
             ADD CONSTRAINT services_preferred_fee_check
             CHECK (preferred_personnel_fee_minor IS NULL OR preferred_personnel_fee_minor >= 0)'
        );

        DB::statement(
            'ALTER TABLE services
             ADD CONSTRAINT services_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        // Service and its category share a tenant (composite FK to the category's (id, merchant_id)).
        DB::statement(
            'ALTER TABLE services
             ADD CONSTRAINT services_category_merchant_foreign
             FOREIGN KEY (category_id, merchant_id)
             REFERENCES service_categories (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
