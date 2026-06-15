<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authorize a route by permission key (Plan §10.3). Runs after auth:sanctum +
 * ResolveTenantContext (and, on per-branch routes, EnsureBranchScope).
 *
 * Backend authorization boundary (guardrail §6.2): a missing permission is a
 * 403 `permission_denied`. Existence leaks are already prevented upstream —
 * route-model binding resolves ULIDs inside tenant scope (foreign id → 404) and
 * EnsureBranchScope 404s a foreign branch — so reaching here means the resource
 * is visible to this tenant and only the capability is in question.
 *
 * Usage: `->middleware(EnsurePermission::class.':branches.create')`.
 */
final class EnsurePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $this->context->can($permission)) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }

        return $next($request);
    }
}
