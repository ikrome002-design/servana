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

it('never passes the host to a policy, gate or query scope', function (): void {
    // A structural guard, not a behavioural one: nothing outside the host layer itself may
    // reference the resolved AccountHost, because a policy that accepted one would make the
    // Host header an authorization input by construction.
    $offenders = [];

    foreach (sourceFilesUnder(base_path('app'), ['php']) as $path) {
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        $normalized = str_replace('\\', '/', $relative);

        // The host layer and the shell that renders the context are the only legitimate
        // consumers. Everything else — policies, gates, scopes, actions — must not see it.
        if (str_starts_with($normalized, 'app/Http/Hosts/')
            || $normalized === 'app/Http/Middleware/ResolveAccountHost.php'
            || $normalized === 'app/Http/Controllers/SpaShellController.php'
            || $normalized === 'app/Http/Controllers/HealthController.php') {
            continue;
        }

        $contents = (string) file_get_contents($path);
        if (str_contains($contents, 'AccountHost')) {
            $offenders[] = $normalized;
        }
    }

    expect($offenders)->toBe([], 'These files reference the account host outside the host layer: '
        .implode(', ', $offenders));
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
