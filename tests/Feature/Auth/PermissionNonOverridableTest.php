<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionRegistry;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix');

/*
 | §19.4 hard non-overridable rules (enforced in code + tests).
 */

beforeEach(fn () => $this->seed(PermissionSeeder::class));

it('never lets the Audit role gain a mutating merchant capability via a crafted grant', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $audit = MerchantUser::factory()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::Audit,
    ]);
    $resolver = app(PermissionResolver::class);

    // Even a directly-crafted grant row for a mutating merchant key is stripped for
    // the read-only Audit role (stripMutatingNonDefaults).
    MerchantUserPermissionOverride::query()->create([
        'merchant_id' => $merchant->id,
        'merchant_user_id' => $audit->id,
        'permission_id' => Permission::query()->where('key', 'customer_payment.record')->value('id'),
        'effect' => PermissionOverrideEffect::Grant,
        'granted_by' => User::factory()->create()->id,
        'reason' => 'non-overridable test',
    ]);

    $resolved = $resolver->forMembership($audit->fresh());
    expect($resolved)->not->toContain('customer_payment.record');

    // Every mutating key the Audit role does hold is one of its in-domain review writes.
    $registry = app(PermissionRegistry::class);
    $auditInDomainWrites = ['audit.flagged_event.create', 'audit.flagged_event.update_status', 'audit.flagged_event.resolve_metadata', 'audit.export'];
    foreach ($resolved as $key) {
        if ($registry->isMutating($key)) {
            expect($key)->toBeIn($auditInDomainWrites);
        }
    }
});

it('provides no contact/personnel export permission anywhere in the catalogue', function (): void {
    $registry = app(PermissionRegistry::class);

    foreach ($registry->permissionKeys() as $key) {
        expect(str_contains($key, 'contact') && str_contains($key, 'export'))->toBeFalse("found a contact-export key {$key}");
    }

    // Personnel resolves no export key of any kind (guardrail §6.8 / Plan §10.2).
    $personnel = $registry->defaultGrantsFor('personnel');
    foreach ($personnel as $key) {
        expect(str_starts_with($key, 'exports.'))->toBeFalse();
    }
});

it('keeps Super Administrator to platform keys only (no merchant-operational leak)', function (): void {
    $resolver = app(PermissionResolver::class);

    foreach ($resolver->forPlatformStaff() as $key) {
        expect(str_starts_with($key, 'platform.'))->toBeTrue("super_admin resolved a non-platform key {$key}");
    }
});
