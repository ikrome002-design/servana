<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Catalogue\Models\ServiceCategory;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Service-category authority (Plan §39). Branch Manager manages categories with
 * the same catalogue keys as services: `service.view` (read), `service.create`
 * (create), `service.update` (rename/reorder/archive). Branch-scoped.
 */
final class ServiceCategoryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('service.view');
    }

    public function view(User $user, ServiceCategory $category): bool
    {
        return $this->context->can('service.view') && $this->ownsBranch($category);
    }

    public function create(User $user): bool
    {
        return $this->context->can('service.create');
    }

    public function update(User $user, ServiceCategory $category): bool
    {
        return $this->context->can('service.update') && $this->ownsBranch($category);
    }

    private function ownsBranch(ServiceCategory $category): bool
    {
        return $category->merchant_id === $this->context->merchantId()
            && $this->context->canAccessBranch($category->branch_id);
    }
}
