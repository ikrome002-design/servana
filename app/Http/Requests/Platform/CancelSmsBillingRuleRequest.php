<?php

declare(strict_types=1);

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validate withdrawal of a SCHEDULED SMS pricing rule (COR-UI08-001 §9; Phase UI-08).
 *
 * The reason is mandatory and NOT NULL at the database. Whether the rule may be cancelled at all
 * is a state question, answered by the action under a row lock and by the
 * `platform_sms_billing_rules_guard` trigger — never here.
 */
final class CancelSmsBillingRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ];
    }
}
