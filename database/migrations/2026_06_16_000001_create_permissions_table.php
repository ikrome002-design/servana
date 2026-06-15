<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * permissions — the capability registry (Plan §10.3).
 *
 * One row per registry key (e.g. `branches.create`, `payments.validate`). The
 * canonical catalogue + role defaults live in PermissionRegistry; PermissionSeeder
 * upserts this table from it. `is_mutating` lets the resolver strip write keys
 * from the read-only `audit` role (Plan §10.2). `category` groups keys for the
 * HR permission-preview UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('key', 100)->unique();
            $table->string('category', 50);
            $table->string('description', 255);
            $table->boolean('is_mutating')->default(true);
            $table->timestamps();

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
