<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

use App\Domain\Files\Enums\FileLifecycleStatus;
use App\Domain\Files\Enums\FilePurpose;
use App\Domain\Files\Enums\FileScanStatus;
use App\Domain\Files\FilePurposeRegistry;
use App\Domain\Files\Models\UploadedFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes SERVER-GENERATED files into the Phase 10F private file domain (Plan §65;
 * Phase 18B). Generated files (receipt PDFs, finance-export CSVs) are trusted server
 * output — they skip the upload/quarantine/scan pipeline but land in the SAME private
 * storage with the SAME UploadedFile record shape, so downloads go through the
 * authorized FileAccessService signed-link boundary. Bytes are written to the private
 * `files.disk`; the storage path and hash never leave the server (they are `$hidden`).
 *
 * This is the only sanctioned way to create a generated file — controllers/domain code
 * never write files directly.
 */
final class GeneratedFileWriter
{
    /**
     * Create an available UploadedFile from in-memory bytes.
     */
    public function write(
        FilePurpose $purpose,
        string $bytes,
        string $downloadFilename,
        string $mimeType,
        string $extension,
        ?int $merchantId,
        ?int $branchId,
        ?int $uploadedBy,
        ?int $ownerUserId = null,
    ): UploadedFile {
        $definition = FilePurposeRegistry::for($purpose);
        $disk = (string) config('files.disk');

        $finalPath = sprintf(
            'generated/%s/%d/%s.%s',
            $purpose->value,
            $merchantId ?? 0,
            (string) Str::ulid(),
            $extension,
        );

        Storage::disk($disk)->put($finalPath, $bytes);

        $file = new UploadedFile;
        $file->fill([
            'merchant_id' => $merchantId,
            'branch_id' => $branchId,
            // Own-scope purposes (e.g. earnings statements) authorise download by owner_user_id;
            // other generated files (receipts, exports) pass null and authorise by tenant/permission.
            'owner_user_id' => $ownerUserId,
            'purpose' => $purpose->value,
            'storage_disk' => $disk,
            // Generated files skip quarantine; the quarantine path equals the final path.
            'quarantine_path' => $finalPath,
            'original_filename_encrypted' => $downloadFilename,
            'safe_download_filename' => $downloadFilename,
            'declared_mime_type' => $mimeType,
            'detected_mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'retention_until' => $definition->retentionDays !== null
                ? CarbonImmutable::now()->addDays($definition->retentionDays)
                : null,
            'uploaded_by' => $uploadedBy,
        ]);
        // Trusted generated content: mark clean, then promote to available.
        $file->scan_status = FileScanStatus::Clean;
        $file->lifecycle_status = FileLifecycleStatus::Quarantined;
        $file->save();

        $file->markAvailable($finalPath);

        return $file;
    }
}
