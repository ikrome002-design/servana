<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Jobs\DeleteExpiredQuarantineFile;
use App\Domain\Files\Jobs\ExpireSignedExport;
use App\Domain\Files\Jobs\VerifyOrphanedFileRecords;
use App\Domain\Files\Models\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('files');

it('expires generated files past their retention window and removes the object', function (): void {
    [, $merchant] = activeAdmin();
    $file = availableFile($merchant->id, FilePurpose::FinanceExport);
    $file->forceFill(['retention_until' => now()->subDay()])->save();

    $expired = app(ExpireSignedExport::class)->handle(app(AuditRecorder::class));

    $file->refresh();
    expect($expired)->toBe(1)
        ->and($file->lifecycle_status)->toBe(FileLifecycleStatus::Expired)
        ->and(Storage::disk($file->storage_disk)->exists((string) $file->final_path))->toBeFalse();
});

it('deletes a quarantine object that never finalized within the window', function (): void {
    [, $merchant] = activeAdmin();
    $disk = (string) config('files.disk');
    Storage::fake($disk);

    $file = UploadedFile::factory()->create([
        'merchant_id' => $merchant->id, 'purpose' => 'merchant_logo', 'storage_disk' => $disk,
        'quarantine_path' => 'quarantine/'.Str::ulid(),
    ]);
    Storage::disk($disk)->put($file->quarantine_path, 'stuck');
    $file->forceFill(['created_at' => now()->subDays(3)])->save();

    $deleted = app(DeleteExpiredQuarantineFile::class)->handle(app(AuditRecorder::class));

    $file->refresh();
    expect($deleted)->toBe(1)
        ->and($file->lifecycle_status)->toBe(FileLifecycleStatus::Deleted)
        ->and(Storage::disk($disk)->exists($file->quarantine_path))->toBeFalse();
});

it('reports orphaned records without deleting unknown objects', function (): void {
    [, $merchant] = activeAdmin();
    $disk = (string) config('files.disk');
    Storage::fake($disk);

    // Available row whose final object is missing → reported, not deleted.
    UploadedFile::factory()->available()->create([
        'merchant_id' => $merchant->id, 'purpose' => 'merchant_logo', 'storage_disk' => $disk,
    ]);

    $report = app(VerifyOrphanedFileRecords::class)->handle();

    expect($report['available_missing'])->toBe(1);
});
