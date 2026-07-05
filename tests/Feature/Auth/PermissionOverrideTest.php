<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Auth\Services\PermissionResolver;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'matrix');

/*
 | §19.1/§19.4 override semantics: grant (◐), revoke, deny-beats-grant, and the
 | no-op of a grant on a non-grantable key.
 */

function financeMembership(Merchant $merchant): MerchantUser
{
    return MerchantUser::factory()->create([
        'user_id' => User::factory()->create()->id,
        'merchant_id' => $merchant->id,
        'role' => MerchantUserRole::Finance,
    ]);
}

function overrideRow(MerchantUser $m, string $key, PermissionOverrideEffect $effect): void
{
    MerchantUserPermissionOverride::query()->create([
        'merchant_id' => $m->merchant_id,
        'merchant_user_id' => $m->id,
        'permission_id' => Permission::query()->where('key', $key)->value('id'),
        'effect' => $effect,
        'granted_by' => User::factory()->create()->id,
        'reason' => 'override test',
    ]);
}

beforeEach(fn () => $this->seed(PermissionSeeder::class));

it('adds a grantable key via a grant override (refund.approve)', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $m = financeMembership($merchant);
    $resolver = app(PermissionResolver::class);

    expect($resolver->forMembership($m))->not->toContain('refund.approve');

    overrideRow($m, 'refund.approve', PermissionOverrideEffect::Grant);

    expect($resolver->forMembership($m->fresh()))->toContain('refund.approve');
});

it('revokes a default grant via a deny override (customer_payment.view)', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $m = financeMembership($merchant);
    $resolver = app(PermissionResolver::class);

    expect($resolver->forMembership($m))->toContain('customer_payment.view');

    overrideRow($m, 'customer_payment.view', PermissionOverrideEffect::Deny);

    expect($resolver->forMembership($m->fresh()))->not->toContain('customer_payment.view');
});

it('lets a deny override beat a role default grant (deny beats grant)', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $m = financeMembership($merchant);
    $resolver = app(PermissionResolver::class);

    // period_lock.create is a Finance DEFAULT grant; a deny override removes it —
    // the resolver applies grants then denies, so deny always wins on conflict.
    expect($resolver->forMembership($m))->toContain('period_lock.create');

    overrideRow($m, 'period_lock.create', PermissionOverrideEffect::Deny);

    expect($resolver->forMembership($m->fresh()))->not->toContain('period_lock.create');
});

it('forbids contradictory overrides for one key (single override row per membership/key)', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $m = financeMembership($merchant);

    overrideRow($m, 'refund.approve', PermissionOverrideEffect::Grant);

    // The DB unique (merchant_user_id, permission_id) prevents a second, conflicting row.
    expect(fn () => overrideRow($m, 'refund.approve', PermissionOverrideEffect::Deny))
        ->toThrow(QueryException::class);
});

it('ignores a grant override for a key that is not grantable to the role', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $m = financeMembership($merchant);
    $resolver = app(PermissionResolver::class);

    // staff.invite is not grantable to Finance — a crafted grant row is a no-op.
    overrideRow($m, 'staff.invite', PermissionOverrideEffect::Grant);

    expect($resolver->forMembership($m->fresh()))->not->toContain('staff.invite');
});
