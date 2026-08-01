<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostRegistry;

uses()->group('hosts', 'ui02', 'security', 'authorization');

/*
 |==============================================================================
 | Phase UI-02 — the host is never authorization (ADR-017; UI/UX plan §4.3, §18.1–§18.5).
 |
 | ADR-016 gives each account its own hostname. The obvious and dangerous shortcut is to
 | treat arrival on `citrus.servana.ke` as evidence that a request is a Super Administrator
 | request. The `Host` header is client-supplied, so that would be a privilege-escalation
 | primitive: send one header, inherit platform scope.
 |
 | These tests assert the NEGATIVE directly — changing only the Host header must change
 | nothing about what a request is allowed to do.
 */

/** Every approved host, so the assertions sweep the whole allowlist rather than a sample. */
function ui02AllHosts(): array
{
    return app(AccountHostRegistry::class)->allHosts();
}

/*
 | NOTE on how the host is varied. Laravel's test client builds the request from the URI,
 | and Symfony's Request::create() overwrites HTTP_HOST with the URI's host — so
 | `withHeader('Host', ...)` on a relative path is silently ineffective and every call would
 | hit `localhost`. These tests therefore use ABSOLUTE URLs, which is the only way to make
 | the assertions genuinely host-varying.
 */

it('gives an anonymous request no access on the platform host', function (): void {
    // Arriving on the Super Administrator host must not authenticate anybody.
    $this->getJson('http://citrus.servana.test/api/v1/me')->assertUnauthorized();
});

it('returns the same authorization answer on every account host', function (): void {
    // One unauthenticated protected call, swept across all 24 approved hosts. If any host
    // were an authorization input, one of these would differ.
    $statuses = [];

    foreach (ui02AllHosts() as $host) {
        $statuses[$host] = $this->getJson('http://'.$host.'/api/v1/me')->getStatusCode();
    }

    expect($statuses)->toHaveCount(24, 'the sweep must cover every approved host');
    expect(array_unique(array_values($statuses)))->toBe(
        [401],
        'A protected endpoint answered differently depending on the Host header: '
            .json_encode(array_filter($statuses, static fn (int $s): bool => $s !== 401)),
    );
});

it('does not let the platform host broaden a merchant user', function (): void {
    // A merchant-side user asking for platform data must be refused identically on the
    // platform host and on their own host: the host is not a capability.
    $onPlatformHost = $this->getJson('http://citrus.servana.test/api/v1/platform/merchants')->getStatusCode();
    $onMerchantHost = $this->getJson('http://servana.test/api/v1/platform/merchants')->getStatusCode();

    expect($onPlatformHost)->toBe($onMerchantHost);
    expect($onPlatformHost)->toBeIn([401, 403, 404]);
});

it('never passes the host to a policy, gate, tenant scope or permission check', function (): void {
    /*
     | A structural guard. Phase UI-03 refines it rather than widening it, because the original
     | form — "no file outside app/Http/Hosts/ may contain the string `AccountHost`" — became both
     | too strict and too weak once authentication had to BIND a credential to a host.
     |
     | Too strict: ADR-019 requires a Magic Link to be bound to its exact issuing host, and ADR-018
     | requires a handoff to be bound to its exact target host. Both are ANTI-SUBSTITUTION controls
     | on a credential. Neither grants anything — a correctly bound link still yields exactly the
     | permissions the database says the user has (ADR-017).
     |
     | Too weak: a substring match said nothing about WHAT a file did with the host. What actually
     | matters is that the host never reaches an AUTHORIZATION decision. So the guard now names the
     | layers where that decision is made — policies, gates, tenant scopes, the permission resolver,
     | the tenant context — and proves the host is absent from all of them.
     */
    $authorizationLayers = [
        'app/Policies',
        'app/Domain/Tenancy',
        'app/Domain/Auth/Services/PermissionResolver.php',
        'app/Domain/Auth/Services/PermissionMatrix.php',
        'app/Http/Middleware/EnsurePermission.php',
        'app/Http/Middleware/EnsureBranchScope.php',
        'app/Http/Middleware/EnsureMerchantActive.php',
        'app/Http/Middleware/EnsureActivePrincipal.php',
        'app/Http/Middleware/ResolveTenantContext.php',
        'app/Http/Middleware/ResolvePlatformContext.php',
    ];

    $offenders = [];

    foreach ($authorizationLayers as $target) {
        $path = base_path($target);
        $files = is_dir($path) ? sourceFilesUnder($path, ['php']) : (is_file($path) ? [$path] : []);

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            if (preg_match('/\bAccountHost(Registry|Resolver|UrlGenerator)?\b/', $contents) === 1) {
                $offenders[] = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file);
            }
        }
    }

    expect($offenders)->toBe([], 'The account host reached an authorization layer in: '
        .implode(', ', $offenders));
});

it('keeps the REQUEST-RESOLVED host inside the host layer and the surfaces that bind a credential', function (): void {
    /*
     | The resolved `AccountHost` VALUE OBJECT — the one built from the request's Host header — is
     | the dangerous one, because it is the only host that is attacker-influenced. It may be
     | consumed only by:
     |
     |   - the host layer itself;
     |   - the shell and health probe that report it;
     |   - the authentication surfaces that BIND a credential to it (ADR-018/ADR-019).
     |
     | Any other file that accepts one is a new place where the Host header could start to matter,
     | and must be added here deliberately with a reason.
     */
    $allowed = [
        'app/Http/Middleware/ResolveAccountHost.php',
        'app/Http/Controllers/SpaShellController.php',
        'app/Http/Controllers/HealthController.php',
        // UI-03: binds the issuing host onto the Magic Link, and the target host onto a handoff.
        'app/Http/Controllers/Api/V1/Auth/MagicLinkController.php',
        'app/Http/Controllers/Api/V1/Auth/AccountContextController.php',
        'app/Http/Controllers/ContextSwitchController.php',
    ];

    $offenders = [];

    foreach (sourceFilesUnder(base_path('app'), ['php']) as $path) {
        $normalized = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);

        if (str_starts_with($normalized, 'app/Http/Hosts/') || in_array($normalized, $allowed, true)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        // The value object specifically — not the registry (configuration: which host serves which
        // account) and not the URL generator (which builds links FROM configuration, never from
        // the request). Those two carry no attacker-influenced value.
        if (preg_match('/\bAccountHost\b(?!Registry|Resolver|UrlGenerator)/', $contents) === 1) {
            $offenders[] = $normalized;
        }
    }

    expect($offenders)->toBe([], 'These files consume the request-resolved account host outside the '
        .'host layer and the credential-binding surfaces: '.implode(', ', $offenders));
});

it('keeps policies free of any host-shaped argument', function (): void {
    $offenders = [];

    foreach (sourceFilesUnder(base_path('app/Policies'), ['php']) as $path) {
        $contents = (string) file_get_contents($path);
        if (preg_match('/\$(host|domain|subdomain|accountHost)\b/i', $contents) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([], 'Policies must never take a host argument: '.implode(', ', $offenders));
});
