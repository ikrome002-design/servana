<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Domain\Messaging\Sms\Actions\ConfirmSmsCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Explicit confirmation of an SMS campaign (Plan §64: *"personnel confirms explicitly"*; Phase 21S).
 *
 * The ONLY accepted field is `acknowledged`, which must be literally true. Nothing about the
 * campaign can be changed at confirm time — not the recipients, not the message, and above all not
 * the cost: the client's estimate is never accepted as authoritative, and
 * {@see ConfirmSmsCampaign} re-prices from the recipients that survive its own revalidation.
 *
 * AN ALLOWLIST, NOT A DENYLIST (see {@see PersonnelSmsCompositionRequest} for the full reasoning):
 * every other key is rejected by {@see after()}, so an unanticipated field is refused too and no
 * server-owned or contact field name is published as an acceptable input in the OpenAPI contract.
 */
final class ConfirmPersonnelSmsCampaignRequest extends FormRequest
{
    /** The ONLY top-level key a client may send. */
    public const ACCEPTED = ['acknowledged'];

    public function authorize(): bool
    {
        return true; // permission + entitlement + billing gates run as route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['acknowledged' => ['required', 'accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['acknowledged.accepted' => 'Confirm that you want to send this message before it is queued.'];
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
