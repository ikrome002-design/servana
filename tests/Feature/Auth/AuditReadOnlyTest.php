<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('auth', 'permissions', 'authority', 'audit');

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
});

it('denies an audit user creating a branch', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    $this->actingAs($audit, 'sanctum')
        ->postJson('/api/v1/branches', ['name' => 'X', 'code' => 'AUD001'])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');
});

it('denies an audit user any staff write and audits the denied attempt', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);
    [, , $personnel] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($audit, 'sanctum')
        ->postJson("/api/v1/staff/{$personnel->ulid}/permissions", [
            'permission' => 'clients.view',
            'effect' => 'deny',
        ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'permission_denied');

    expect(AuditLog::query()->where('action', 'permission.write_denied')->exists())->toBeTrue();
});

it('denies an audit user suspending staff', function (): void {
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);
    [, , $personnel] = branchStaff($merchant, $branch, MerchantUserRole::Personnel);

    $this->actingAs($audit, 'sanctum')
        ->postJson("/api/v1/staff/{$personnel->ulid}/suspend")
        ->assertStatus(403);
});

it('makes audit_logs append-only — UPDATE and DELETE are blocked at the database', function (): void {
    $log = app(AuditRecorder::class)
        ->record('test.event', AuditSeverity::Info);

    expect(fn () => AuditLog::query()->whereKey($log->id)->update(['action' => 'tampered']))
        ->toThrow(QueryException::class);

    expect(fn () => AuditLog::query()->whereKey($log->id)->delete())
        ->toThrow(QueryException::class);
});
