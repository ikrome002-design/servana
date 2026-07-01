<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditSeverity;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'payment-audit');

it('emits customer_payment.recorded (info) with safe, masked context', function (): void {
    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])
        ->assertCreated();

    $log = AuditLog::query()->where('action', 'customer_payment.recorded')->latest('id')->firstOrFail();

    expect($log->merchant_id)->toBe($scn['merchant']->id)
        ->and($log->branch_id)->toBe($scn['branch']->id)
        ->and($log->actor_id)->toBe($scn['frontOffice']->id)
        ->and($log->severity)->toBe(AuditSeverity::Info)
        ->and($log->context['group_id'])->toBeString()
        ->and($log->context['component_methods'])->toContain('mpesa_offline')
        ->and($log->context['total_amount_minor'])->toBe(100000)
        ->and($log->context['currency'])->toBe('KES');

    // No raw/normalized reference and no client contact ever enter the audit context.
    $json = (string) json_encode($log->context);
    expect($json)->not->toContain('QGX7YT1ABC')
        ->not->toContain('reference_normalized')
        ->not->toContain('reference_display');
});

it('emits customer_payment.recorded_exception (high) for a Finance maker exception', function (): void {
    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['finance'], $scn['invoice']->ulid, [cashComponent(100000)], null, '/exception')
        ->assertCreated();

    $log = AuditLog::query()->where('action', 'customer_payment.recorded_exception')->latest('id')->firstOrFail();
    expect($log->severity)->toBe(AuditSeverity::High)
        ->and($log->actor_id)->toBe($scn['finance']->id);
});

it('emits customer_payment.duplicate_suspected (warning) with a masked reference only', function (): void {
    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertCreated();
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertStatus(409);

    $log = AuditLog::query()->where('action', 'customer_payment.duplicate_suspected')->latest('id')->firstOrFail();
    expect($log->severity)->toBe(AuditSeverity::Warning)
        ->and($log->context['masked_reference'])->toContain('•');
    expect((string) json_encode($log->context))->not->toContain('QGX7YT1ABC');
});

it('emits customer_payment.duplicate_override_approved (high) with a sanitized reason', function (): void {
    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertCreated();
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertStatus(409);
    $check = PaymentReferenceCheck::query()->where('result', 'duplicate_suspected')->firstOrFail();

    test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-reference-checks/{$check->ulid}/override", ['reason' => 'Verified distinct payment.'])
        ->assertCreated();

    $log = AuditLog::query()->where('action', 'customer_payment.duplicate_override_approved')->latest('id')->firstOrFail();
    expect($log->severity)->toBe(AuditSeverity::High)
        ->and($log->context['override_reason'])->toBe('Verified distinct payment.')
        ->and($log->actor_id)->toBe($scn['finance']->id);
});

it('writes no success event when the recording rolls back (overpayment)', function (): void {
    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(600000)])->assertStatus(422);

    expect(AuditLog::query()->where('action', 'customer_payment.recorded')->exists())->toBeFalse();
});
