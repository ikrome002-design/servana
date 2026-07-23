<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Clients\Dto;

use App\Domain\Messaging\Sms\Enums\SmsProviderResultClass;
use App\Domain\Messaging\Sms\Support\SmsProviderPayloadRedactor;

/**
 * The normalized outcome of one provider send (Plan §64; Phase 21S).
 *
 * A provider adapter hands back ONLY this: a closed-set classification, an opaque provider message
 * id, a bounded provider code, a raw diagnostic message and an optional provider-reported cost.
 * It never returns the destination number, the message body or any credential — and
 * `$rawProviderMessage` is passed through {@see SmsProviderPayloadRedactor} before it is persisted
 * or logged, so even a provider that echoes the MSISDN cannot leak it into Servana.
 *
 * `$costMinor` is advisory. Servana's own tariff is authoritative for what is BILLED
 * (`sms_billing_entries`); a provider-reported cost is recorded on the recipient row for
 * reconciliation only.
 */
final readonly class SmsSendResult
{
    public function __construct(
        public SmsProviderResultClass $resultClass,
        /** Opaque provider-side message identifier, bounded to the column width. */
        public ?string $providerMessageId = null,
        /** Provider's own status/error code, bounded to the column width. */
        public ?string $providerCode = null,
        /** UNREDACTED provider diagnostic — never persisted or logged without the redactor. */
        public ?string $rawProviderMessage = null,
        /** Provider-reported cost in integer minor units (advisory; never billed directly). */
        public ?int $costMinor = null,
        public int $durationMs = 0,
    ) {}

    public static function accepted(string $providerMessageId, ?int $costMinor = null, int $durationMs = 0): self
    {
        return new self(
            SmsProviderResultClass::Accepted,
            $providerMessageId,
            'accepted',
            null,
            $costMinor,
            $durationMs,
        );
    }

    public static function failure(
        SmsProviderResultClass $resultClass,
        ?string $providerCode = null,
        ?string $rawProviderMessage = null,
        int $durationMs = 0,
    ): self {
        return new self($resultClass, null, $providerCode, $rawProviderMessage, null, $durationMs);
    }
}
