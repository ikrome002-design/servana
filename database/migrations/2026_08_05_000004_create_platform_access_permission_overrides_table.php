<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * platform_access_permission_overrides — DENY-ONLY per-administrator permission overrides
 * (COR-UI08-001 §11; Phase UI-08). Canonical DDL:
 * docs/architecture/data-dictionary/platform-governance.md.
 *
 * WHY NOT `merchant_user_permission_overrides` (proven, not assumed): that table is keyed on a NOT
 * NULL `merchant_user_id` FK. A platform administrator has no `merchant_users` row by design, so it
 * cannot address them at all — and `PermissionResolver::forPlatformStaff()` correspondingly applied
 * no overrides whatsoever before this phase.
 *
 * DENY-ONLY IS THE WHOLE POINT. `effect` is CHECK-constrained to 'deny', so an override can only
 * ever SUBTRACT from the super_admin default grants. "Increase my own access" is therefore not
 * merely refused by policy — it cannot be represented as a row. A trigger additionally rejects any
 * permission whose `permissions.category <> 'platform'`, so a merchant key cannot be written here
 * even if every layer above the database were bypassed.
 *
 * Forward-only (ADR-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_access_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('platform_access_membership_id')->constrained('platform_access_memberships')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->restrictOnDelete();
            $table->string('effect', 8)->default('deny');
            $table->string('reason', 500);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(
                ['platform_access_membership_id', 'permission_id'],
                'platform_access_permission_overrides_unique',
            );
        });

        // `grant` is deliberately unrepresentable at launch.
        DB::statement(
            "ALTER TABLE platform_access_permission_overrides ADD CONSTRAINT platform_access_permission_overrides_effect_check
             CHECK (effect IN ('deny'))"
        );

        // The structural half of "no merchant permission may be referenced here". `permissions.category`
        // carries the registry scope, so a non-platform key is rejected at write time.
        DB::statement(
            "CREATE OR REPLACE FUNCTION platform_access_permission_overrides_scope_guard() RETURNS trigger AS $$
             DECLARE
                 permission_scope text;
             BEGIN
                 SELECT category INTO permission_scope FROM permissions WHERE id = NEW.permission_id;

                 IF (permission_scope IS DISTINCT FROM 'platform') THEN
                     RAISE EXCEPTION 'a platform access override may only reference a platform-scope permission';
                 END IF;

                 RETURN NEW;
             END;
             $$ LANGUAGE plpgsql"
        );
        DB::statement(
            'CREATE TRIGGER platform_access_permission_overrides_scope_guard
             BEFORE INSERT OR UPDATE ON platform_access_permission_overrides
             FOR EACH ROW EXECUTE FUNCTION platform_access_permission_overrides_scope_guard()'
        );
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS platform_access_permission_overrides_scope_guard ON platform_access_permission_overrides');
        DB::statement('DROP FUNCTION IF EXISTS platform_access_permission_overrides_scope_guard()');
        Schema::dropIfExists('platform_access_permission_overrides');
    }
};
