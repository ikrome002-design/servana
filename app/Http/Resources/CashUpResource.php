<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Branches\Models\BranchCashUp;
use App\Domain\Branches\Models\CashUpLine;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cash-up payload (Plan §45; guardrail §6.4; Phase 18B). Exposes the cash-up ULID,
 * business date, status, server-derived expected + counted + variance totals (integer
 * minor units), and per-method lines. It NEVER exposes a sequential id. Currency is
 * KES (the platform currency); all amounts are integer minor units.
 *
 * @mixin BranchCashUp
 */
final class CashUpResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::KES;

        return [
            'id' => $this->ulid,
            'business_date' => $this->business_date === null ? null : $this->business_date->toDateString(),
            'status' => $this->status->value,
            'expected' => Money::ofMinor($this->expected_minor, $currency)->toArray(),
            'counted' => Money::ofMinor($this->counted_minor, $currency)->toArray(),
            'variance' => Money::ofMinor($this->variance_minor, $currency)->toArray(),
            'expected_minor' => $this->expected_minor,
            'counted_minor' => $this->counted_minor,
            'variance_minor' => $this->variance_minor,
            'submitted_at' => $this->submitted_at === null ? null : $this->submitted_at->toIso8601String(),
            'approved_at' => $this->approved_at === null ? null : $this->approved_at->toIso8601String(),
            'reviewed_at' => $this->reviewed_at === null ? null : $this->reviewed_at->toIso8601String(),
            'review_note' => $this->review_note,
            'created_at' => $this->created_at === null ? null : $this->created_at->toIso8601String(),
            'lines' => $this->whenLoaded('lines', fn (): array => $this->lines
                ->map(static fn (CashUpLine $line): array => [
                    'method' => $line->method->value,
                    'expected_minor' => $line->expected_minor,
                    'counted_minor' => $line->counted_minor,
                    'variance_minor' => $line->variance_minor,
                ])->values()->all()),
        ];
    }
}
