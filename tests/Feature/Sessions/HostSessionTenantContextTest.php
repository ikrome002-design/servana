<?php

declare(strict_types=1);

use App\Domain\Branches\Models\BranchUserAssignment;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Merchants\Models\MerchantUser;
use App\Domain\Sessions\Models\HostSession;
use App\Domain\Sessions\Services\SessionFamilyService;
use App\Domain\Sessions\Support\SessionBinding;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantContextResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Tenant context follows the host-session binding (Phase UI-03)
|--------------------------------------------------------------------------
|
| REGRESSION, found by the UI-03 deployed-origin browser proof.
|
| `TenantContextResolver` resolved the merchant with `activeMembership()` — the FIRST active
| membership. `merchant_users` is UNIQUE(merchant, user), so a user with two memberships holds them
| in two different MERCHANTS, and "the first one" is independent of which account the session is
| actually in. After a context handoff to the Audit account of merchant B, `/api/v1/me` on
| `audit.servana.test` still reported merchant A and merchant A's Front Office permissions.
|
| The fix makes the server-created `host_sessions` row — never the Host header, never anything the
| browser sent — say which account the session is operating as, with the membership re-read and
| re-verified from canonical state on every request.
|
| ADR-017 is untouched: the host still grants nothing. The binding is a server-created identifier
| for the intended context, and every authority is freshly resolved from it.
*/

uses(RefreshDatabase::class)->group('auth');

