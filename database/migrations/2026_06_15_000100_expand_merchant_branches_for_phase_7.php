<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expand merchant_branches forward-only (Plan §7.2, Scope §3.3). Phase 6 created
 * the minimal branch identity/profile/status table; Phase 7 adds the lifecycle
 * columns needed for suspend/archive transitions and audit (CLAUDE.md guardrail
 * 12 — never edit a shipped migration; expand/contract only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_branches', function (Blueprint $table): void {
            $table->string('status_reason')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('status_reason');
            $table->timestamp('archived_at')->nullable()->after('suspended_at');
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('merchant_branches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['status_reason', 'suspended_at', 'archived_at']);
        });
    }
};
