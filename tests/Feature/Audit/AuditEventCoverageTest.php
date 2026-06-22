<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Actions\ArchiveBranch;
use App\Domain\Branches\Actions\CloseBranchDay;
use App\Domain\Branches\Actions\CreateBranch;
use App\Domain\Branches\Actions\OpenBranchDay;
use App\Domain\Branches\Actions\UpdateBranch;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Actions\AcceptStaffInvitation;
use App\Domain\Hr\Actions\CreateStaffInvitation;
use App\Domain\Hr\Actions\RevokeStaffInvitation;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Hr\Services\StaffLifecycleService;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Domain\Onboarding\Actions\RegisterMerchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('audit');

/*
 | R2 event-coverage: every currently-implemented audited transition emits a
 | typed AuditRecorder row with the right action, severity, and tenant/branch
 | attribution. These drive the REAL actions/services and assert the stored rows
 | (not source text). Financial/billing/compensation/SMS/file events are out of
 | R2 scope (owning phases 18/19/20/21S/10F).
 */

function lastAudit(string $action): AuditLog
{
    return AuditLog::query()->where('action', $action)->latest('id')->firstOrFail();
}

it('audits the full branch lifecycle with merchant + branch attribution', function (): void {
    [$admin, $merchant] = activeAdmin();

    $branch = app(CreateBranch::class)->handle($merchant, $admin, ['name' => 'Main', 'code' => 'MN1']);
    $created = lastAudit('branch.created');
    expect($created->merchant_id)->toBe($merchant->id)
        ->and($created->branch_id)->toBe($branch->id)
        ->and($created->actor_id)->toBe($admin->id)
        ->and($created->severity)->toBe(AuditSeverity::Info);

    app(UpdateBranch::class)->handle($branch, $admin, ['name' => 'Renamed']);
    $updated = lastAudit('branch.profile_updated');
    expect($updated->branch_id)->toBe($branch->id)
        ->and($updated->context['old_values']['name'])->toBe('Main')
        ->and($updated->context['new_values']['name'])->toBe('Renamed');

    app(OpenBranchDay::class)->handle($branch, $admin);
    expect(lastAudit('branch.day_opened')->branch_id)->toBe($branch->id);

    app(CloseBranchDay::class)->handle($branch, $admin);
    expect(lastAudit('branch.day_closed')->branch_id)->toBe($branch->id);

    app(OpenBranchDay::class)->handle($branch, $admin); // re-open a closed day
    expect(lastAudit('branch.day_reopened')->severity)->toBe(AuditSeverity::Warning);

    // Archive a fresh, blocker-free branch.
    $archivable = app(CreateBranch::class)->handle($merchant, $admin, ['name' => 'Temp', 'code' => 'TMP']);
    app(ArchiveBranch::class)->handle($archivable, $admin, 'closing');
    $archived = lastAudit('branch.archived');
    expect($archived->severity)->toBe(AuditSeverity::High)
        ->and($archived->context['new_values']['status'])->toBe('archived');
});

it('audits invitation create / resend / revoke / accept with masked email', function (): void {
    Notification::fake();
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);

    $invitation = app(CreateStaffInvitation::class)->handle(
        $merchant, $branch, $admin, 'invitee@salon.co.ke', ['role' => MerchantUserRole::FrontOffice],
    );
    $created = lastAudit('invitation.created');
    expect($created->branch_id)->toBe($branch->id)
        ->and($created->context['email'])->toContain('***')
        ->and($created->context['email'])->not->toContain('invitee@salon.co.ke');

    app(CreateStaffInvitation::class)->rotateAndSend($invitation);
    expect(lastAudit('invitation.resent')->branch_id)->toBe($branch->id);

    app(RevokeStaffInvitation::class)->handle($invitation->fresh(), $admin);
    expect(lastAudit('invitation.revoked')->severity)->toBe(AuditSeverity::Warning);

    // Accept a separate invitation with a known token.
    $raw = 'raw-accept-token';
    $accept = StaffInvitation::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'email' => 'newhire@salon.co.ke',
        'role' => MerchantUserRole::FrontOffice,
        'token_hash' => hash('sha256', $raw),
        'expires_at' => now()->addDay(),
    ]);

    app(AcceptStaffInvitation::class)->handle($raw, [
        'first_name' => 'New', 'last_name' => 'Hire', 'phone' => '+254700000111',
    ]);

    expect(lastAudit('invitation.accepted')->branch_id)->toBe($branch->id)
        ->and(lastAudit('membership.created')->context['via'])->toBe('invitation');
    unset($accept);
});

it('audits membership lifecycle and branch assignment via the lifecycle service', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, $membership] = branchStaff($merchant, $branch, MerchantUserRole::FrontOffice);

    $service = app(StaffLifecycleService::class);

    $service->suspend($membership, $admin, 'policy');
    $suspended = lastAudit('membership.suspended');
    expect($suspended->severity)->toBe(AuditSeverity::High)
        ->and($suspended->branch_id)->toBe($branch->id)
        ->and($suspended->context['old_values']['status'])->toBe('active')
        ->and($suspended->context['new_values']['status'])->toBe('suspended');

    $service->activate($membership->fresh(), $admin);
    expect(lastAudit('membership.activated')->branch_id)->toBe($branch->id);

    $service->deactivate($membership->fresh(), $admin);
    expect(lastAudit('membership.deactivated')->severity)->toBe(AuditSeverity::High);

    // Branch assignment grant + revoke on a second branch.
    $branch2 = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, $membership2] = branchStaff($merchant, $branch, MerchantUserRole::Personnel, assigned: false);

    $assignment = $service->assignBranch($membership2, $branch2, $admin);
    expect(lastAudit('branch_assignment.granted')->branch_id)->toBe($branch2->id);

    $service->revokeBranchAssignment($assignment, $admin);
    expect(lastAudit('branch_assignment.revoked')->severity)->toBe(AuditSeverity::Warning);
});

it('audits the founding membership on merchant self-registration', function (): void {
    app(RegisterMerchant::class)->handle('Olive Owner', 'olive@salon.co.ke', 'Olive Spa');

    $log = lastAudit('membership.created');
    expect($log->context['via'])->toBe('self_registration')
        ->and($log->branch_id)->toBeNull()
        ->and($log->merchant_id)->not->toBeNull();
});

it('audits permission overrides and unauthorized access via the HTTP boundary', function (): void {
    $this->seed(PermissionSeeder::class); // override endpoint validates the key against the catalogue
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [, , $finance] = branchStaff($merchant, $branch, MerchantUserRole::Finance);

    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff/{$finance->ulid}/permissions", [
        'permission' => 'refunds.approve', 'effect' => 'grant',
    ])->assertStatus(200);
    expect(lastAudit('permission.override.created')->severity)->toBe(AuditSeverity::High);

    // Foreign-tenant probe → unauthorized_access.
    $foreign = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);
    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/branches/{$foreign->ulid}")->assertStatus(404);
    expect(lastAudit('unauthorized_access')->severity)->toBe(AuditSeverity::High);
});
