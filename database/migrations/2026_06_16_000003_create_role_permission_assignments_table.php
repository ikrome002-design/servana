<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * role_permission_assignments — default grants per role (Plan §10.3).
 *
 * The seeded §10.3 matrix: a row means "this role grants this permission key by
 * default". Per-membership exceptions are layered on top via
 * merchant_user_permission_overrides (deny beats grant). Unique (role, permission)
 * keeps the matrix idempotent under re-seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permission_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_assignments');
    }
};
