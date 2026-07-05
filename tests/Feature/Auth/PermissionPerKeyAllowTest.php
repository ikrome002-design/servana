<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
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
 | §19.5: `PermissionMatrix/{key}_allows` — every ACTIVE key resolves for a
 | principal that legitimately holds it (role default, override grant, or platform
 | staff). Data-driven over the whole active catalogue in one pass.
 */

function allowMembership(Merchant $merchant, string $roleKey): MerchantUser
{
    return MerchantUser::factory()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::from($roleKey),
    ]);
}

it('resolves every active permission key for a legitimate holder', function (): void {
    $this->seed(PermissionSeeder::class);
    $registry = app(PermissionRegistry::class);
    $resolver = app(PermissionResolver::class);
    $matrix = app(PermissionMatrix::class);
    $merchant = Merchant::factory()->active()->create();
    $permissionIds = Permission::query()->pluck('id', 'key');

    $merchantRoles = array_values(array_filter(
        $registry->roleKeys(),
        static fn (string $r): bool => $r !== PermissionRegistry::ROLE_SUPER_ADMIN,
    ));

    $failures = [];
    foreach ($matrix->activeKeys() as $key) {
        // Platform keys are super_admin (platform-staff) defaults.
        if (str_starts_with($key, 'platform.')) {
            if (! in_array($key, $resolver->forPlatformStaff(), true)) {
                $failures[] = "{$key}: not resolved for platform staff";
            }

            continue;
        }

        $defaultHolder = collect($merchantRoles)->first(
            static fn (string $r): bool => in_array($key, $registry->defaultGrantsFor($r), true),
        );

        if ($defaultHolder !== null) {
            $membership = allowMembership($merchant, $defaultHolder);
            if (! in_array($key, $resolver->forMembership($membership), true)) {
                $failures[] = "{$key}: not resolved for default holder {$defaultHolder}";
            }

            continue;
        }

        // Override-only (grantable) key: grant it to a role that may hold it.
        $grantableHolder = collect($merchantRoles)->first(
            static fn (string $r): bool => $registry->isGrantableFor($r, $key),
        );
        expect($grantableHolder)->not->toBeNull("{$key}: no default or grantable holder");

        $membership = allowMembership($merchant, $grantableHolder);
        MerchantUserPermissionOverride::query()->create([
            'merchant_id' => $merchant->id,
            'merchant_user_id' => $membership->id,
            'permission_id' => $permissionIds[$key],
            'effect' => PermissionOverrideEffect::Grant,
            'granted_by' => User::factory()->create()->id,
            'reason' => 'per-key allow test',
        ]);

        if (! in_array($key, $resolver->forMembership($membership->fresh()), true)) {
            $failures[] = "{$key}: not resolved after grant override for {$grantableHolder}";
        }
    }

    expect($failures)->toBe([], implode("\n", $failures));
});
