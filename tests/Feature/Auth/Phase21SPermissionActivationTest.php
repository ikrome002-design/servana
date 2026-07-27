<?php

declare(strict_types=1);

use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'phase21s');

/*
 | Phase 21S — the atomic permission flip (Plan §19.2/§19.3, §64; ADR-010). TWO canonical keys are
 | activated, both Personnel-only:
 |
 |   personnel.my_served_clients.view — the masked served-client READ (allow_read in billing
 |     read-only, no entitlement). Its matrix row previously carried `owning_phase: Phase 21N` and
 |     the navigation YAML said Phase 15A; Plan §64 and the §80 Phase 21S entry both place the
 |     served-clients view inside 21S, and the Plan is authoritative. Its ATTRIBUTES are unchanged.
 |
 |   personnel.my_sms.send — the sending capability (entitlement `sms`, blocked in billing
 |     read-only, warn-severity audit).
 |
 | NO legacy key is retired, and NO contact-export key is created — Plan §19.4 makes that
 | non-overridable. Parity across YAML/PHP/DB/TypeScript/phase8 is proven exhaustively in the matrix
 | parity suites; this asserts the specific 21S delta.
 */

const P21S_ACTIVATED = [
    'personnel.my_served_clients.view',
    'personnel.my_sms.send',
];

it('activates exactly the two canonical Phase 21S keys across YAML + PHP registry + DB', function (): void {
    $this->seed(PermissionSeeder::class);
    $matrix = app(PermissionMatrix::class);
    $registry = array_fill_keys(app(PermissionRegistry::class)->permissionKeys(), true);
    $active = array_fill_keys($matrix->activeKeys(), true);
    $dbKeys = array_fill_keys(Permission::query()->pluck('key')->all(), true);

    foreach (P21S_ACTIVATED as $key) {
        expect($active)->toHaveKey($key, "{$key} must be active in the YAML");
        expect($registry)->toHaveKey($key, "{$key} must be in the PHP registry");
        expect($dbKeys)->toHaveKey($key, "{$key} must be projected to the DB");
        // An active canonical key carries no owning phase / successor (final form).
        expect($matrix->get($key)['implementation_status'])->toBe('active');
        expect($matrix->get($key)['owning_phase'] ?? null)->toBeNull();
    }
});

it('keeps its two keys active as later phases extend the matrix, with no legacy retirement', function (): void {
    $matrix = app(PermissionMatrix::class);
    $active = array_fill_keys($matrix->activeKeys(), true);

    // The absolute counts move with every phase (20H left 128/40; 21S activated 2 more → 130/38;
    // Phase 23 activated `staff.view` → 131/37 and the merchant-profile pair → 133/35, then retired
    // the legacy duplicate `merchant.profile.manage` → 132/35 with the catalogue at 167), so —
    // following the Phase 20H precedent — this asserts what Phase 21S actually OWNS: its own two
    // keys, still active, and the invariant that no phase INVENTS a canonical key.
    foreach (P21S_ACTIVATED as $key) {
        expect($active)->toHaveKey($key);
    }

    expect(count($matrix->activeKeys()) + count($matrix->plannedKeys()))
        ->toBe(167, 'the catalogue only ever shrinks by a retired legacy duplicate — never grows');
});

it('grants both keys to PERSONNEL and to no other role, by default or by override', function (): void {
    $registry = app(PermissionRegistry::class);

    foreach ($registry->roleKeys() as $role) {
        $grants = array_merge($registry->defaultGrantsFor($role), $registry->grantableFor($role));

        foreach (P21S_ACTIVATED as $key) {
            // `toContain` treats extra arguments as further expected VALUES, not as a message, so
            // the assertion is written as a boolean with the message on `toBeTrue`/`toBeFalse`.
            $holds = in_array($key, $grants, true);

            if ($role === PermissionRegistry::ROLE_PERSONNEL) {
                expect($holds)->toBeTrue("personnel must hold {$key}");

                continue;
            }

            expect($holds)->toBeFalse("{$role} must never hold {$key}");
        }
    }
});

it('keeps the read and the send capabilities distinct — reading never implies sending', function (): void {
    $matrix = app(PermissionMatrix::class);

    $read = $matrix->get('personnel.my_served_clients.view');
    $send = $matrix->get('personnel.my_sms.send');

    // The READ survives billing read-only and needs no entitlement.
    expect($read['billing_read_only_behavior'])->toBe('allow_read')
        ->and($read['entitlement_key'])->toBeNull()
        ->and($read['audit_severity'])->toBe('info');

    // The SEND is entitlement-gated and stops in billing read-only.
    expect($send['billing_read_only_behavior'])->toBe('block')
        ->and($send['entitlement_key'])->toBe('sms')
        ->and($send['audit_severity'])->toBe('warn');

    // Both are own-scope and non-overridable — no override can widen either (Plan §19.4).
    foreach (P21S_ACTIVATED as $key) {
        $row = $matrix->get($key);
        expect($row['scope'])->toBe('own')
            ->and($row['override_policy'])->toBe('non_overridable')
            ->and($row['mfa_required'])->toBeFalse()
            ->and($row['step_up_required'])->toBeFalse()
            ->and($row['default_roles'])->toBe(['personnel']);
    }
});

it('creates NO contact-export permission and retires nothing', function (): void {
    $registry = app(PermissionRegistry::class);
    $keys = $registry->permissionKeys();

    // Guardrail §6.8 / Plan §19.4: no export key touching client contact exists anywhere.
    foreach ($keys as $key) {
        $lower = strtolower($key);
        $contactish = str_contains($lower, 'client') || str_contains($lower, 'contact') || str_contains($lower, 'served');
        expect($contactish && str_contains($lower, 'export'))->toBeFalse("{$key} would be a contact-export key");
    }

    // Every key Phase 20H had is still present (nothing was retired to make room).
    foreach ([
        'personnel.my_compensation.view', 'personnel.my_earnings.view', 'personnel.my_statements.download',
        'personnel.my_payouts.view', 'personnel.my_earnings_query.create',
        'personnel.my_queue.view', 'personnel.my_appointments.view', 'personnel.my_sessions.view',
    ] as $key) {
        expect($keys)->toContain($key);
    }
});

it('leaves the deferred families planned (20D-W / 21N / 21R-B / 22 / 23 / 24 / 25)', function (): void {
    $matrix = app(PermissionMatrix::class);
    $planned = array_fill_keys($matrix->plannedKeys(), true);

    expect($planned)->toHaveKey('platform.billing_reconciliation.resolve')  // Phase 20D-W
        ->toHaveKey('platform.integrations.health.view')                    // Phase 20D-W
        ->toHaveKey('platform.integrations.refer_earn.manage');             // Phase 21R-B
});
