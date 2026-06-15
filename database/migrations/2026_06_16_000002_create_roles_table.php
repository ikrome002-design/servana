<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * roles — the fixed role catalogue (Plan §10.1).
 *
 * The seven merchant account-type roles + the platform `super_admin`. `key`
 * mirrors merchant_users.role (merchant scope) or 'super_admin' (platform). This
 * is a registry table seeded by PermissionSeeder; merchant_users.role remains the
 * per-membership role assignment — roles are NOT recreated per merchant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('key', 50)->unique();
            $table->string('name', 100);
            $table->string('scope', 20);
            $table->boolean('is_read_only')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE roles ADD CONSTRAINT roles_scope_check
             CHECK (scope IN ('merchant','platform'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
