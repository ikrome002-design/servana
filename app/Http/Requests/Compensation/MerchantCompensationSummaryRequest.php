<?php

declare(strict_types=1);

namespace App\Http\Requests\Compensation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Merchant-Administrator compensation summary read (Plan §62/§63; `merchant.compensation_summary.view`).
 * Merchant-scoped and read-only — the whole own merchant, currency-grouped. There are no client filters
 * that widen scope; a client-supplied `merchant_id`/`branch_id` is never honoured.
 */
final class MerchantCompensationSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'merchant_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
