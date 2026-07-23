<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sms\Clients;

use App\Domain\Messaging\Sms\Clients\Dto\SmsSendResult;

/**
 * The SMS provider adapter seam (Plan §64 "provider adapter interface"; Phase 21S).
 *
 * The Plan pins NO SMS provider, so the domain depends on this interface and never on a vendor.
 * Two implementations exist: {@see FakeSmsProviderClient} (bound whenever the integration is
 * disabled or incompletely configured, and unconditionally in `testing`) and
 * {@see HttpSmsProviderClient} (bound only when every credential is present, and fails closed
 * otherwise). Live-provider verification is tracked by REM-SMS-002.
 *
 * CONTACT PROTECTION: the destination number crosses this boundary exactly once, as the `$phone`
 * argument, and is read from the encrypted delivery snapshot immediately before the call. No
 * implementation may log it, store it, or return it — implementations return only a
 * {@see SmsSendResult}, whose diagnostic message is redacted before persistence.
 */
interface SmsProviderClientInterface
{
    /**
     * Submit one message to one destination.
     *
     * @param  string  $phone  the destination in E.164 — NEVER logged or persisted by the adapter
     * @param  string  $body  the message text — NEVER logged or persisted by the adapter
     * @param  string  $reference  an opaque Servana-side correlation reference (a recipient ULID-free id)
     */
    public function send(string $phone, string $body, string $reference): SmsSendResult;

    /** The provider slug recorded on every attempt row (bounded to 32 characters). */
    public function providerSlug(): string;
}
