<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Files\Models\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Phase 20H earnings-statement file metadata (Plan §63, §65; §H11). Exposes only SAFE 10F file metadata
 * — the public ULID, the safe download filename, byte size, mime type, and the generated timestamp. The
 * storage disk/path and SHA-256 are `$hidden` on the model and never serialize. The actual bytes are
 * fetched through the authorized 10F download endpoints (own-scope by `owner_user_id`); a short-lived
 * signed link is attached alongside this resource by the controller.
 *
 * @mixin UploadedFile
 */
final class EarningsStatementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'filename' => $this->safe_download_filename,
            'mime_type' => $this->detected_mime_type,
            'size_bytes' => (int) $this->size_bytes,
            'generated_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
