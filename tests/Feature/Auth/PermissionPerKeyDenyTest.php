<?php

declare(strict_types=1);

use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionMatrix;
use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix');

/*
 | §19.5: `PermissionMatrix/{key}_denies` — every ACTIVE key is absent for a
 | principal that does not hold it. A role that has a key only as a grantable
 | override (without an override row) must not resolve it either.
 */

function denyMembership(Merchant $merchant, string $roleKey): MerchantUser
{
    return MerchantUser::factory()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::from($roleKey),
    ]);
}

it('never resolves an active permission key for a non-holder', function (): void {
    $this->seed(PermissionSeeder::class);
    $registry = app(PermissionRegistry::class);
    $resolver = app(PermissionResolver::class);
    $matrix = app(PermissionMatrix::class);
    $merchant = Merchant::factory()->active()->create();

    $merchantRoles = array_values(array_filter(
        $registry->roleKeys(),
        static fn (string $r): bool => $r !== PermissionRegistry::ROLE_SUPER_ADMIN,
    ));

    $failures = [];
    foreach ($matrix->activeKeys() as $key) {
        // A merchant role that does not hold the key by default is a clean denier.
        $nonHolder = collect($merchantRoles)->first(
            static fn (string $r): bool => ! in_array($key, $registry->defaultGrantsFor($r), true),
        );

        if ($nonHolder !== null) {
            $membership = denyMembership($merchant, $nonHolder);
            if (in_array($key, $resolver->forMembership($membership), true)) {
                $failures[] = "{$key}: unexpectedly resolved for non-holder {$nonHolder}";
            }

            continue;
        }

        // Held by every merchant role (e.g. reports.view): platform staff is the denier.
        if (in_array($key, $resolver->forPlatformStaff(), true)) {
            $failures[] = "{$key}: unexpectedly resolved for platform staff (expected deny)";
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});

it('denies a suspended and a deactivated membership every permission (empty resolved set)', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $resolver = app(PermissionResolver::class);

    $suspended = MerchantUser::factory()->suspended()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::Finance,
    ]);
    $deactivated = MerchantUser::factory()->deactivated()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::Finance,
    ]);

    expect($resolver->forMembership($suspended))->toBe([]);
    expect($resolver->forMembership($deactivated))->toBe([]);
});