/** A user holding Front Office in one merchant and Audit in another, both branch-assigned. */
function multiContextUser(): array
{
    $user = User::factory()->create();

    $build = function (string $name, MerchantUserRole $role) use ($user): array {
        $merchant = Merchant::factory()->active()->create(['name' => $name]);
        $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
        $membership = MerchantUser::factory()->create([
            'merchant_id' => $merchant->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
        BranchUserAssignment::factory()->create([
            'merchant_user_id' => $membership->id,
            'branch_id' => $branch->id,
            'merchant_id' => $merchant->id,
        ]);

        return [$merchant, $membership, $branch];
    };

    [$sourceMerchant, $sourceMembership] = $build('Ctx Source', MerchantUserRole::FrontOffice);
    [$targetMerchant, $targetMembership] = $build('Ctx Target', MerchantUserRole::Audit);

    return compact('user', 'sourceMerchant', 'sourceMembership', 'targetMerchant', 'targetMembership');
}

/** Resolve as if the session were bound to `$bound`, or unbound when null. */
function resolveWith(?MerchantUser $bound, User $user): TenantContext
{
    return resolveBinding($bound === null ? SessionBinding::absent() : SessionBinding::merchant($bound), $user);
}

function resolveBinding(SessionBinding $binding, User $user): TenantContext
{
    $context = app(TenantContext::class);
    app(TenantContextResolver::class)->populate($context, $user, $binding);

    return $context;
}

it('resolves the bound membership rather than the first active one', function (): void {
    ['user' => $user, 'targetMembership' => $target, 'targetMerchant' => $targetMerchant] = multiContextUser();

    $context = resolveWith($target, $user);

    expect($context->merchant()?->id)->toBe($targetMerchant->id)
        ->and($context->merchantUser()?->id)->toBe($target->id);
});

it('still resolves the source membership for the source binding', function (): void {
    ['user' => $user, 'sourceMembership' => $source, 'sourceMerchant' => $sourceMerchant] = multiContextUser();

    $context = resolveWith($source, $user);

    expect($context->merchant()?->id)->toBe($sourceMerchant->id)
        ->and($context->merchantUser()?->id)->toBe($source->id);
});

it('resolves permissions freshly for the bound membership, so source-only permissions are absent', function (): void {
    $fixture = multiContextUser();

    $sourcePermissions = resolveWith($fixture['sourceMembership'], $fixture['user'])->permissions();
    $targetPermissions = resolveWith($fixture['targetMembership'], $fixture['user'])->permissions();

    // Front Office and Audit are deliberately different authorities (Plan §10.2): Audit is
    // read-only and never records payments.
    $sourceOnly = array_diff($sourcePermissions, $targetPermissions);

    expect($sourceOnly)->not->toBeEmpty()
        ->and(array_intersect($sourceOnly, $targetPermissions))->toBeEmpty();
});

it('leaves the context EMPTY when the binding does not belong to the user', function (): void {
    ['user' => $user] = multiContextUser();
    $stranger = multiContextUser();

    $context = resolveWith($stranger['targetMembership'], $user);

    expect($context->merchant())->toBeNull()
        ->and($context->merchantUser())->toBeNull()
        ->and($context->permissions())->toBeEmpty();
});

it('leaves the context EMPTY when the bound membership is no longer active', function (): void {
    ['user' => $user, 'targetMembership' => $target] = multiContextUser();

    $target->forceFill(['status' => MerchantUserStatus::Suspended])->save();

    $context = resolveWith($target->fresh(), $user);

    expect($context->merchant())->toBeNull()
        ->and($context->merchantUser())->toBeNull();
});

it('never falls back to another membership when the binding is unusable', function (): void {
    ['user' => $user, 'targetMembership' => $target, 'sourceMerchant' => $sourceMerchant] = multiContextUser();

    $target->forceFill(['status' => MerchantUserStatus::Suspended])->save();

    $context = resolveWith($target->fresh(), $user);

    // The user still holds an ACTIVE Front Office membership. Falling back to it would hand a
    // request addressed to the Audit account the authority of the Front Office account.
    expect($context->merchant()?->id)->not->toBe($sourceMerchant->id)
        ->and($context->merchant())->toBeNull();
});

it('fails closed — never falls back — when a required binding is missing or does not agree', function (): void {
    ['user' => $user, 'sourceMerchant' => $sourceMerchant] = multiContextUser();

    // A browser account request whose session carries no server-created binding, or one whose
    // binding names another host/user/family. Both must resolve NOTHING.
    $context = resolveBinding(SessionBinding::mismatch(), $user);

    expect($context->merchant())->toBeNull()
        ->and($context->merchantUser())->toBeNull()
        ->and($context->permissions())->toBeEmpty()
        // The user genuinely holds this merchant. Falling back to it is exactly the defect.
        ->and($context->merchant()?->id)->not->toBe($sourceMerchant->id);
});

it('resolves platform authority from the user for an agreeing platform binding', function (): void {
    $user = User::factory()->platformStaff()->create();

    $context = resolveBinding(SessionBinding::platform(), $user);

    expect($context->isPlatformStaff())->toBeTrue()
        ->and($context->merchant())->toBeNull();
});

it('keeps the existing single-membership contract when nothing is bound', function (): void {
    $user = User::factory()->create();
    $merchant = Merchant::factory()->active()->create();
    $membership = MerchantUser::factory()->create([
        'merchant_id' => $merchant->id,
        'user_id' => $user->id,
        'role' => MerchantUserRole::MerchantAdmin,
    ]);

    // No binding: machine traffic, token callers and the existing test contract all land here.
    $context = resolveWith(null, $user);

    expect($context->merchant()?->id)->toBe($merchant->id)
        ->and($context->merchantUser()?->id)->toBe($membership->id);
});

it('resolves platform staff from the user record, never from a merchant binding', function (): void {
    $user = User::factory()->platformStaff()->create();

    $context = resolveWith(null, $user);

    expect($context->isPlatformStaff())->toBeTrue()
        ->and($context->merchant())->toBeNull();
});

it('stores no permission snapshot on the host session', function (): void {
    $families = app(SessionFamilyService::class);
    ['user' => $user, 'targetMembership' => $target] = multiContextUser();

    $family = $families->startFamily($user);
    $hostSession = HostSession::factory()->create([
        'session_family_id' => $family->id,
        'user_id' => $user->id,
        'merchant_id' => $target->merchant_id,
        'merchant_user_id' => $target->id,
        'account_key' => 'merchant_audit',
    ]);

    $columns = array_keys($hostSession->getAttributes());

    expect($columns)->not->toContain('permissions')
        ->and($columns)->not->toContain('abilities')
        ->and($columns)->not->toContain('grants');
});

it('reflects a permission change on the next resolution without a new binding', function (): void {
    ['user' => $user, 'targetMembership' => $target] = multiContextUser();

    $before = resolveWith($target, $user)->permissions();

    // Role changes are canonical authority; the binding row is untouched.
    $target->forceFill(['role' => MerchantUserRole::Hr])->save();

    $after = resolveWith($target->fresh(), $user)->permissions();

    expect($after)->not->toBe($before);
});
