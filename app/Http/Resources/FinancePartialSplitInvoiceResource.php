<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Enums\Currency;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** Server-owned balance waterfall and nested group/component view for Finance. */
/** @mixin Invoice */
final class FinancePartialSplitInvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);
        $pendingStatuses = PaymentRecordingGroupStatus::activePendingStatuses();
        $pendingMinor = $this->paymentGroups
            ->filter(static fn (PaymentRecordingGroup $group): bool => in_array($group->status, $pendingStatuses, true))
            ->sum('total_amount_minor');

        return [
            'invoice' => [
                'id' => $this->ulid,
                'number' => $this->invoice_number,
                'status' => $this->status->value,
                'created_at' => $this->created_at?->toIso8601String(),
            ],
            'balance' => [
                'total' => Money::ofMinor($this->total_minor, $currency)->toArray(),
                'validated' => Money::ofMinor($this->validated_paid_minor, $currency)->toArray(),
                'pending_recorded' => Money::ofMinor((int) $pendingMinor, $currency)->toArray(),
                'remaining' => Money::ofMinor(max(0, $this->balanceMinor()), $currency)->toArray(),
            ],
            'group_count' => $this->paymentGroups->count(),
            'has_multiple_groups' => $this->paymentGroups->count() > 1,
            'has_multi_method_group' => $this->paymentGroups->contains(
                static fn (PaymentRecordingGroup $group): bool => $group->records->count() > 1,
            ),
            'groups' => $this->paymentGroups->sortBy('recorded_at')->values()->map(function (PaymentRecordingGroup $group): array {
                $groupCurrency = Currency::from($group->currency);
                $maker = $group->maker;
                if ($maker === null) {
                    throw new LogicException('A payment recording group must retain its maker.');
                }

                return [
                    'id' => $group->ulid,
                    'status' => $group->status->value,
                    'total' => Money::ofMinor($group->total_amount_minor, $groupCurrency)->toArray(),
                    'recorded_at' => $group->recorded_at?->toIso8601String(),
                    'maker' => $maker->name,
                    'receipt' => $group->validatedEvent?->receipt === null ? null : [
                        'id' => $group->validatedEvent->receipt->ulid,
                        'number' => $group->validatedEvent->receipt->receipt_number,
                    ],
                    'components' => $group->records->map(static function (PaymentRecord $record): array {
                        $recordCurrency = Currency::from($record->currency);

                        return [
                            'id' => $record->ulid,
                            'method' => $record->method->value,
                            'status' => $record->status->value,
                            'amount' => Money::ofMinor($record->amount_minor, $recordCurrency)->toArray(),
                            'reference_masked' => $record->maskedReference(),
                            'duplicate_risk' => $record->referenceChecks->contains(
                                static fn (PaymentReferenceCheck $check): bool => $check->result === PaymentReferenceCheckResult::DuplicateSuspected
                                    && $check->override_by === null,
                            ),
                        ];
                    })->values()->all(),
                ];
            })->values()->all(),
        ];
    }
}
