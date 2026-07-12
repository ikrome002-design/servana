<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Generate (or regenerate) a subscription invoice PDF (Plan §49; Phase 20B). Bodiless — the target
 * invoice is the ULID-bound route model. Generation is blocked in billing read-only states by the
 * route billing-mutable gate + the action's file-generation policy. Authorization is enforced by
 * route middleware.
 */
final class GenerateSubscriptionInvoicePdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
