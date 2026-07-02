<?php

declare(strict_types=1);

namespace App\Http\Requests\Payments;

use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Merchant-client payment recording validation (Plan §41; Phase 18A). Accepts ONLY
 * the components the maker legitimately supplies: per component a method, a positive
 * integer minor-unit amount, an optional reference/evidence, an optional paid_at
 * (≤ now), and an optional currency (which must equal the invoice currency). All
 * authoritative values (merchant/branch/invoice/maker/group total/status/allocations/
 * validated amount) are derived server-side and are NEVER accepted from the body.
 * `split_payment` is a valid enum value but is rejected downstream as a component
 * method (Gate B).
 */
final class RecordPaymentGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // PaymentRecordingGroupPolicy + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'components' => ['required', 'array', 'min:1', 'max:20'],
            'components.*.method' => ['required', 'string', Rule::enum(PaymentMethod::class)],
            'components.*.amount_minor' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'components.*.reference' => ['nullable', 'string', 'max:190'],
            'components.*.paid_at' => ['nullable', 'date', 'before_or_equal:now'],
            'components.*.currency' => ['nullable', 'string', 'size:3'],
        ];
    }
}
