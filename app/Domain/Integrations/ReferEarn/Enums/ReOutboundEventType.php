<?php

declare(strict_types=1);

namespace App\Domain\Integrations\ReferEarn\Enums;

/**
 * Outbound event catalogue owned by Phase 21R-A (Plan §58B.1; Phase 21R-A rows only).
 *
 * Mirrors the `re_outbound_events.event_type` DB CHECK. The `subscription.*` and `activity.*` rows of
 * the §58B.1 catalogue are **Phase 21R-B** and are deliberately absent here AND from the database
 * constraint, so 21R-B cannot be built by accident.
 */
enum ReOutboundEventType: string
{
    case MerchantRegistrationStarted = 'merchant.registration_started';
    case MerchantAdminCreated = 'merchant.admin_created';
    case MerchantSetupCompleted = 'merchant.setup_completed';
    case MerchantStatusChanged = 'merchant.status_changed';
    case MerchantIdentitySnapshotChanged = 'merchant.identity_snapshot_changed';

    /** Committed JSON Schema backing this type's payload (docs/integrations/refer-earn/schemas). */
    public function schemaFile(): string
    {
        return $this->value.'.v'.$this->version().'.json';
    }

    /** Payload/schema version; `X-Citrus-Event-Version` and the payload `schema_version`. */
    public function version(): string
    {
        return '1';
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
