<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Clients;

use App\Domain\Integrations\ReferEarn\Clients\Dto\AttributionConfirmation;
use App\Domain\Integrations\ReferEarn\Clients\Dto\EventDeliveryResult;
use App\Domain\Integrations\ReferEarn\Clients\Dto\ReferralCodeValidation;
use App\Domain\Integrations\ReferEarn\Enums\ReDeliveryResponseClass;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;
use App\Domain\Integrations\ReferEarn\Support\CitrusEventSigner;

/**
 * Deterministic in-process stand-in for Citrus R&E (Plan §17.1 "sandbox `FakeWalletClient`/
 * `FakeReferEarnClient` are used in CI so no real credential ever reaches test environments";
 * §81 rule 21; §80 Phase 21R-A entry-criteria fallback; Phase 21R-A).
 *
 * This is the bound implementation whenever the integration is disabled or unconfigured — which is
 * every test run and every local environment — so no test can accidentally reach a live partner.
 *
 * It performs NO network I/O and makes no wall-clock assumptions. Tests script outcomes with
 * `queueDeliveryResult()` / `queueValidation()` / `queueConfirmation()`; anything unscripted takes
 * the documented happy path. Every call is recorded so a test can assert what Servana *would* have
 * sent, including that a malformed code is never sent at all.
 */
final class FakeReferEarnClient implements ReferEarnClientInterface
{
    /** @var list<EventDeliveryResult> */
    private array $deliveryQueue = [];

    /** @var list<ReferralCodeValidation> */
    private array $validationQueue = [];

    /** @var list<AttributionConfirmation> */
    private array $confirmationQueue = [];

    /** @var list<array{event_id: string, event_type: string, body: string, content_sha256: string, headers: array<string, string>}> */
    public array $deliveredEvents = [];

    /** @var list<array{code: string, snapshot_ulid: string}> */
    public array $validatedCodes = [];

    /** @var list<array{code: string, snapshot_ulid: string, merchant_public_id: string}> */
    public array $confirmedAttributions = [];

    public function __construct(private readonly CitrusEventSigner $signer) {}

    public function queueDeliveryResult(EventDeliveryResult ...$results): void
    {
        foreach ($results as $result) {
            $this->deliveryQueue[] = $result;
        }
    }

    public function queueValidation(ReferralCodeValidation ...$validations): void
    {
        foreach ($validations as $validation) {
            $this->validationQueue[] = $validation;
        }
    }

    public function queueConfirmation(AttributionConfirmation ...$confirmations): void
    {
        foreach ($confirmations as $confirmation) {
            $this->confirmationQueue[] = $confirmation;
        }
    }

    public function deliverEvent(ReOutboundEvent $event, string $body): EventDeliveryResult
    {
        // Sign exactly as the HTTP client would, so signing defects (missing key, unpinned
        // algorithm, hash drift) surface in CI instead of only in production. When the signing
        // contract is unconfigured the headers are simply omitted from the record — the fake never
        // invents a credential.
        $headers = [];

        try {
            $headers = $this->signer->headers($event, 'POST', $this->eventsPath(), $body);
        } catch (\Throwable) {
            // Recorded as "unsigned" below; delivery-path tests that care assert on the headers.
        }

        $this->deliveredEvents[] = [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type->value,
            'body' => $body,
            'content_sha256' => hash('sha256', $body),
            'headers' => $headers,
        ];

        return array_shift($this->deliveryQueue)
            ?? new EventDeliveryResult(ReDeliveryResponseClass::Accepted, 202, null, '{"status":"accepted"}', 1);
    }

    public function validateReferralCode(string $normalizedCode, string $snapshotUlid): ReferralCodeValidation
    {
        $this->validatedCodes[] = ['code' => $normalizedCode, 'snapshot_ulid' => $snapshotUlid];

        return array_shift($this->validationQueue) ?? ReferralCodeValidation::valid();
    }

    public function confirmAttribution(string $normalizedCode, string $snapshotUlid, string $merchantPublicId): AttributionConfirmation
    {
        $this->confirmedAttributions[] = [
            'code' => $normalizedCode,
            'snapshot_ulid' => $snapshotUlid,
            'merchant_public_id' => $merchantPublicId,
        ];

        return array_shift($this->confirmationQueue)
            ?? AttributionConfirmation::confirmed('ATTR-'.substr($snapshotUlid, -10));
    }

    private function eventsPath(): string
    {
        return '/api/v1/integrations/products/'.(string) config('refer-earn.product_code', 'SRV').'/events';
    }
}
