<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validated pagination/filters for the payment-recording-group list (Plan §23, §41;
 * Phase 18A). Filters are indexed columns; sorts are allowlisted. Reads are
 * branch-scoped by the model. Finance sees the pending groups; Phase 22 cross-domain
 * search is out of scope.
 */
final class PaymentGroupIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'recorded_at', '-recorded_at'];

    public function authorize(): bool
    {
        return true; // PaymentRecordingGroupPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...ApiPagination::rules(),
            ...ApiPagination::sortRule(self::SORTS),
            'status' => ['sometimes', 'string', Rule::in(array_column(PaymentRecordingGroupStatus::cases(), 'value'))],
        ];
    }
}
