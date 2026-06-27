<?php

declare(strict_types=1);

use App\Domain\Files\Models\UploadedFile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('files');

/*
 | Exact schema guard for uploaded_files / file_scan_events (Plan §13.13; Phase 10F).
 */

it('creates both file tables with the required columns', function (): void {
    expect(Schema::hasTable('uploaded_files'))->toBeTrue()
        ->and(Schema::hasTable('file_scan_events'))->toBeTrue();

    expect(Schema::hasColumns('uploaded_files', [
        'id', 'ulid', 'merchant_id', 'branch_id', 'owner_user_id', 'purpose',
        'storage_disk', 'quarantine_path', 'final_path', 'original_filename_encrypted',
        'safe_download_filename', 'declared_mime_type', 'detected_mime_type', 'extension',
        'size_bytes', 'sha256', 'scan_status', 'lifecycle_status', 'retention_until',
        'uploaded_by', 'available_at', 'revoked_at', 'created_at', 'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasColumns('file_scan_events', [
        'id', 'uploaded_file_id', 'scanner', 'engine_version', 'signature_version',
        'result', 'malware_name', 'error_code', 'scanned_at',
    ]))->toBeTrue();
});

it('does not add download_count to uploaded_files (belongs to finance_exports later)', function (): void {
    expect(Schema::hasColumn('uploaded_files', 'download_count'))->toBeFalse();
});

it('rejects an unknown purpose via the CHECK constraint', function (): void {
    DB::table('uploaded_files')->insert([
        'ulid' => (string) Str::ulid(), 'purpose' => 'not_a_purpose',
        'storage_disk' => 's3', 'quarantine_path' => 'q/x', 'original_filename_encrypted' => 'x',
        'safe_download_filename' => 'x', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        'scan_status' => 'pending', 'lifecycle_status' => 'quarantined',
    ]);
})->throws(QueryException::class);

it('rejects an unknown scan_status via the CHECK constraint', function (): void {
    // Raw insert bypasses the enum cast so the DB CHECK itself is exercised.
    DB::table('uploaded_files')->insert([
        'ulid' => (string) Str::ulid(), 'purpose' => 'merchant_logo',
        'storage_disk' => 's3', 'quarantine_path' => 'q/x', 'original_filename_encrypted' => 'x',
        'safe_download_filename' => 'x', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        'scan_status' => 'bogus', 'lifecycle_status' => 'quarantined',
    ]);
})->throws(QueryException::class);

it('rejects an available file that is not clean with a final path', function (): void {
    // lifecycle_status=available but scan_status=pending / final_path null → CHECK fails.
    DB::table('uploaded_files')->insert([
        'ulid' => (string) Str::ulid(), 'purpose' => 'merchant_logo',
        'storage_disk' => 's3', 'quarantine_path' => 'q/x', 'original_filename_encrypted' => 'x',
        'safe_download_filename' => 'x', 'size_bytes' => 1, 'sha256' => str_repeat('a', 64),
        'scan_status' => 'pending', 'lifecycle_status' => 'available',
    ]);
})->throws(QueryException::class);

it('rejects an unknown scan result on file_scan_events', function (): void {
    $file = UploadedFile::factory()->create();
    DB::table('file_scan_events')->insert([
        'uploaded_file_id' => $file->id, 'scanner' => 'clamav',
        'result' => 'maybe', 'scanned_at' => now(),
    ]);
})->throws(QueryException::class);

it('never serializes storage paths or the sha256', function (): void {
    $file = UploadedFile::factory()->create();
    $array = $file->toArray();

    expect($array)->not->toHaveKey('storage_disk')
        ->and($array)->not->toHaveKey('quarantine_path')
        ->and($array)->not->toHaveKey('final_path')
        ->and($array)->not->toHaveKey('sha256')
        ->and($array)->not->toHaveKey('original_filename_encrypted');
});

it('encrypts the original filename at rest', function (): void {
    $file = UploadedFile::factory()->create(['original_filename_encrypted' => 'secret-name.png']);

    $raw = DB::table('uploaded_files')->where('id', $file->id)->value('original_filename_encrypted');

    expect($raw)->not->toContain('secret-name.png')
        ->and($file->fresh()->original_filename_encrypted)->toBe('secret-name.png');
});
