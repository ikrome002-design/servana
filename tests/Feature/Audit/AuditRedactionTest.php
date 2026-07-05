<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('audit', 'security');

/*
 | Phase 19 (Plan §9.13, §74; ADR-008): every value returned by an audit read is
 | masked/redacted SERVER-SIDE. Sensitive context keys (tokens, references, phones,
 | restricted compensation, emails) never leave the API in the clear — defense in
 | depth over the accurate-but-sensitive values that legitimately live in a row.
 */

it('redacts and masks sensitive context values in a branch-events read', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    app(AuditRecorder::class)->record(
        AuditEvent::BranchProfileUpdated, $admin, $merchant->id, $branch->id, $branch,
        [
            'token' => 'super-secret-token-value',
            'reference' => 'REF-ABCD-9999',
            'phone' => '+254712345678',
            'gross_pay' => '5000000',
            'email' => 'owner@salon.co.ke',
            'nested' => ['session' => 'sess-plain', 'note' => 'safe-note'],
        ],
    );

    $response = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs')->assertOk();
    $body = $response->getContent();
    $context = $response->json('data.0.context');

    // Fully redacted secrets — never displayable, at any nesting depth.
    expect($body)->not->toContain('super-secret-token-value')
        ->and($body)->not->toContain('sess-plain')
        ->and($context['token'])->toBe('[redacted]')
        ->and($context['nested']['session'])->toBe('[redacted]');

    // Restricted compensation value.
    expect($context['gross_pay'])->toBe('[restricted]');

    // Partial masks that correlate but do not enumerate.
    expect($body)->not->toContain('REF-ABCD-9999')
        ->and($context['reference'])->toContain('9999')->and($context['reference'])->toStartWith('***');
    expect($body)->not->toContain('+254712345678')
        ->and($context['phone'])->toContain('678');
    expect($body)->not->toContain('owner@salon.co.ke')
        ->and($context['email'])->toContain('***');

    // Non-sensitive values pass through unchanged.
    expect($context['nested']['note'])->toBe('safe-note');
});

it('never exposes internal ids or hash-chain columns in a read payload', function (): void {
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    $row = app(AuditRecorder::class)->record(AuditEvent::BranchDayOpened, $admin, $merchant->id, $branch->id, $branch);

    $payload = $this->actingAs($audit, 'sanctum')->getJson('/api/v1/audit-logs')
        ->assertOk()->json('data.0');

    // ULID external id only; no sequential id, hash, prev_hash, ip, or actor_id.
    expect($payload['id'])->toBe($row->ulid);
    foreach (['hash', 'prev_hash', 'previous_hash', 'ip', 'ip_address', 'actor_id', 'auditable_id'] as $forbidden) {
        expect($payload)->not->toHaveKey($forbidden);
    }
    // The sequential primary key is never the external id.
    expect((string) $payload['id'])->not->toBe((string) $row->id);
});
