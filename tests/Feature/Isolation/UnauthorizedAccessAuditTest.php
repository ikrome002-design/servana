<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('isolation', 'tenancy', 'audit');

/*
 | Cross-tenant attempts are audited (Plan §8.4, §22.2). A foreign-tenant ULID
 | writes a high-severity `unauthorized_access` row carrying the actor, merchant,
 | model, attempted ULID, and route — but the row of the foreign resource is never
 | linked or leaked, and a genuinely missing ULID is NOT audited.
 */

it('writes a high-severity unauthorized_access row for a foreign branch', function (): void {
    [$admin, $merchant] = activeAdmin();
    $foreign = MerchantBranch::factory()->create([
        'merchant_id' => Merchant::factory()->active()->create()->id,
    ]);

    $this->actingAs($admin, 'sanctum')
        ->getJson("/api/v1/branches/{$foreign->ulid}")
        ->assertStatus(404);

    $log = AuditLog::query()->where('action', 'unauthorized_access')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->severity)->toBe(AuditSeverity::High)
        ->and($log->actor_id)->toBe($admin->id)
        ->and($log->merchant_id)->toBe($merchant->id)
        ->and($log->context['model'])->toBe('MerchantBranch')
        ->and($log->context['attempted_id'])->toBe($foreign->ulid)
        ->and($log->auditable_id)->toBeNull(); // foreign row never linked
});

it('audits a foreign staff and a foreign invitation attempt', function (): void {
    [$admin] = activeAdmin();
    $other = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $other->id]);
    [, , $profile] = branchStaff($other, $branch, MerchantUserRole::FrontOffice);
    $invitation = StaffInvitation::factory()->create(['merchant_id' => $other->id, 'branch_id' => $branch->id]);

    $this->actingAs($admin, 'sanctum')->getJson("/api/v1/staff/{$profile->ulid}")->assertStatus(404);
    $this->actingAs($admin, 'sanctum')->postJson("/api/v1/staff-invitations/{$invitation->ulid}/resend")->assertStatus(404);

    expect(AuditLog::query()->where('action', 'unauthorized_access')->where('context->model', 'StaffProfile')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'unauthorized_access')->where('context->model', 'StaffInvitation')->exists())->toBeTrue();
});

it('does NOT audit a genuinely non-existent ULID', function (): void {
    [$admin] = activeAdmin();

    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/branches/01JZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertStatus(404);

    expect(AuditLog::query()->where('action', 'unauthorized_access')->exists())->toBeFalse();
});
