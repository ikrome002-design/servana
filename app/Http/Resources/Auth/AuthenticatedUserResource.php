<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Domain\Auth\Mfa\MfaStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Onboarding\Services\FirstTimeSetupProgress;
use App\Domain\Sessions\Services\AccountContextResolver;
use App\Domain\Sessions\Support\AccountContext;
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
        /** @var User $user */
        $user = $this->resource;

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
                'email_verified_at' => $this->email_verified_at === null ? null : $this->email_verified_at->toIso8601String(),
                'is_platform_staff' => (bool) $this->is_platform_staff,
                // Phase UI-04 (ADR-021 §3). The user's EXPLICIT theme choice, or null when they
                // have never made one. `resolved_theme` applies the "absence means light" rule
                // server-side so the SPA never has to re-implement it — and so it can never
                // accidentally re-implement it as "ask the operating system".
                'theme_preference' => $this->theme_preference?->value,
                'resolved_theme' => $user->resolvedTheme()->value,
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
            // Phase UI-03 (ADR-018; UI/UX plan §19.1): the account experiences this user may
            // currently enter, DERIVED SERVER-SIDE from live membership rows. The SPA needs it to
            // refuse a wrong-account surface before it mounts (UI01-ROLE-001), and giving it the
            // answer is what stops the frontend inventing a role→account mapping of its own — a
            // second authority that could drift from the database.
            //
            // Account KEYS only. No permission, no merchant identity, no branch identity: the
            // switcher's own endpoint returns the full contexts, and it does so under the same
            // ownership check.
            'account_keys' => array_map(
                static fn (AccountContext $context): string => $context->accountKey,
                app(AccountContextResolver::class)->forUser($user),
            ),
            'setup' => $this->setupState($merchant),
            // Safe MFA state (Plan §18): drives the SPA enrollment/challenge/
            // step-up routing. Never exposes the secret or recovery-code hashes.
            'mfa' => app(MfaStatus::class)->for(
                $user,
                $request->hasSession() ? $request->session() : null,
            ),
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
            'completed_at' => $merchant->setup_completed_at === null ? null : $merchant->setup_completed_at->toIso8601String(),
        ];
    }
}
