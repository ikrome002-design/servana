<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Hr\Models\StaffInvitation;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('ui11', 'hr-experience');

it('returns a truthful assigned-branch HR workspace without foreign-branch or gated fabrication', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Westlands Studio',
        'code' => 'WST',
        'town' => 'Nairobi',
    ]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    branchStaff($merchant, $otherBranch, MerchantUserRole::Personnel);
    StaffInvitation::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $branch->id]);
    StaffInvitation::factory()->create(['merchant_id' => $merchant->id, 'branch_id' => $otherBranch->id]);

    $response = $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/hr/workspace')
        ->assertOk()
        ->assertJsonPath('data.overview.branch.id', $branch->ulid)
        ->assertJsonPath('data.overview.branch.name', 'Westlands Studio')
        ->assertJsonPath('data.overview.staff.total', 2)
        ->assertJsonPath('data.overview.staff.pending_invitations', 1)
        ->assertJsonPath('data.overview.readiness.without_eligibility', 2)
        ->assertJsonPath('data.overview.readiness.without_availability', 2)
        ->assertJsonPath('data.overview.readiness.without_compensation', 2)
        ->assertJsonPath('data.overview.reports.available', false)
        ->assertJsonPath('data.overview.notifications.available', false);

    expect((string) $response->getContent())
        ->toContain('External Gate W')
        ->not->toContain($otherBranch->ulid)
        ->not->toContain('earnings')
        ->not->toContain('notification_count');
});

it('serves only minimal active service options from HR assigned branches', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    $service = Service::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'name' => 'Natural hair consultation',
        'price_minor' => 125_000,
    ]);
    $foreign = Service::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $otherBranch->id,
    ]);

    $response = $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/hr/service-options')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ulid', $service->ulid)
        ->assertJsonPath('data.0.name', 'Natural hair consultation');

    expect((string) $response->getContent())
        ->not->toContain((string) $service->price_minor)
        ->not->toContain($foreign->ulid)
        ->not->toContain('duration_minutes');

    $this->actingAs($manager, 'sanctum')
        ->getJson('/api/v1/hr/service-options')
        ->assertForbidden();
});

it('serves a paginated filtered and server-masked HR audit projection', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $otherBranch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);

    $event = AuditLog::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'action' => 'membership.suspended',
        'actor_label' => 'private@example.test',
        'context' => ['email' => 'staff@example.test', 'reference' => 'SECRET-REFERENCE-1234'],
    ]);
    AuditLog::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $branch->id,
        'action' => 'invoice.issued',
    ]);
    $foreign = AuditLog::factory()->create([
        'merchant_id' => $merchant->id,
        'branch_id' => $otherBranch->id,
        'action' => 'membership.suspended',
    ]);

    $response = $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/hr/audit-activity?domain=staff&per_page=25&sort=-created_at')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $event->ulid)
        ->assertJsonPath('data.0.action', 'membership.suspended');

    expect((string) $response->getContent())
        ->not->toContain('private@example.test')
        ->not->toContain('staff@example.test')
        ->not->toContain('SECRET-REFERENCE-1234')
        ->not->toContain($foreign->ulid);

    $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/hr/audit-activity?per_page=101')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');
});

it('validates and applies server-side HR roster search, role, status and employment filters', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [, , $amina] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);
    $amina->update(['display_name' => 'Amina Wanjiku', 'first_name' => 'Amina', 'last_name' => 'Wanjiku']);
    branchStaff($merchant, $branch, MerchantUserRole::Finance);

    $this->actingAs($hr, 'sanctum')
        ->getJson('/api/v1/staff?search=amina&role=personnel&status=active&employment_status=employed&per_page=20')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $amina->ulid);

    foreach (['role=owner', 'status=enabled', 'employment_status=active', 'search='.str_repeat('x', 121)] as $query) {
        $this->actingAs($hr, 'sanctum')
            ->getJson('/api/v1/staff?'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
    }
});

it('validates lifecycle reasons through a Form Request and preserves wrong-role denial', function (): void {
    $merchant = Merchant::factory()->active()->create();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$hr] = branchStaff($merchant, $branch, MerchantUserRole::Hr);
    [$manager] = branchStaff($merchant, $branch, MerchantUserRole::BranchManager);
    [, , $staff] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/suspend", ['reason' => str_repeat('x', 501)])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed');

    $this->actingAs($manager, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/suspend", ['reason' => 'Wrong authority'])
        ->assertForbidden();

    $this->actingAs($hr, 'sanctum')
        ->postJson("/api/v1/staff/{$staff->ulid}/suspend", ['reason' => 'Temporary leave review'])
        ->assertOk()
        ->assertJsonPath('data.status', 'suspended');
});
