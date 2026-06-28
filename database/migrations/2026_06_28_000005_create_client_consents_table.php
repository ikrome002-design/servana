<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * client_consents — SMS consent state capture (Plan §13.7, §35; 15A/21S).
 *
 * Branch-owned. ONE current state per (client, channel) via a unique constraint;
 * changing consent updates the row + changed_at (no history table in 15A).
 * Channel restricted to 'sms'; state to opted_in/opted_out. NO SMS delivery in
 * 15A (Phase 21S). Composite FKs force same-tenant linkage to the branch and the
 * client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('channel', 8)->default('sms');
            $table->string('state', 12);
            $table->string('source', 40);
            $table->timestampTz('changed_at')->useCurrent();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['client_id', 'channel'], 'client_consents_client_channel_unique');
            $table->index(['merchant_id', 'branch_id']);
            $table->index('branch_id');
        });

        DB::statement("ALTER TABLE client_consents ADD CONSTRAINT client_consents_channel_check CHECK (channel IN ('sms'))");
        DB::statement("ALTER TABLE client_consents ADD CONSTRAINT client_consents_state_check CHECK (state IN ('opted_in','opted_out'))");

        DB::statement(
            'ALTER TABLE client_consents
             ADD CONSTRAINT client_consents_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
        DB::statement(
            'ALTER TABLE client_consents
             ADD CONSTRAINT client_consents_client_merchant_foreign
             FOREIGN KEY (client_id, merchant_id)
             REFERENCES clients (id, merchant_id)
             ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('client_consents');
    }
};
