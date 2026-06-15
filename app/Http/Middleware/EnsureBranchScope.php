<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Tenancy\Exceptions\TenantAccessException;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branch-scope boundary (Plan §8.2, Scope §3.3/§3.4). Runs after
 * ResolveTenantContext + EnsureMerchantActive on routes with a {branch} binding.
 *
 *   - branch belongs to another merchant → 404 (route binding already scoped;
 *     this is a defence-in-depth existence check that never leaks via 403)
 *   - merchant_admin                     → all own-merchant branches allowed
 *   - branch-scoped role without an active assignment to this branch → 403
 *     `no_branch_scope`
 *
 * The {branch} parameter resolves by ULID; foreign ULIDs 404 by binding.
 */
final class EnsureBranchScope
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $param = $request->route('branch');

        if ($param === null) {
            return $next($request); // route has no {branch} binding
        }

        // Resolve independently of binding-middleware ordering: the param may be
        // a model (bindings already ran) or the raw ULID string.
        $branch = $param instanceof MerchantBranch
            ? $param
            : MerchantBranch::query()->where('ulid', (string) $param)->first();

        // Foreign or unknown branch never leaks existence — 404, not 403.
        if ($branch === null || $branch->merchant_id !== $this->context->merchantId()) {
            abort(404);
        }

        if (! $this->context->canAccessBranch($branch->id)) {
            throw TenantAccessException::noBranchScope();
        }

        // Hand the scoped instance to the controller (implicit binding skips a
        // parameter already resolved to a model).
        $route = $request->route();
        if ($route !== null) {
            $route->setParameter('branch', $branch);
        }

        return $next($request);
    }
}
