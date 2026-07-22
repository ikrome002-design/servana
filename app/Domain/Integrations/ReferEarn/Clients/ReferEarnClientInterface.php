<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Clients;

use App\Domain\Integrations\ReferEarn\Clients\Dto\AttributionConfirmation;
use App\Domain\Integrations\ReferEarn\Clients\Dto\EventDeliveryResult;
use App\Domain\Integrations\ReferEarn\Clients\Dto\ReferralCodeValidation;
use App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent;

/**
 * The ONLY seam through which Servana talks to Citrus R&E (Plan §10.1, §58A.2; ADR-013;
 * Phase 21R-A).
 *
 * Every domain action depends on this interface, never on an HTTP client, so CI can run the whole
 * integration against `FakeReferEarnClient` and never call a live partner (Plan §81 rule 21). The
 * three methods are the complete Phase 21R-A surface:
 *
 *   - `deliverEvent`  → POST {RE}/api/v1/integrations/products/{productCode}/events   (signed)
 *   - `validateReferralCode` → POST …/referral-codes/validate
 *   - `confirmAttribution`   → POST …/attributions/confirm  (idempotent by snapshot ULID)
 *
 * Implementations NEVER throw for a partner-level failure: an unreachable or unhappy R&E is a
 * normal, expected outcome that the caller must record and retry, not an exception that would
 * bubble into a merchant-facing request (Plan A-19: registration is never blocked by R&E).
 */
interface ReferEarnClientInterface
{
    /** @param  string  $body  the canonical JSON body EXACTLY as hashed at outbox insert */
    public function deliverEvent(ReOutboundEvent $event, string $body): EventDeliveryResult;

    public function validateReferralCode(string $normalizedCode, string $snapshotUlid): ReferralCodeValidation;

    public function confirmAttribution(string $normalizedCode, string $snapshotUlid, string $merchantPublicId): AttributionConfirmation;
}
