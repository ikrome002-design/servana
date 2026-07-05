<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit');

/*
 | Phase 19 — the Audit role may read masked data and drive the flagged-event review
 | workflow (review metadata only), but may NEVER mutate an operational, financial,
 | identity, authorization, file-source or audit-log source record. Proven at the HTTP
 | boundary against representative mutating endpoints across implemented domains.
 */

/** @return array{audit: User, branch: MerchantBranch, merchant: Merchant} */
function auditActor(): array
{
    [, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    return ['audit' => $audit, 'branch' => $branch, 'merchant' => $merchant];
}

it('denies the Audit role representative source mutations across domains', function (string $method, string $uri): void {
    $scn = auditActor();

    // 403 (permission denied), 404 (route-existence-hiding), and 405 (method blocked) are
    // all hard denials — none reaches business logic, so no source record is ever mutated.
    // What must never happen is a 2xx or a 422, which would mean the audit role passed
    // authorization and reached validation of a mutating endpoint.
    $status = test()->actingAs($scn['audit'], 'sanctum')->json($method, $uri, [])->getStatusCode();

    expect($status)->toBeIn([403, 404, 405]);
})->with([
    'create a branch' => ['POST', '/api/v1/branches'],
    'create a service' => ['POST', '/api/v1/services'],
    'create an invoice' => ['POST', '/api/v1/invoices'],
    'record a payment' => ['POST', '/api/v1/payment-recording-groups'],
    'create a period lock' => ['POST', '/api/v1/period-locks'],
    'request a finance export' => ['POST', '/api/v1/finance-exports'],
    'grant a permission override' => ['POST', '/api/v1/staff/nonexistent-ulid/permissions'],
]);

it('cannot mutate the audit_logs source row through any audit route', function (): void {
    $scn = auditActor();
    $log = AuditLog::factory()->create(['merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id]);
    $before = $log->hash;

    // There is no update/delete route for audit_logs; the read routes are GET-only.
    test()->actingAs($scn['audit'], 'sanctum')->putJson("/api/v1/audit-logs/{$log->ulid}", ['action' => 'tampered'])
        ->assertStatus(405);
    test()->actingAs($scn['audit'], 'sanctum')->deleteJson("/api/v1/audit-logs/{$log->ulid}")
        ->assertStatus(405);

    expect(AuditLog::query()->whereKey($log->id)->firstOrFail()->hash)->toBe($before);
});
