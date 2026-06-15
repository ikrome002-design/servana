<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * audit_logs — append-only, hash-chained audit trail (Plan §7.5, §22.2).
 *
 * Phase 8 introduces the REAL schema (auditing must exist before the financial
 * phases) with a minimal recorder; full event coverage + chain verification
 * mature in Phase 19. Append-only is enforced at the database: a trigger raises
 * on any UPDATE or DELETE (guardrail §6.5 — audit rows are immutable). Each row
 * carries `previous_hash`/`hash` so the chain is tamper-evident from day one;
 * Phase 19 adds the verifier + masking + the complete §5.18 event set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->char('ulid', 26)->unique();
            // Nullable for platform-scope events (no merchant) and for actions by
            // platform staff acting outside a single tenant.
            $table->foreignId('merchant_id')->nullable()->constrained('merchants')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 150)->nullable();
            $table->string('action', 100);
            $table->string('severity', 20)->default('info');
            // Optional subject of the event (polymorphic, by class + bigint id).
            $table->string('auditable_type', 191)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->char('correlation_id', 26)->nullable();
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();

            $table->index(['merchant_id', 'created_at']);
            $table->index('action');
            $table->index(['auditable_type', 'auditable_id']);
        });

        DB::statement(
            "ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_severity_check
             CHECK (severity IN ('info','notice','warning','high','critical'))"
        );

        // Append-only: block UPDATE and DELETE at the database (guardrail §6.5).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs is append-only (% blocked)', TG_OP;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_block_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs;');
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_block_mutation();');
        Schema::dropIfExists('audit_logs');
    }
};
