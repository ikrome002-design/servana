<?php

declare(strict_types=1);

use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Models\UploadedFile;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Receipts\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('payments', 'receipts');

it('generates the receipt PDF through the private file domain and flips the receipt to ready', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    Storage::fake((string) config('files.disk'));

    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);
    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    // Durable, but not downloadable until the outbox job runs.
    $receipt = Receipt::query()->firstOrFail();
    expect($receipt->file_generation_status)->toBe('pending')
        ->and($receipt->file_id)->toBeNull();

    // Run the outbox job (as the queue would).
    (new GenerateReceiptPdf($receipt->id, $receipt->merchant_id, $receipt->branch_id))->handle();

    $receipt->refresh();
    expect($receipt->file_generation_status)->toBe('ready')
        ->and($receipt->file_id)->not->toBeNull();

    // The generated file is a private, available receipt_pdf in the file domain.
    $file = UploadedFile::query()->findOrFail($receipt->file_id);
    expect($file->purpose)->toBe(FilePurpose::ReceiptPdf)
        ->and($file->lifecycle_status)->toBe(FileLifecycleStatus::Available)
        ->and($file->isDownloadable())->toBeTrue();
    Storage::disk((string) config('files.disk'))->assertExists($file->final_path);

    // The bytes are a real PDF.
    expect(Storage::disk((string) config('files.disk'))->get($file->final_path))->toStartWith('%PDF-');
});

it('is idempotent: re-running the generation job on a ready receipt does not regenerate', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    Storage::fake((string) config('files.disk'));

    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);
    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();

    $receipt = Receipt::query()->firstOrFail();
    $job = new GenerateReceiptPdf($receipt->id, $receipt->merchant_id, $receipt->branch_id);
    $job->handle();
    $firstFileId = $receipt->refresh()->file_id;

    // A second run is a no-op — same file, no duplicate generated file record.
    $job->handle();
    expect($receipt->refresh()->file_id)->toBe($firstFileId)
        ->and(UploadedFile::query()->where('purpose', FilePurpose::ReceiptPdf->value)->count())->toBe(1);
});
