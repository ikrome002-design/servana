<?php

declare(strict_types=1);

use App\Domain\Files\Contracts\FileScanner;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Jobs\FinalizeCleanFile;
use App\Domain\Files\Jobs\ScanUploadedFile;
use App\Domain\Files\Models\FileScanEvent;
use App\Domain\Files\Services\FakeFileScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('files');

it('scans exactly once even if the scan job runs repeatedly', function (): void {
    $file = quarantinedImage();
    app()->instance(FileScanner::class, FakeFileScanner::clean());

    ScanUploadedFile::dispatchSync($file->id);
    ScanUploadedFile::dispatchSync($file->id);
    ScanUploadedFile::dispatchSync($file->id);

    expect(FileScanEvent::query()->where('uploaded_file_id', $file->id)->count())->toBe(1);
});

it('finalizes exactly once even if the finalize job runs repeatedly', function (): void {
    $file = quarantinedImage();
    app()->instance(FileScanner::class, FakeFileScanner::clean());
    ScanUploadedFile::dispatchSync($file->id); // → clean + finalized

    $file->refresh();
    $finalPath = $file->final_path;

    // Re-running finalize is a no-op (already available); the final object stands.
    FinalizeCleanFile::dispatchSync($file->id);
    FinalizeCleanFile::dispatchSync($file->id);

    $file->refresh();
    expect($file->lifecycle_status)->toBe(FileLifecycleStatus::Available)
        ->and($file->final_path)->toBe($finalPath)
        ->and(Storage::disk($file->storage_disk)->exists($finalPath))->toBeTrue();
});
