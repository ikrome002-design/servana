<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Service catalogue authority (Plan §10.2/§19.3, §39). Branch Manager owns the
 * catalogue in its own branch scope: `service.view` (read), `service.create`,
 * `service.update`, `service.archive`. Cross-merchant rows are 404'd upstream; the
 * same-merchant + branch-scope guard is retained here as defence-in-depth. No
 * other role (Merchant Admin, Front Office, HR) may mutate the catalogue.
 */
final class ServicePolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('service.view');
    }

    public function view(User $user, Service $service): bool
    {
        return $this->context->can('service.view') && $this->ownsBranch($service);
    }

    public function create(User $user): bool
    {
        return $this->context->can('service.create');
    }

    public function update(User $user, Service $service): bool
    {
        return $this->context->can('service.update') && $this->ownsBranch($service);
    }

    public function archive(User $user, Service $service): bool
    {
        return $this->context->can('service.archive') && $this->ownsBranch($service);
    }

    private function ownsBranch(Service $service): bool
    {
        return $service->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($service->branch_id);
    }
}
