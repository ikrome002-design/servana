<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'payment-validation');

function correctReference(User $actor, string $recordUlid, string $reference): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-records/{$recordUlid}/correct-reference", ['reference' => $reference]);
}

it('corrects a component reference on a correctable group, preserving original evidence, masked', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [referencedComponent(500000, 'mpesa_offline', 'QGX7YT1ABC')]);

    // Return the group for correction first.
    test()->actingAs($scn['finance'], 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/request-correction", ['reason' => 'wrong reference'])
        ->assertCreated();

    $record = PaymentRecord::query()->firstOrFail();
    $checksBefore = PaymentReferenceCheck::query()->where('payment_record_id', $record->id)->count();

    $response = correctReference($scn['finance'], $record->ulid, 'QNEWREF9XYZ')->assertOk();

    // The masked suffix reflects the NEW reference; the raw/normalized reference is never returned.
    expect($response->json('data.reference_masked'))->toContain('•')->toEndWith('9XYZ');
    expect(json_encode($response->json()))->not->toContain('QNEWREF9XYZ')
        ->and(json_encode($response->json()))->not->toContain('QGX7YT1ABC');

    // A NEW durable reference-check row was added; the original evidence rows are preserved (append-only).
    $checksAfter = PaymentReferenceCheck::query()->where('payment_record_id', $record->id)->count();
    expect($checksAfter)->toBeGreaterThan($checksBefore);

    // The component now carries the new normalized reference.
    $record->refresh();
    expect($record->reference_normalized)->toBe('QNEWREF9XYZ');

    // A masked before/after audit event exists — never the full/normalized reference.
    $audit = AuditLog::query()->where('action', 'customer_payment.reference_corrected')->firstOrFail();
    expect(json_encode($audit->context))->not->toContain('QNEWREF9XYZ')
        ->and(json_encode($audit->context))->not->toContain('QGX7YT1ABC');
});

it('rejects correcting a reference when the group is not correction_required', function (): void {
    $scn = paymentScenario(500000);
    recordPendingGroup($scn, [referencedComponent(500000, 'mpesa_offline', 'QGX7YT1ABC')]);
    $record = PaymentRecord::query()->firstOrFail();

    // The group is pending_validation (not correction_required).
    correctReference($scn['finance'], $record->ulid, 'QNEWREF9XYZ')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'group_not_correctable');
});

it('forbids Front Office from correcting a reference (403)', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [referencedComponent(500000, 'mpesa_offline', 'QGX7YT1ABC')]);
    test()->actingAs($scn['finance'], 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-recording-groups/{$groupUlid}/request-correction", ['reason' => 'wrong reference'])->assertCreated();

    $record = PaymentRecord::query()->firstOrFail();
    correctReference($scn['frontOffice'], $record->ulid, 'QNEWREF9XYZ')->assertForbidden();
});
