<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Files\Contracts\FileScanner;
use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\Jobs\FinalizeCleanFile;
use App\Domain\Files\Jobs\ScanUploadedFile;
use App\Domain\Files\Models\FileScanEvent;
use App\Domain\Files\Services\FakeFileScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('files');

// pngBytes() and quarantinedImage() are shared, parallel-safe helpers in tests/Pest.php.

it('promotes a clean image to available, re-encoded, with quarantine removed', function (): void {
    $file = quarantinedImage();
    app()->instance(FileScanner::class, FakeFileScanner::clean());

    ScanUploadedFile::dispatchSync($file->id);

    $file->refresh();
    $disk = Storage::disk($file->storage_disk);

    expect($file->scan_status)->toBe(FileScanStatus::Clean)
        ->and($file->lifecycle_status)->toBe(FileLifecycleStatus::Available)
        ->and($file->final_path)->not->toBeNull()
        ->and($file->isDownloadable())->toBeTrue();

    expect($disk->exists($file->final_path))->toBeTrue()
        ->and($disk->exists($file->quarantine_path))->toBeFalse();

    // Re-encoded final object is a valid, decodable image (metadata stripped on re-encode).
    expect(@getimagesizefromstring($disk->get($file->final_path)))->not->toBeFalse();

    expect(FileScanEvent::query()->where('uploaded_file_id', $file->id)->count())->toBe(1);
});

it('never makes an infected file available', function (): void {
    $file = quarantinedImage();
    app()->instance(FileScanner::class, FakeFileScanner::infected('Test-Sig'));

    ScanUploadedFile::dispatchSync($file->id);

    $file->refresh();
    expect($file->scan_status)->toBe(FileScanStatus::Infected)
        ->and($file->lifecycle_status)->toBe(FileLifecycleStatus::Quarantined)
        ->and($file->final_path)->toBeNull()
        ->and($file->isDownloadable())->toBeFalse();
});

it('marks the file scan_failed after exhausted retries and never finalizes', function (): void {
    $file = quarantinedImage();
    app()->instance(FileScanner::class, FakeFileScanner::error('engine_down'));

    // Simulate the queue's final failure path.
    $job = new ScanUploadedFile($file->id);
    try {
        $job->handle(app(FileScanner::class), app(AuditRecorder::class));
    } catch (Throwable $e) {
        $job->failed($e);
    }

    $file->refresh();
    expect($file->scan_status)->toBe(FileScanStatus::ScanFailed)
        ->and($file->isDownloadable())->toBeFalse();
});

it('is idempotent: a second scan does not re-scan or double-finalize', function (): void {
    $file = quarantinedImage();
    app()->instance(FileScanner::class, FakeFileScanner::clean());

    ScanUploadedFile::dispatchSync($file->id);
    ScanUploadedFile::dispatchSync($file->id); // second run: already clean → no-op

    expect(FileScanEvent::query()->where('uploaded_file_id', $file->id)->count())->toBe(1);

    // Finalize is also idempotent.
    FinalizeCleanFile::dispatchSync($file->id);
    $file->refresh();
    expect($file->lifecycle_status)->toBe(FileLifecycleStatus::Available);
});
