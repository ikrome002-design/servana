<?php

declare(strict_types=1);

namespace App\Domain\Idempotency\Support;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Deterministic idempotency scope derivation (Plan §13.5, §24.4; Phase R4).
 *
 * The scope embeds the merchant/user/provider identity so the same raw
 * Idempotency-Key cannot collide across different merchants, actors, or provider
 * environments. Runs after `ResolveTenantContext`, so the context is populated.
 *
 *   merchant:{merchant-ulid}:user:{user-ulid}   (merchant-scoped request)
 *   platform:user:{user-ulid}                    (platform staff)
 *   webhook:{provider}:{environment}             (provider callback seam)
 */
final class IdempotencyScopeResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function forRequest(Request $request): string
    {
        $user = $request->user();
        $userUlid = $user instanceof User ? $user->ulid : 'anonymous';

        if ($this->context->isPlatformStaff()) {
            return 'platform:user:'.$userUlid;
        }

        $merchant = $this->context->merchant();

        if ($merchant !== null) {
            return 'merchant:'.$merchant->ulid.':user:'.$userUlid;
        }

        return 'user:'.$userUlid;
    }

    /** Provider-callback dedupe scope (Phase 20D attaches real provider IDs). */
    public function forProvider(string $provider, string $environment): string
    {
        return 'webhook:'.strtolower(trim($provider)).':'.strtolower(trim($environment));
    }

    /**
     * Forensic actor/tenant ids for the stored row (nullable; never the scope key).
     *
     * @return array{actor_user_id: int|null, merchant_id: int|null, branch_id: int|null}
     */
    public function forensics(Request $request): array
    {
        $user = $request->user();
        $branchIds = $this->context->branchIds();

        return [
            'actor_user_id' => $user instanceof User ? $user->id : null,
            'merchant_id' => $this->context->merchantId(),
            'branch_id' => count($branchIds) === 1 ? $branchIds[0] : null,
        ];
    }
}
