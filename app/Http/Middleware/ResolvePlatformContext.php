<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextResolver;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the PLATFORM context for Super-Admin platform routes (Plan §8.1, §24.1; Phase 20A).
 *
 * Platform authority is never merchant tenant context: this middleware populates the request
 * TenantContext with the platform-staff permissions ONLY (via {@see TenantContextResolver}, which
 * marks platform staff and sets platform grants without binding any merchant). A non-platform user
 * is left with an empty context, so `EnsurePermission` denies (no platform grant) and no merchant
 * scope is ever bound on a platform route. Unlike {@see ResolveTenantContext} it never resolves a
 * merchant membership — which is exactly why platform_mutation forbids ResolveTenantContext but
 * permits this resolver (Plan §24.1).
 */
final class ResolvePlatformContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_platform_staff) {
            $this->resolver->populate($this->context, $user);
        } else {
            $this->context->reset();
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $this->context->reset();
    }
}
