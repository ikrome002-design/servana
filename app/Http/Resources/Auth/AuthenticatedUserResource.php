<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Onboarding\Services\FirstTimeSetupProgress;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\MerchantMembershipResource;
use App\Http\Resources\MerchantResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Authenticated bootstrap payload for the SPA authStore (Plan §6.2, §8.1).
 *
 * Phase 6 fills the merchant tenancy fields from the request-scoped
 * TenantContext (resolved by ResolveTenantContext middleware): the user's
 * merchant, their membership (role + status), and first-time setup state so the
 * SPA can route a pending_setup owner straight to the wizard. The public id is
 * the ULID (A5).
 *
 * `permissions` carries the resolved §10.3 permission keys (Phase 8).
 * `memberships` (array) is retained for router-guard compatibility and derived
 * from the single active membership (launch rule: one membership per user).
 *
 * @mixin User
 */
final class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $context = app(TenantContext::class);
        $merchant = $context->merchant();
        $membership = $context->merchantUser();

        $merchantPayload = $merchant !== null
            ? MerchantResource::make($merchant)->resolve($request)
            : null;

        $membershipPayload = $membership !== null
            ? MerchantMembershipResource::make($membership)->resolve($request)
            : null;

        return [
            'user' => [
                'id' => $this->ulid,
                'email' => $this->email,
                'name' => $this->name,
                'status' => $this->status,
                'email_verified_at' => $this->email_verified_at?->toIso8601String(),
                'is_platform_staff' => (bool) $this->is_platform_staff,
            ],
            'merchant' => $merchantPayload,
            'membership' => $membershipPayload,
            // Retained for guard compatibility; mirrors the single active membership.
            'memberships' => $membershipPayload !== null ? [$membershipPayload] : [],
            // Active branch assignments (Plan §8.2). Empty for a merchant_admin —
            // they see all own-merchant branches — and for non-merchant users.
            'branch_ids' => $this->branchUlids($context),
            // Resolved permission keys (Plan §10.3): role default grants ± per-user
            // overrides, request-cached by TenantContext. UX only — the backend
            // (EnsurePermission + policies) is the security boundary.
            'permissions' => $context->permissions(),
            'setup' => $this->setupState($merchant),
        ];
    }

    /**
     * Public ULIDs of the membership's active branch assignments (Plan §8.2).
     *
     * @return list<string>
     */
    private function branchUlids(TenantContext $context): array
    {
        $branchIds = $context->branchIds();

        if ($branchIds === []) {
            return [];
        }

        return array_values(MerchantBranch::query()
            ->whereIn('id', $branchIds)
            ->pluck('ulid')
            ->map(static fn (string $ulid): string => $ulid)
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function setupState(?Merchant $merchant): array
    {
        if ($merchant === null) {
            return [
                'required' => false,
                'current_step' => null,
                'completed_at' => null,
            ];
        }

        $progress = app(FirstTimeSetupProgress::class);

        return [
            'required' => $progress->required($merchant),
            'current_step' => $progress->currentStep($merchant),
            'completed_at' => $merchant->setup_completed_at?->toIso8601String(),
        ];
    }
}
