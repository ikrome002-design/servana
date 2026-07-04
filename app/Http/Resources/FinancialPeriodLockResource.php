<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\FinanceOps\Models\FinancialPeriodLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Financial period lock payload (Plan §46; ADR-0007; Phase 18B). Exposes the lock
 * ULID, scope (merchant/branch), period range, status, exception flag, and reopen
 * governance timestamps. It NEVER exposes a sequential id; user references are the
 * governance actor ULIDs only where loaded.
 *
 * @mixin FinancialPeriodLock
 */
final class FinancialPeriodLockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'scope' => $this->branch_id === null ? 'merchant' : 'branch',
            'branch' => $this->whenLoaded('branch', function (): ?array {
                /** @var MerchantBranch|null $branch */
                $branch = $this->branch;

                return $branch === null ? null : ['id' => $branch->ulid, 'name' => $branch->name];
            }),
            'period_start' => $this->period_start->toDateString(),
            'period_end' => $this->period_end->toDateString(),
            'status' => $this->status->value,
            'exception_required' => $this->exception_required,
            'reopen_reason' => $this->reopen_reason,
            'reopen_requested_at' => $this->reopen_requested_at?->toIso8601String(),
            'reopen_approved_at' => $this->reopen_approved_at?->toIso8601String(),
            'reopened_at' => $this->reopened_at?->toIso8601String(),
            'locked_at' => $this->locked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
