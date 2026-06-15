<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * merchant_user_permission_overrides — per-membership grant/deny (Plan §10.3).
 *
 * Layered on top of role defaults during resolution: an `effect = grant` adds a
 * grantable (◐) capability; an `effect = deny` removes a capability. Deny ALWAYS
 * beats grant (Plan §10.3). One override per (membership, permission) — a partial
 * is not needed since a member holds at most one stance per key. `granted_by`
 * records the actor for the audit trail (changes are audited high-severity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_user_permission_overrides', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_user_id')->constrained('merchant_users')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->string('effect', 10);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['merchant_user_id', 'permission_id']);
            $table->index('permission_id');
        });

        DB::statement(
            "ALTER TABLE merchant_user_permission_overrides ADD CONSTRAINT merchant_user_permission_overrides_effect_check
             CHECK (effect IN ('grant','deny'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_user_permission_overrides');
    }
};
