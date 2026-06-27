<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-scan malware-scan log for uploaded files (Plan §13.13, §65; Phase 10F,
 * REM-FILE-001). Canonical DDL: docs/architecture/data-dictionary/files-and-media.md.
 *
 * Scope is inherited through uploaded_file_id; this table is NEVER directly
 * route-bound. One row per actual scan; scanner raw responses / payloads are never
 * stored (only the mapped result + safe metadata). Forward-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_scan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('uploaded_file_id')->constrained('uploaded_files')->restrictOnDelete();

            $table->string('scanner', 40);
            $table->string('engine_version', 60)->nullable();
            $table->string('signature_version', 60)->nullable();
            $table->string('result', 20);
            $table->string('malware_name', 191)->nullable();
            $table->string('error_code', 60)->nullable();
            $table->timestamp('scanned_at');
        });

        DB::statement("ALTER TABLE file_scan_events ADD CONSTRAINT file_scan_events_result_check CHECK (result IN ('clean','infected','error'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('file_scan_events');
    }
};
