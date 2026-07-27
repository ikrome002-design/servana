<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Support\Facades\Route;

uses()->group('security', 'phase23', 'forbidden-capability');

/*
 |==============================================================================
 | Phase 23 Increment 3.3 — the standing forbidden-capability absence guard.
 |
 | `ForbiddenRouteAbsenceTest` proves two forbidden ROUTES are absent, and
 | `NoDirectProviderIntegrationTest` proves no direct provider integration exists. Neither
 | looks at the other surfaces a forbidden capability could leak through: the permission
 | catalogue, the generated TypeScript contracts, the OpenAPI document, the screen inventory,
 | or configuration. This guard closes that gap in ONE place (Plan §80 Phase 23: "confirm
 | forbidden routes absent"; §83 launch criteria; §2.2 ownership matrix).
 |
 | DELIBERATE NON-MATCH: `mpesa_offline` is a legitimate merchant-CLIENT payment METHOD
 | (Phase 18A) and has nothing to do with platform billing or provider integration. Every
 | pattern below is written to exclude it, and a dedicated case proves it survives.
 */

/** Surfaces scanned for forbidden capability leakage. */
function p23ForbiddenSurfaces(): array
{
    return [
        'openapi' => base_path('docs/api/openapi.json'),
        'api types' => base_path('resources/spa/src/types/generated/api.ts'),
        'permission types' => base_path('resources/spa/src/types/generated/permissions.ts'),
        'screen inventory' => base_path('docs/frontend/screens/inventory.json'),
        'role navigation' => base_path('docs/frontend/navigation/role-navigation.yaml'),
    ];
}

it('exposes no Super-Administrator merchant-creation, first-admin-creation or impersonation capability', function (): void {
    $offending = [];

    // Routes.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $haystack = strtolower($route->uri().' '.($route->getName() ?? ''));
        foreach (['impersonat', 'become-user', 'switch-user', 'sudo'] as $needle) {
            if (str_contains($haystack, $needle)) {
                $offending[] = "route: {$route->uri()}";
            }
        }
        // A platform-scoped write that creates a merchant administrator.
        if (str_contains($haystack, 'platform') && preg_match('/merchant[-_]?admin|first[-_]?admin/', $haystack)
            && array_intersect(['POST', 'PUT', 'PATCH'], $route->methods()) !== []) {
            $offending[] = "route: {$route->uri()}";
        }
    }

    // Permission catalogue (runtime registry + canonical matrix).
    $keys = array_merge(
        app(PermissionRegistry::class)->permissionKeys(),
        app(PermissionMatrix::class)->keys(),
    );
    foreach ($keys as $key) {
        $lower = strtolower($key);
        if (str_contains($lower, 'impersonat')) {
            $offending[] = "permission: {$key}";
        }
        if (preg_match('/^platform\.merchant\.(create|register)/', $lower)) {
            $offending[] = "permission: {$key}";
        }
    }

    expect(array_values(array_unique($offending)))->toBe([], implode("\n", array_unique($offending)));
});

it('exposes no personnel contact-export capability on ANY surface', function (): void {
    $offending = [];

    // Permission catalogue: no export key may touch personnel/client contact (Plan §19.4).
    $keys = array_merge(
        app(PermissionRegistry::class)->permissionKeys(),
        app(PermissionMatrix::class)->keys(),
    );
    foreach ($keys as $key) {
        $lower = strtolower($key);
        $contactish = str_contains($lower, 'contact')
            || str_contains($lower, 'served')
            || str_contains($lower, 'client');
        if ($contactish && (str_contains($lower, 'export') || str_contains($lower, 'download'))) {
            $offending[] = "permission: {$key}";
        }
    }

    // Contracts + inventories: no contact-export operation, path, screen or nav entry.
    foreach (p23ForbiddenSurfaces() as $label => $path) {
        if (! is_file($path)) {
            continue;
        }
        $body = strtolower((string) file_get_contents($path));
        foreach ([
            'contact-export', 'contacts/export', 'contact_export',
            'personnel-export', 'personnel/export', 'staff-contact', 'phone-export',
        ] as $needle) {
            if (str_contains($body, $needle)) {
                $offending[] = "{$label}: {$needle}";
            }
        }
    }

    expect(array_values(array_unique($offending)))->toBe([], implode("\n", array_unique($offending)));
});

