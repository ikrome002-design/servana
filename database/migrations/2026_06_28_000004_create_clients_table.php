<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * clients — Front-Office client records (Plan §13.7, §35; guardrail §6.4; 15A).
 *
 * Branch-owned. Contact is ENCRYPTED at rest (phone_encrypted / email_encrypted,
 * AES-256-GCM via the `encrypted` cast) and displayed MASKED (phone_last_four).
 * Phone is searchable / duplicate-checked through a keyed HMAC blind index
 * (`phone_index`) — NOT a plaintext index, NOT reversible ciphertext — never
 * returned by the API or logged. Same-branch duplicate prevention is a PARTIAL
 * UNIQUE index on (branch_id, phone_index) WHERE status='active': one active
 * client per branch + normalized phone; the same phone may exist in another
 * branch/merchant. No hard delete (status active/archived). UNIQUE (id,
 * merchant_id) makes clients a composite-FK target for client_consents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('merchant_branches')->cascadeOnDelete();
            $table->string('full_name', 160);
            $table->text('phone_encrypted');
            $table->char('phone_index', 64);
            $table->char('phone_last_four', 4);
            $table->text('email_encrypted')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->index(['merchant_id', 'branch_id']);
            $table->index('branch_id');
            $table->index(['branch_id', 'status']);
            $table->unique(['id', 'merchant_id'], 'clients_id_merchant_id_unique');
        });

        DB::statement(
            "ALTER TABLE clients ADD CONSTRAINT clients_status_check
             CHECK (status IN ('active','archived'))"
        );
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_phone_last_four_check CHECK (char_length(phone_last_four) = 4)');

        // Same-branch active-phone duplicate prevention over the blind index (Plan §35).
        DB::statement(
            "CREATE UNIQUE INDEX clients_branch_active_phone_index_unique
             ON clients (branch_id, phone_index)
             WHERE status = 'active'"
        );

        DB::statement(
            'ALTER TABLE clients
             ADD CONSTRAINT clients_branch_merchant_foreign
             FOREIGN KEY (branch_id, merchant_id)
             REFERENCES merchant_branches (id, merchant_id)
             ON DELETE CASCADE ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
