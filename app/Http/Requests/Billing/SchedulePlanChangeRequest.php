<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Domain\Billing\Services\ResolveSetupPlanPrice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate a merchant no-proration next-cycle plan change (Plan §48; Phase 20B). Accepts the target
 * plan + price as PUBLIC ULIDs only; the plan/price existence, plan membership, and current
 * effectiveness are enforced by {@see ResolveSetupPlanPrice} (422 field
 * errors). `effective_at` is computed server-side (the current period end) — never client-supplied.
 * Authorization + billing-mutable gate are enforced by route middleware.
 */
final class SchedulePlanChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subscription_plan_ulid' => ['required', 'string', 'max:64'],
            'subscription_plan_price_ulid' => ['required', 'string', 'max:64'],
        ];
    }
}