it('exposes no Wallet-owned or R&E-owned capability inside Servana', function (): void {
    $offending = [];

    // Servana owns billing truth; Wallet owns money movement; R&E owns reward truth (Plan §2.2).
    $forbiddenConcepts = [
        // Wallet-owned
        'wallet_ledger', 'wallet-ledger', 'provider_reconciliation', 'ledger_posting',
        // R&E-owned
        'referrer_account', 'referrer-account', 'reward_calculation', 'reward_ledger',
        'referrer_payout', 'referrer-payout', 'reward-ledger',
    ];

    foreach (p23ForbiddenSurfaces() as $label => $path) {
        if (! is_file($path)) {
            continue;
        }
        $body = strtolower((string) file_get_contents($path));
        foreach ($forbiddenConcepts as $needle) {
            if (str_contains($body, $needle)) {
                $offending[] = "{$label}: {$needle}";
            }
        }
    }

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $haystack = strtolower($route->uri().' '.($route->getName() ?? ''));
        foreach ($forbiddenConcepts as $needle) {
            if (str_contains($haystack, str_replace('_', '-', $needle))) {
                $offending[] = "route: {$route->uri()}";
            }
        }
    }

    expect(array_values(array_unique($offending)))->toBe([], implode("\n", array_unique($offending)));
});

it('exposes no provider runtime surface (STK, C2B, PayBill, Till, callbacks) outside the future Wallet boundary', function (): void {
    $offending = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower($route->uri());
        // Path SEGMENT matches only — `mpesa_offline` (a payment-method value, never a path)
        // can never satisfy these.
        foreach (['/mpesa/', '/daraja/', '/stk/', 'stk-push', 'stk-callback', '/c2b/', 'paybill', '/till/'] as $needle) {
            if (str_contains($uri, $needle)) {
                $offending[] = "route: {$route->uri()}";
            }
        }
    }

    // Configuration: no provider credential namespace may exist.
    foreach (['services.mpesa', 'services.daraja', 'services.safaricom'] as $configKey) {
        if (config($configKey) !== null) {
            $offending[] = "config: {$configKey}";
        }
    }

    expect(array_values(array_unique($offending)))->toBe([], implode("\n", array_unique($offending)));
});

it('does NOT reject the legitimate mpesa_offline merchant-client payment method', function (): void {
    // Guardrail on the guard itself: Phase 18A's `mpesa_offline` payment method is legitimate
    // merchant-client terminology (CLAUDE.md §1) and must survive every pattern above. If a
    // future contributor broadens a pattern to a bare "mpesa" substring, this fails.
    $openapi = base_path('docs/api/openapi.json');
    expect(is_file($openapi))->toBeTrue();

    $body = (string) file_get_contents($openapi);
    expect(str_contains($body, 'mpesa_offline'))
        ->toBeTrue('mpesa_offline must remain a valid merchant-client payment method in the contract');

    // And it must not be reachable by any provider-runtime pattern used above.
    foreach (['/mpesa/', '/daraja/', '/stk/', 'stk-push', 'stk-callback', '/c2b/', 'paybill', '/till/'] as $needle) {
        expect(str_contains('mpesa_offline', $needle))
            ->toBeFalse("pattern '{$needle}' must never match the legitimate mpesa_offline method");
    }
});

it('never ships Meilisearch credentials to the frontend', function (): void {
    $offending = [];

    // sourceFilesUnder() replaces RecursiveDirectoryIterator, which truncates directory listings
    // on the dev bind mount and silently under-scanned the SPA (PH23-SCAN-001).
    foreach (sourceFilesUnder(base_path('resources/spa/src'), ['ts', 'vue', 'js']) as $path) {
        $body = (string) file_get_contents($path);
        if (preg_match('/meili[_-]?(master|search)?[_-]?key|MEILISEARCH_KEY|meilisearch.*apiKey/i', $body)) {
            $offending[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    // The SPA must reach search only through the authenticated, tenant-scoped Servana API.
    expect($offending)->toBe([], "Meilisearch credential material in the SPA:\n".implode("\n", $offending));
});
