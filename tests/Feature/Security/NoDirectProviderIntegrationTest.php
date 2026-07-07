<?php

declare(strict_types=1);
use Illuminate\Support\Facades\Route;

uses()->group('security', 'architecture', 'provider-guard');

/*
 | No direct payment-provider integration (Plan §9 rule 20, §11, §75.1; ADR-012).
 | Servana billing collections flow through Wallet by Citrus only. This guard fails when
 | executable or configuration surfaces introduce Safaricom/Daraja SDKs, provider OAuth,
 | provider secrets, provider callback routes, provider API hostnames, services.mpesa config,
 | provider receipt-uniqueness logic, provider reconciliation logic, or direct bank/card
 | adapters for Servana platform billing.
 |
 | Legitimate merchant-client terminology `mpesa_offline` (Phase 18A payment method) is NOT
 | rejected. Documentation, historical ADRs, plan supersession text, and this test's own
 | fixture strings are excluded via narrow path/content allowlists.
 */

/** @return list<string> */
function providerGuardScanRoots(): array
{
    return [
        base_path('app'),
        base_path('bootstrap'),
        base_path('config'),
        base_path('routes'),
    ];
}

/** @return list<string> */
function providerGuardScanExtensions(): array
{
    return ['php', 'env.example'];
}

/**
 * @return list<string> relative paths (forward slashes) excluded from content scans
 */
function providerGuardExcludedRelativePaths(): array
{
    return [
        'tests/Feature/Security/NoDirectProviderIntegrationTest.php',
    ];
}

/** @return list<string> */
function providerGuardForbiddenPatterns(): array
{
    return [
        'oauth/v1/generate',
        'mpesa_consumer_key',
        'mpesa_consumer_secret',
        'services.mpesa',
        'safaricom.co.ke',
        'api.safaricom',
        'daraja',
        'Mpesa\\',
        'Safaricom\\',
    ];
}

/**
 * Lines matching forbidden patterns but allowed when the line is clearly merchant-client
 * payment-method terminology or an explicit allowlisted comment marker.
 */
function providerGuardLineIsAllowed(string $line): bool
{
    if (str_contains($line, 'mpesa_offline')) {
        return true;
    }

    if (preg_match('/\bno\s+daraja\b/i', $line) || preg_match('/\bnot\s+daraja\b/i', $line)) {
        return true;
    }

    if (str_contains($line, 'provider-guard-allowed')) {
        return true;
    }

    return false;
}

/** @return list<string> */
function providerGuardCollectMatchingLines(string $relativePath, string $contents): array
{
    $matches = [];
    $patterns = providerGuardForbiddenPatterns();

    foreach (preg_split('/\r?\n/', $contents) as $i => $line) {
        if (providerGuardLineIsAllowed($line)) {
            continue;
        }

        $haystack = strtolower($line);
        foreach ($patterns as $pattern) {
            if (str_contains($haystack, strtolower($pattern))) {
                $matches[] = $relativePath.':'.($i + 1).': '.$line;

                break;
            }
        }
    }

    return $matches;
}

/** @return list<string> all offending lines across scanned roots */
function providerGuardScanExecutableSurfaces(): array
{
    $offending = [];
    $excluded = providerGuardExcludedRelativePaths();

    foreach (providerGuardScanRoots() as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (! in_array($ext, providerGuardScanExtensions(), true)) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));
            if (in_array($relative, $excluded, true)) {
                continue;
            }

            $contents = (string) file_get_contents($path);
            array_push($offending, ...providerGuardCollectMatchingLines($relative, $contents));
        }
    }

    return $offending;
}

it('exposes no direct provider callback route matching */mpesa/*', function (): void {
    $offending = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());
        if (preg_match('#(^|/)mpesa(/|$)#', $uri)) {
            $offending[] = $route->methods()[0].' '.$route->uri().' ['.($route->getName() ?? '').']';
        }
    }

    expect($offending)->toBe([]);
});

it('has no services.mpesa configuration keys', function (): void {
    $configPath = config_path('services.php');
    expect($configPath)->toBeFile();

    $contents = strtolower((string) file_get_contents($configPath));
    expect($contents)->not->toContain('mpesa');
});

it('has no Daraja or Safaricom composer dependency', function (): void {
    $composer = json_decode((string) file_get_contents(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);
    $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);
    $names = implode(' ', array_keys($require));

    expect(strtolower($names))->not->toMatch('/daraja|safaricom|mpesa/');
});

it('has no forbidden direct-provider symbols in executable or configuration surfaces', function (): void {
    $offending = providerGuardScanExecutableSurfaces();

    expect($offending)->toBe([]);
});

it('detects a representative forbidden fixture when the guard runs against it', function (): void {
    $fixture = <<<'PHP'
<?php
// provider-guard-allowed: regression fixture only — not production code
$mpesa_consumer_key = 'fixture';
PHP;

    $matches = providerGuardCollectMatchingLines('fixture/example.php', $fixture);

    expect($matches)->not->toBe([]);
    expect(implode("\n", $matches))->toContain('mpesa_consumer_key');
});

it('does not reject legitimate mpesa_offline merchant-client payment terminology', function (): void {
    $allowed = <<<'PHP'
<?php
case PaymentMethod::MpesaOffline:
    return 'mpesa_offline';
PHP;

    expect(providerGuardCollectMatchingLines('app/Domain/Payments/Enums/PaymentMethod.php', $allowed))->toBe([]);
});
