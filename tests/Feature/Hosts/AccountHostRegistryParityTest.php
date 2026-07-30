<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostRegistry;

uses()->group('hosts', 'ui02', 'contracts');

/*
 |==============================================================================
 | Phase UI-02 — one host authority, generated consumers (UI/UX plan §8.2).
 |
 | `config/account-hosts.json` is the single source. Laravel config, the frontend registry
 | and the Nginx allowlist are all derived from it. Three hand-maintained maps would drift,
 | and a drifted map means a host that Nginx accepts but Laravel does not recognise (or
 | worse, the reverse). These tests fail the moment any consumer disagrees with the source.
 */

/** @return array<string, mixed> */
function ui02Source(): array
{
    /** @var array<string, mixed> $source */
    $source = json_decode(
        (string) file_get_contents(base_path('config/account-hosts.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    return $source;
}

it('derives Laravel config from the canonical source', function (): void {
    $source = ui02Source();
    $config = (array) config('account_hosts.accounts');

    expect($config)->toHaveCount(count($source['accounts']));

    foreach ($source['accounts'] as $account) {
        $key = $account['account_key'];
        expect($config)->toHaveKey($key);

        foreach ([
            'display_name', 'public_content_key', 'legal_content_key', 'navigation_placement',
            'route_name_prefix', 'default_authenticated_route', 'requires_setup', 'requires_mfa',
            'role_family', 'self_registration', 'invitation_acceptance', 'public_cta_category',
        ] as $field) {
            expect($config[$key][$field])->toBe($account[$field], "{$key}.{$field}");
        }
    }
});

it('keeps the frontend registry in parity with the backend', function (): void {
    $generated = (string) file_get_contents(
        base_path('resources/spa/src/host/accountHosts.generated.ts'),
    );

    $registry = app(AccountHostRegistry::class);

    foreach ($registry->accountKeys() as $accountKey) {
        $account = $registry->get($accountKey);

        // Every account, and every host it answers on, must appear in the generated file.
        expect($generated)->toContain("  {$accountKey}: {");

        foreach (['production', 'staging', 'local'] as $environment) {
            expect($generated)->toContain("'".$registry->hostForAccount($accountKey, $environment)."'");
        }

        expect($generated)->toContain("navigationPlacement: '{$account->navigationPlacement}'");
    }

    // ...and the frontend must not have invented an account the backend does not know.
    preg_match_all('/^  ([a-z_]+): \{$/m', $generated, $matches);
    expect($matches[1])->toEqualCanonicalizing($registry->accountKeys());
});

it('keeps the nginx allowlist in parity with the backend', function (): void {
    $allowlist = (string) file_get_contents(
        base_path('docker/nginx/account-hosts.generated.conf'),
    );

    $registry = app(AccountHostRegistry::class);

    // Every approved host is present...
    foreach ($registry->allHosts() as $host) {
        expect($allowlist)->toContain($host);
    }

    // ...and nothing else is. Parse the directive back out and compare exactly, so an
    // extra hostname smuggled into the allowlist fails here.
    preg_match('/server_name\s+(.*?);/s', $allowlist, $m);
    expect(isset($m[1]))->toBeTrue('the generated fragment has no server_name directive');

    $declared = array_values(array_filter(array_map('trim', explode("\n", $m[1]))));
    sort($declared);

    expect($declared)->toBe($registry->allHosts());
    expect($declared)->toHaveCount(24, '8 accounts x 3 environments');
});

it('lives outside the auto-included nginx conf.d directory', function (): void {
    // nginx.conf auto-includes conf.d/*.conf at the HTTP level. The fragment is a bare
    // `server_name` directive, which is only valid inside a server block — placing it in
    // conf.d would make nginx fail to start.
    $default = (string) file_get_contents(base_path('docker/nginx/default.conf'));
    $dockerfile = (string) file_get_contents(base_path('docker/nginx.Dockerfile'));

    expect($default)->toContain('include /etc/nginx/servana/account-hosts.generated.conf;');
    expect($dockerfile)->toContain('/etc/nginx/servana/account-hosts.generated.conf');
    expect($default)->not->toContain('/etc/nginx/conf.d/account-hosts.generated.conf');
});

it('keeps approved production hostnames out of hand-written source', function (): void {
    // The production hostnames may appear ONLY in the declared authority and its generated
    // consumers (plus documentation and proof, which are narrative). A hostname hard-coded
    // into application or test source is a second authority waiting to drift.
    $allowed = [
        'config/account-hosts.json',
        'config/account_hosts.php',
        'resources/spa/src/host/accountHosts.generated.ts',
        'docker/nginx/account-hosts.generated.conf',
        'scripts/generate-account-hosts.mjs',
        'scripts/ui02-host-smoke.mjs',
        // Tests deliberately spell out the bound production map so a silent rename fails,
        // and assert that a denial state never names an approved host.
        'tests/Feature/Hosts/AccountHostResolutionTest.php',
        'tests/Feature/Hosts/AccountHostUrlGeneratorTest.php',
        'resources/spa/src/host/accountHostContext.spec.ts',
        'resources/spa/src/pages/Home.spec.ts',
    ];

    // Comments are stripped first: this guard is about a hostname reaching executable code,
    // not about explaining in prose why `evil-servana.ke` must be rejected.
    $stripComments = static function (string $code): string {
        $code = preg_replace('#/\*.*?\*/#s', '', $code) ?? $code;
        $code = preg_replace('#^\s*//.*$#m', '', $code) ?? $code;

        return preg_replace('#^\s*\#.*$#m', '', $code) ?? $code;
    };

    $offenders = [];
    $roots = [base_path('app'), base_path('config'), base_path('routes'), base_path('resources/spa/src')];

    foreach ($roots as $root) {
        foreach (sourceFilesUnder($root, ['php', 'ts', 'vue', 'json']) as $path) {
            $relative = str_replace([base_path().DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            $contents = $stripComments((string) file_get_contents($path));
            if (preg_match('/\b[a-z]*\.?servana\.ke\b/i', $contents) === 1) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'Production hostnames are hard-coded outside the account-host '
        .'authority in: '.implode(', ', $offenders));
});
