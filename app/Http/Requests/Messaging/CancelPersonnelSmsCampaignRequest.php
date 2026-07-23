<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cancel an SMS campaign that has not yet been handed to the provider (Plan §64; Phase 21S).
 *
 * Takes an optional short free-text reason for the personnel member's own record. The reason is
 * NOT placed in the audit context: it is user-authored text that could name a client, and Plan
 * §24.5 keeps client identity out of audit payloads. Whether the cancellation is legal at all is
 * the state machine's decision, never this request's.
 *
 * AN ALLOWLIST, NOT A DENYLIST (see {@see PersonnelSmsCompositionRequest}): every other key is
 * rejected by {@see after()}, so no server-owned or contact field name is published as an
 * acceptable input in the OpenAPI contract.
 */
final class CancelPersonnelSmsCampaignRequest extends FormRequest
{
    /** The ONLY top-level key a client may send. */
    public const ACCEPTED = ['reason'];

    public function authorize(): bool
    {
        return true; // permission + billing gates run as route middleware; own scope in the policy
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'string', 'max:280']];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->all()) as $key) {
                    if (! in_array($key, self::ACCEPTED, true)) {
                        $validator->errors()->add(
                            (string) $key,
                            PersonnelSmsCompositionRequest::SERVER_OWNED_MESSAGE,
                        );
                    }
                }
            },
        ];
    }
}
