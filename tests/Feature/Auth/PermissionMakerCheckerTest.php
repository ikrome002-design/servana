<?php

declare(strict_types=1);

use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;

uses()->group('auth', 'permissions', 'matrix');

/*
 | §19.3 maker/checker: the contract records every incompatibility, and the
 | default-grant matrix keeps maker and checker on DIFFERENT roles for the
 | separation-of-duty workflows.
 |
 | The one deliberate single-role pair — customer_payment.record_exception ⟂
 | customer_payment.validate (both Finance) — is separated PER TRANSACTION by the
 | PaymentMakerCheckerGuard (a checker may not be the group maker), not by role,
 | and is proven in the Phase 18A/18B payment tests. It is excluded here.
 */

/** Normalise the one documented Plan alias (cash_up.submit → branch.cash_up.submit). */
function mcNormalize(string $key, PermissionRegistry $registry): string
{
    if (in_array($key, $registry->permissionKeys(), true)) {
        return $key;
    }

    return $key === 'cash_up.submit' ? 'branch.cash_up.submit' : $key;
}

it('records a maker/checker incompatibility for the active financial workflows', function (): void {
    $matrix = app(PermissionMatrix::class);

    $pairs = [
        'customer_payment.record' => 'customer_payment.validate',
        'branch.cash_up.submit' => 'cash_up.approve',
        'refund.create' => 'refund.approve',
        'period_lock.reopen' => 'merchant.period_reopen.approve_exception',
    ];

    foreach ($pairs as $maker => $checker) {
        $declared = collect($matrix->get($maker)['maker_checker_incompatibilities'])
            ->push(...$matrix->get($checker)['maker_checker_incompatibilities'])
            ->map(fn (string $k) => $k)
            ->all();

        expect($declared)->not->toBe([], "no MC incompatibility declared for {$maker}/{$checker}");
    }
});

it('never grants both sides of a separation-of-duty pair to a single role by default', function (): void {
    $registry = app(PermissionRegistry::class);
    $matrix = app(PermissionMatrix::class);
    $excludedSingleRolePairs = [
        ['customer_payment.record_exception', 'customer_payment.validate'],
    ];

    $problems = [];
    foreach ($matrix->activeKeys() as $key) {
        foreach ($matrix->get($key)['maker_checker_incompatibilities'] as $partnerRaw) {
            $partner = mcNormalize($partnerRaw, $registry);
            if (! in_array($partner, $registry->permissionKeys(), true)) {
                continue; // partner not yet an active key
            }
            $pair = [$key, $partner];
            sort($pair);
            if (in_array($pair, array_map(function ($p) {
                sort($p);

                return $p;
            }, $excludedSingleRolePairs), true)) {
                continue;
            }
            foreach ($registry->roleKeys() as $role) {
                $grants = $registry->defaultGrantsFor($role);
                if (in_array($key, $grants, true) && in_array($partner, $grants, true)) {
                    $problems[] = "role {$role} holds both {$key} and {$partner} by default";
                }
            }
        }
    }

    expect(array_values(array_unique($problems)))->toBe([], implode("\n", array_unique($problems)));
});
