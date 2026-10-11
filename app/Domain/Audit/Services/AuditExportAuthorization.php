<?php

declare(strict_types=1);

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;

/** Live requester authority, shared by creation, generation and every file access boundary. */
final class AuditExportAuthorization
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /** @param array<string, mixed> $scope */
    public function allows(User $user, int $merchantId, int $branchId, array $scope): bool
    {
        if (! $user->fresh()?->isActive()
            || array_diff(array_keys($scope), ['domains', 'severities', 'date_from', 'date_to']) !== []) {
            return false;
        }

        $membership = MerchantUser::query()->where('merchant_id', $merchantId)
            ->where('user_id', $user->id)->first();
        $branch = MerchantBranch::query()->where('merchant_id', $merchantId)->find($branchId);
        if ($membership === null || ! $membership->isActive()
            || ! $membership->merchant?->status->isActive() || ! $branch?->isActive()
            || ! in_array($branchId, $membership->activeBranchIds(), true)) {
            return false;
        }

        $domains = array_key_exists('domains', $scope) ? $scope['domains'] : [AuditDomain::General->value];
        if (! is_array($domains)) {
            return false;
        }
        // An omitted or empty selection has always meant general, never all domains.
        $domains = $domains === [] ? [AuditDomain::General->value] : $domains;
        $keys = ['audit.export'];
        foreach ($domains as $domain) {
            if (! is_string($domain) || AuditDomain::tryFrom($domain) === null) {
                return false;
            }
            $keys[] = match (AuditDomain::from($domain)) {
                AuditDomain::General => 'audit.branch_events.view',
                AuditDomain::Finance => 'audit.finance.view',
                AuditDomain::Compensation => 'audit.compensation.view',
            };
        }

        return array_diff($keys, $this->permissions->forMembership($membership)) === [];
    }
}
