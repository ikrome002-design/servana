<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditExport;
use App\Domain\Branches\Models\MerchantBranch;
use App\Http\Resources\Concerns\HasCapabilities;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Audit export payload (Plan §13.5, §80; guardrail §6.4; Phase 19; ADR-010). Exposes the
 * export ULID, branch, status, reason, a SAFE scope summary, row count, and download
 * accounting. It NEVER exposes `file_id`, the storage path, the signed URL/signature, the
 * file bytes, an internal id, or a raw failure detail (only the redacted code/message).
 * The raw `scope_json` is reduced to non-sensitive counts/flags.
 *
 * @mixin AuditExport
 */
final class AuditExportResource extends JsonResource
{
    use HasCapabilities;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'branch' => $this->whenLoaded('branch', function (): ?array {
                /** @var MerchantBranch|null $branch */
                $branch = $this->branch;

                return $branch === null ? null : ['id' => $branch->ulid, 'name' => $branch->name];
            }),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'scope' => $this->safeScope(),
            'row_count' => $this->row_count,
            'download_count' => $this->download_count,
            'requested_at' => $this->requested_at === null ? null : $this->requested_at->toIso8601String(),
            'generated_at' => $this->generated_at === null ? null : $this->generated_at->toIso8601String(),
            'expires_at' => $this->expires_at === null ? null : $this->expires_at->toIso8601String(),
            'first_downloaded_at' => $this->first_downloaded_at === null ? null : $this->first_downloaded_at->toIso8601String(),
            'last_downloaded_at' => $this->last_downloaded_at === null ? null : $this->last_downloaded_at->toIso8601String(),
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message_redacted,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'can' => $this->capabilities($request, [
                'view' => 'view',
                'download' => 'download',
                'revoke' => 'revoke',
            ]),
        ];
    }

    /**
     * A non-sensitive view of the requested scope — the allowlisted classifications only,
     * never raw values that could reveal targeted subjects.
     *
     * @return array<string, mixed>
     */
    private function safeScope(): array
    {
        $scope = $this->scope_json;

        return [
            'domains' => array_values(array_filter(
                (array) ($scope['domains'] ?? []),
                'is_string',
            )),
            'severities' => array_values(array_filter(
                (array) ($scope['severities'] ?? []),
                'is_string',
            )),
            'has_date_from' => isset($scope['date_from']),
            'has_date_to' => isset($scope['date_to']),
        ];
    }
}
