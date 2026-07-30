<?php

declare(strict_types=1);

use App\Http\Hosts\AccountHostRegistry;
use App\Http\Hosts\AccountHostResolver;
use Illuminate\Http\Request;

uses()->group('hosts', 'ui02', 'security');

/*
 |==============================================================================
 | Phase UI-02 — account-host resolution (ADR-016, ADR-017; UI/UX plan §4.1–§4.7, §18.5).
 |
 | Closes UI01-HOST-001: before this phase Nginx used `server_name _`, no Laravel host
 | resolver existed, and one origin served every account experience.
 |
 | The `Host` header is attacker-controlled, so these tests are written as an allowlist
 | contract: the eight approved hosts resolve, and everything else — deceptive suffixes,
 | injected control characters, ambiguous proxy chains, untrusted forwarded hosts — is
 | denied. Resolution NEVER grants anything; that is asserted separately in
 | AccountHostDoesNotAuthorizeTest.
 */

/** @return array<string, string> host => expected account key, for one environment */
function ui02HostsFor(string $environment): array
{
    $registry = app(AccountHostRegistry::class);
    $map = [];

    foreach ($registry->accountKeys() as $key) {
        $map[$registry->hostForAccount($key, $environment)] = $key;
    }

    return $map;
}

function ui02Resolve(string $host): ?string
{
    $request = Request::create('/', 'GET');
    $request->headers->set('Host', $host);

    return app(AccountHostResolver::class)->resolve($request)?->accountKey;
}

it('registers exactly the eight canonical accounts', function (): void {
    expect(app(AccountHostRegistry::class)->accountKeys())->toEqualCanonicalizing([
        'super_administrator',
        'merchant_administrator',
        'merchant_branch',
        'merchant_human_resource',
        'merchant_finance',
        'merchant_front_office',
        'merchant_personnel',
        'merchant_audit',
    ]);
});

it('resolves every production host to its account', function (): void {
    foreach (ui02HostsFor('production') as $host => $expected) {
        expect(ui02Resolve($host))->toBe($expected, "production host {$host}");
    }

    // The exact production map the plan binds — spelled out so a silent rename fails here.
    // Compared by account key rather than by declaration order.
    $actual = array_flip(ui02HostsFor('production'));
    ksort($actual);

    expect($actual)->toBe([
        'merchant_administrator' => 'servana.ke',
        'merchant_audit' => 'audit.servana.ke',
        'merchant_branch' => 'branch.servana.ke',
        'merchant_finance' => 'finance.servana.ke',
        'merchant_front_office' => 'office.servana.ke',
        'merchant_human_resource' => 'hr.servana.ke',
        'merchant_personnel' => 'staff.servana.ke',
        'super_administrator' => 'citrus.servana.ke',
    ]);
});

it('resolves every local host to its account', function (): void {
    foreach (ui02HostsFor('local') as $host => $expected) {
        expect(ui02Resolve($host))->toBe($expected, "local host {$host}");
    }

    expect(array_keys(ui02HostsFor('local')))->toEqualCanonicalizing([
        'citrus.servana.test', 'servana.test', 'branch.servana.test', 'hr.servana.test',
        'finance.servana.test', 'office.servana.test', 'staff.servana.test', 'audit.servana.test',
    ]);
});

it('resolves every staging host to its account', function (): void {
    foreach (ui02HostsFor('staging') as $host => $expected) {
        expect(ui02Resolve($host))->toBe($expected, "staging host {$host}");
    }

    // Staging is DERIVED from the configured suffix, never hard-coded per account.
    foreach (array_keys(ui02HostsFor('staging')) as $host) {
        expect($host)->toEndWith('.'.config('account_hosts.domains.staging_suffix'));
    }
});

