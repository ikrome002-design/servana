<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Files\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe uploaded-file representation (Plan §65; Phase 10F). ULID is the public id.
 * Storage disk/paths and the SHA-256 are NEVER exposed (also $hidden on the model).
 *
 * @mixin UploadedFile
 */
final class FileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'purpose' => $this->purpose->value,
            'scan_status' => $this->scan_status->value,
            'lifecycle_status' => $this->lifecycle_status->value,
            'safe_download_filename' => $this->safe_download_filename,
            'detected_mime_type' => $this->detected_mime_type,
            'size_bytes' => $this->size_bytes,
            'available_at' => $this->available_at === null ? null : $this->available_at->toIso8601String(),
            'can' => [
                'download' => $this->isDownloadable(),
            ],
        ];
    }
}
