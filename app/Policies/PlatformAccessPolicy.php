<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Internal platform-access authority (COR-UI08-001 §11; Plan §19.3; Phase UI-08).
 *
 * `platform.internal_access.view` reads the roster, invitations and access history;
 * `platform.internal_access.manage` performs every lifecycle mutation (MFA + a fresh
 * `platform_access_administration` step-up enforced on the route). Both are platform-scope and
 * granted to `super_admin` only — no merchant-side role holds either.
 *
 * The self-protection and lockout rules deliberately do NOT live here. They depend on the state of
 * the whole active set and must be evaluated under a row lock inside the mutating transaction, so
 * they belong to `PlatformAdministratorQuorum`; a policy answering "may this actor act at all?"
 * cannot safely answer "would this leave zero administrators?".
 */
final class PlatformAccessPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function view(User $user): bool
    {
        return $this->context->can('platform.internal_access.view');
    }

    public function manage(User $user): bool
    {
        return $this->context->can('platform.internal_access.manage');
    }
}