it('normalizes case and a development port without corrupting the account', function (): void {
    expect(ui02Resolve('FINANCE.SERVANA.TEST'))->toBe('merchant_finance');
    expect(ui02Resolve('Finance.Servana.Test:8080'))->toBe('merchant_finance');
    expect(ui02Resolve('servana.test:5173'))->toBe('merchant_administrator');
    // A trailing root-label dot is legal in DNS and must not change the answer.
    expect(ui02Resolve('audit.servana.test.'))->toBe('merchant_audit');
});

it('denies an unknown host', function (): void {
    foreach (['example.com', 'servana.example', 'unknown.servana.test', 'localhost'] as $host) {
        expect(ui02Resolve($host))->toBeNull("host {$host} must not resolve");
    }
});

it('denies a deceptive suffix rather than matching on ends-with', function (): void {
    // These all "contain" or "end with" something that looks like an approved domain. A
    // suffix check would pass several of them; exact allowlist membership passes none.
    foreach ([
        'evil-servana.ke',
        'notservana.ke',
        'servana.ke.attacker.test',
        'finance.servana.ke.attacker.test',
        'xservana.test',
        'servana.test.evil.com',
        'finance-servana.test',
    ] as $host) {
        expect(ui02Resolve($host))->toBeNull("deceptive host {$host} must not resolve");
    }
});

it('denies malformed, injected and ambiguous host values', function (): void {
    $resolver = app(AccountHostResolver::class);

    foreach ([
        '',
        '   ',
        "finance.servana.test\r\nX-Injected: 1",
        "finance.servana.test\n",
        "finance.servana.test\t",
        'finance.servana.test finance.servana.ke',
        'finance.servana.test,servana.test',
        'finance.servana.test:notaport',
        'finance.servana.test:8080:9090',
        'user@finance.servana.test',
        'finance.servana.test/path',
        '*.servana.test',
        'finance_servana.test',
        '-finance.servana.test',
        'finance-.servana.test',
        str_repeat('a', 300).'.servana.test',
    ] as $host) {
        expect($resolver->normalize($host))->toBeNull('must reject: '.json_encode($host));
    }
});

it('ignores an untrusted forwarded host', function (): void {
    // TRUSTED_PROXIES is empty in tests, so Laravel must not honour X-Forwarded-Host at all.
    $request = Request::create('/', 'GET');
    $request->headers->set('Host', 'staff.servana.test');
    $request->headers->set('X-Forwarded-Host', 'citrus.servana.test');

    $resolved = app(AccountHostResolver::class)->resolve($request);

    expect($resolved?->accountKey)->toBe(
        'merchant_personnel',
        'an untrusted X-Forwarded-Host must never upgrade Personnel to the platform host',
    );
});

it('denies an ambiguous forwarded host chain', function (): void {
    $request = Request::create('/', 'GET');
    $request->headers->set('Host', 'staff.servana.test');
    $request->headers->set('X-Forwarded-Host', 'staff.servana.test, citrus.servana.test');

    expect(app(AccountHostResolver::class)->resolve($request))->toBeNull();
});

it('keeps machine hosts out of the account map', function (): void {
    $registry = app(AccountHostRegistry::class);

    foreach ($registry->machineHosts() as $machineHost) {
        expect($registry->findByHost($machineHost))
            ->toBeNull("machine host {$machineHost} must not resolve to an account");
        expect($registry->isMachineHost($machineHost))->toBeTrue();
    }

    // ...and an account host is never treated as a machine host.
    expect($registry->isMachineHost('finance.servana.test'))->toBeFalse();
});

it('survives configuration caching', function (): void {
    // config:cache stores the RESULT of config/account_hosts.php, so a cached deployment
    // never reads config/account-hosts.json again. Simulate that by round-tripping the
    // resolved config through serialization exactly as the cache file does.
    $live = config('account_hosts');

    /** @var array<string, mixed> $cached */
    $cached = unserialize(serialize($live));

    expect($cached)->toBe($live);
    expect($cached['accounts'])->toHaveCount(8);

    foreach ($cached['accounts'] as $key => $account) {
        expect($account['hosts'])->toHaveKeys(['production', 'staging', 'local']);
        expect($account['account_key'])->toBe($key);
    }
});
