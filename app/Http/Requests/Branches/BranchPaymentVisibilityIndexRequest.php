<?php

declare(strict_types=1);

namespace App\Http\Requests\Branches;

use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Http\Api\ApiPagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filters for Branch Manager's narrow, read-only payment-record visibility projection. */
final class BranchPaymentVisibilityIndexRequest extends FormRequest
{
    public const SORTS = ['created_at', '-created_at', 'recorded_at', '-recorded_at'];

    public function authorize(): bool
    {
        return true;
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
