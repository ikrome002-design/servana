<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Platform feature-flag authority (COR-UI08-001 section 12.2; Plan section 19.3; Phase UI-08).
 *
 * REUSES the existing platform settings permissions: `platform.settings.view` reads the catalogue
 * and history, `platform.settings.update` proposes, decides and pauses. COR-UI08-001 authorizes NO
 * feature-flag-specific permission key, and none exists.
 *
 * Note what this policy does NOT do: it never answers "is this flag on?". Rollout evaluation is
 * PlatformFeatureFlagEvaluator's job and is entirely separate from authorization, because a flag
 * must never be able to grant a capability the permission system denies.
 */
final class PlatformFeatureFlagPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->can('platform.settings.view');
    }

    public function update(User $user): bool
    {
        return $this->context->can('platform.settings.update');
    }
}
