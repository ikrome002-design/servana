<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;

/**
 * Finance export authority (Plan §65, §67; Phase 18B). Finance owns
 * `finance_export.create` (request + revoke) and `finance_export.download` (download).
 * Every per-row check enforces same-merchant ownership (foreign-tenant ULIDs 404).
 */
final class FinanceExportPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('finance_export.create') || $this->context->can('finance_export.download');
    }

    public function view(User $user, FinanceExport $export): bool
    {
        return $this->viewAny($user) && $this->ownsMerchant($export);
    }

    public function create(User $user): bool
    {
        return $this->context->can('finance_export.create');
    }

    public function download(User $user, FinanceExport $export): bool
    {
        return $this->context->can('finance_export.download') && $this->ownsMerchant($export);
    }

    public function revoke(User $user, FinanceExport $export): bool
    {
        return $this->context->can('finance_export.create') && $this->ownsMerchant($export);
    }

    private function ownsMerchant(FinanceExport $export): bool
    {
        return $export->merchant_id === $this->context->merchantId();
    }
}
