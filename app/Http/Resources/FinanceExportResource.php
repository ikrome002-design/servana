<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\FinanceOps\Models\FinanceExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Finance export payload (Plan §65, §67; guardrail §6.4; Phase 18B). Exposes the export
 * ULID, type, scope, status, reason, row count, and download accounting. It NEVER
 * exposes the storage path, the signed URL/signature, the file bytes, an internal id,
 * or a raw failure detail (only the redacted failure code/message).
 *
 * @mixin FinanceExport
 */
final class FinanceExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'export_type' => $this->export_type->value,
            'scope' => $this->branch_id === null ? 'merchant' : 'branch',
            'branch' => $this->whenLoaded('branch', function (): ?array {
                /** @var MerchantBranch|null $branch */
                $branch = $this->branch;

                return $branch === null ? null : ['id' => $branch->ulid, 'name' => $branch->name];
            }),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'row_count' => $this->row_count,
            'download_count' => $this->download_count,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'first_downloaded_at' => $this->first_downloaded_at?->toIso8601String(),
            'last_downloaded_at' => $this->last_downloaded_at?->toIso8601String(),
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message_redacted,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
