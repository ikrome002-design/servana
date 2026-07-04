<?php

declare(strict_types=1);

namespace App\Http\Requests\Refunds;

use App\Domain\Payments\Enums\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * External refund request body (Plan §44; Gate D; Phase 18B). Identifies the validated
 * component (payment_record ULID), the positive minor-unit amount, the external refund
 * method, a mandatory reason, and (per method) an external reference. The remaining
 * refundable check, currency match, and reference encryption happen in the action; the
 * reference is never echoed back.
 */
final class RequestRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RefundPolicy::create + EnsurePermission are the boundary
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_record' => ['required', 'string', 'size:26'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            // Concrete refund methods only — split_payment is never a refund method.
            'method' => ['required', 'string', Rule::in(array_map(
                static fn (PaymentMethod $m): string => $m->value,
                array_filter(PaymentMethod::cases(), static fn (PaymentMethod $m): bool => $m !== PaymentMethod::SplitPayment),
            ))],
            'reason' => ['required', 'string', 'min:3', 'max:480'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:190'],
        ];
    }
}
