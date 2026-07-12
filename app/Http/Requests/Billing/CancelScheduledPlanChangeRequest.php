<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancel the pending scheduled plan change (Plan §48; Phase 20B). Bodiless — the target is the
 * merchant's single pending scheduled change, resolved server-side. Authorization + billing-mutable
 * gate are enforced by route middleware.
 */
final class CancelScheduledPlanChangeRequest extends FormRequest
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
