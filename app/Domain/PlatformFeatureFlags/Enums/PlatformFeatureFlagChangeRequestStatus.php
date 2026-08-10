<?php

declare(strict_types=1);

namespace App\Domain\PlatformFeatureFlags\Enums;

/**
 * Feature-flag change-request status (COR-UI08-001 §12.3; Phase UI-08). Lifecycle:
 * docs/architecture/state-machines/platform-feature-flag-change-request.md. Mirrors the
 * `platform_feature_flag_change_requests.status` CHECK exactly.
 */
enum PlatformFeatureFlagChangeRequestStatus: string
{
    /** Awaiting a SECOND administrator. At most one per flag. */
    case Pending = 'pending';

    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Applied = 'applied';

    /** Application aborted; the flag was left unchanged, with a mandatory failure reason. */
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return $this !== self::Pending && $this !== self::Approved;
    }

    /** @return list<string> the exact vocabulary, for schema-contract assertions */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
