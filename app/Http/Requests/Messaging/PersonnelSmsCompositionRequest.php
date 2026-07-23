<?php

declare(strict_types=1);

namespace App\Http\Requests\Messaging;

use App\Domain\Messaging\Sms\Support\SmsBatchLimiter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Shared contract for the two SMS composition endpoints — preview and draft creation
 * (Plan §64, §11.2; Phase 21S).
 *
 * ONLY TWO FIELDS ARE ACCEPTED: the selected client ULIDs and the message body. Everything else the
 * campaign will eventually carry — merchant, branch, staff profile, status, recipient count,
 * currency, estimated/final cost, consent snapshot, provider ids, delivery status, timestamps — is
 * SERVER-OWNED, and so is every contact field: a phone never travels INTO this API either.
 *
 * AN ALLOWLIST, NOT A DENYLIST. {@see ACCEPTED} names what may be sent, and {@see after()} rejects
 * EVERY other key with the canonical 422 field envelope. That is stronger than enumerating
 * forbidden names (an unanticipated field is rejected too), and — the reason it is written this
 * way — it keeps server-owned names out of the PUBLISHED CONTRACT: the OpenAPI generator derives
 * request properties from `rules()` keys, so a denylist would have advertised `phone_encrypted` as
 * an acceptable input on an endpoint that rejects it (ADR-010; caught by
 * `SmsContactExportProhibitionTest`).
 *
 * Preview and create share this base so the two can never drift apart — a field that becomes
 * rejectable on one is rejectable on the other in the same commit.
 *
 * The batch cap comes from configuration and is re-enforced in the domain by
 * {@see SmsBatchLimiter}: these rules produce the friendly 422 field error, the limiter is the
 * boundary.
 */
abstract class PersonnelSmsCompositionRequest extends FormRequest
{
    /**
     * The ONLY top-level keys a client may send. Anything else is rejected by {@see after()}.
     *
     * @var list<string>
     */
    public const ACCEPTED = ['client_ulids', 'message_body'];

    public const SERVER_OWNED_MESSAGE = 'This field is set by Servana and cannot be supplied.';

    public function authorize(): bool
    {
        return true; // permission + entitlement + billing gates run as route middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $limiter = app(SmsBatchLimiter::class);

        return [
            'client_ulids' => ['required', 'array', 'min:1', 'max:'.$limiter->maxRecipients()],
            'client_ulids.*' => ['required', 'string', 'size:26'],
            'message_body' => ['required', 'string', 'min:1', 'max:'.$limiter->maxMessageCharacters()],
        ];
    }

    /**
     * Reject every key outside the allowlist, with the same message and field envelope a
     * `prohibited` rule would have produced.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                foreach (array_keys($this->all()) as $key) {
                    if (! in_array($key, static::ACCEPTED, true)) {
                        $validator->errors()->add((string) $key, self::SERVER_OWNED_MESSAGE);
                    }
                }
            },
        ];
    }

    /** @return list<string> */
    public function clientUlids(): array
    {
        /** @var list<string> $ulids */
        $ulids = array_values($this->validated()['client_ulids']);

        return $ulids;
    }

    public function messageBody(): string
    {
        return (string) $this->validated()['message_body'];
    }
}
