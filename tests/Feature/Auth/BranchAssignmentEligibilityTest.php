<?php

declare(strict_types=1);

use App\Domain\Auth\Notifications\MagicLoginLinkNotification;
use App\Domain\Branches\Enums\BranchUserAssignmentStatus;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('auth', 'hr');

/*
 | Magic Link eligibility check 6 (Scope §2.3 / Plan §9.1), wired in Phase 7: a
 | branch-scoped role needs an active branch_user_assignment to receive a link.
 | Merchant Admin is exempt.
 */

it('sends a link to a branch-scoped user with an active assignment', function (): void {
    Notification::fake();
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$user] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice, assigned: true);
    $user->update(['email' => 'fo@salon.co.ke']);

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'fo@salon.co.ke'])->assertStatus(202);

    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('sends no link to a branch-scoped user without an active assignment', function (): void {
    Notification::fake();
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$user] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice, assigned: false);
    $user->update(['email' => 'unassigned@salon.co.ke']);

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'unassigned@salon.co.ke'])->assertStatus(202);

    Notification::assertNothingSent();
});

it('sends a link to a merchant admin without any branch assignment', function (): void {
    Notification::fake();
    $user = eligibleOwner('admin@salon.co.ke'); // admin, no assignment

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'admin@salon.co.ke'])->assertStatus(202);

    Notification::assertSentTo($user, MagicLoginLinkNotification::class);
});

it('stops sending a link once the branch assignment is revoked', function (): void {
    Notification::fake();
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$user, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice, assigned: true);
    $user->update(['email' => 'revoked@salon.co.ke']);

    $membership->branchAssignments()->update([
        'status' => BranchUserAssignmentStatus::Revoked->value,
        'revoked_at' => now(),
    ]);

    $this->postJson('/api/v1/auth/magic-link', ['email' => 'revoked@salon.co.ke'])->assertStatus(202);

    Notification::assertNothingSent();
});
