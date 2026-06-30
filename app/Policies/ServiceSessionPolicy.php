<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Scheduling\Models\ServiceSession;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Service-session authority (Plan §10.2/§19, §25.2; Phase 16C). Front Office owns the
 * operational service-session lifecycle within its resolved merchant + assigned
 * branch (`service_session.view/start/complete/cancel`). Branch Manager has NO
 * session authority (no view, no mutation) — branch-level session visibility is not
 * granted in 16C (no authoritative screen requires it; Phase 19 owns matrix closure).
 * Personnel own-scope reads are enforced in the dedicated personnel controller via
 * `personnel.my_sessions.view`. Notes edits reuse `service_session.complete` (operate
 * an active session) — no new key (Phase 19 owns permission-matrix closure).
 */
final class ServiceSessionPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('service_session.view');
    }

    public function view(User $user, ServiceSession $session): bool
    {
        return $this->context->can('service_session.view') && $this->ownsBranch($session);
    }

    public function complete(User $user, ServiceSession $session): bool
    {
        return $this->context->can('service_session.complete') && $this->ownsBranch($session);
    }

    public function cancel(User $user, ServiceSession $session): bool
    {
        return $this->context->can('service_session.cancel') && $this->ownsBranch($session);
    }

    /** Editing operational service notes (reuses service_session.complete; no new key). */
    public function update(User $user, ServiceSession $session): bool
    {
        return $this->context->can('service_session.complete') && $this->ownsBranch($session);
    }

    private function ownsBranch(ServiceSession $session): bool
    {
        return $session->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($session->branch_id);
    }
}
