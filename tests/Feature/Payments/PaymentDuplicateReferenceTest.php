<?php

declare(strict_types=1);

use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Enums\PaymentRecordingGroupStatus;
use App\Domain\Payments\Enums\PaymentReferenceCheckResult;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use App\Domain\Payments\Models\PaymentReferenceCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'payment-duplicate');

/** Record a unique reference, then a duplicate; returns the held group + its duplicate check. */
function recordDuplicate(array $scn, string $reference = 'QGX7YT1ABC'): array
{
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', $reference)])
        ->assertCreated();

    $response = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', $reference)]);

    return [$response, $scn];
}

it('detects a duplicate reference durably and returns a masked 409 conflict', function (): void {
    $scn = paymentScenario(500000);
    [$response] = recordDuplicate($scn);

    $response->assertStatus(409)
        ->assertJsonPath('error.code', 'payment_reference_duplicate_suspected');

    expect($response->json('error.meta.group_id'))->toBeString()
        ->and($response->json('error.meta.masked_reference'))->toContain('•')
        ->and($response->json('error.meta.masked_reference'))->toEndWith('1ABC');

    // The held group is durable and remains `recorded` (NOT pending_validation).
    $held = PaymentRecordingGroup::query()->where('ulid', $response->json('error.meta.group_id'))->firstOrFail();
    expect($held->status)->toBe(PaymentRecordingGroupStatus::Recorded);

    // A durable duplicate_suspected check exists, pointing at the prior record.
    $dup = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();
    expect($dup->matched_payment_record_id)->not->toBeNull();
});

it('never leaks the raw reference, SQLSTATE, or a constraint name in the conflict', function (): void {
    $scn = paymentScenario(500000);
    [$response] = recordDuplicate($scn, 'QGX7YT1ABC');

    $body = $response->getContent();
    expect($body)->not->toContain('QGX7YT1ABC')
        ->not->toContain('23505')
        ->not->toContain('payment_reference_checks_unique_reservation');
});

it('lets authorized Finance override a duplicate with a reason and advances the group', function (): void {
    $scn = paymentScenario(500000);
    [$response] = recordDuplicate($scn);
    $groupUlid = $response->json('error.meta.group_id');

    $check = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();

    test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-reference-checks/{$check->ulid}/override", ['reason' => 'Confirmed distinct M-Pesa payment with the client.'])
        ->assertCreated()
        ->assertJsonPath('data.result', 'override_approved');

    // The held group advances to pending_validation; the original reference is untouched.
    $group = PaymentRecordingGroup::query()->where('ulid', $groupUlid)->firstOrFail();
    expect($group->status)->toBe(PaymentRecordingGroupStatus::PendingValidation);

    $original = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();
    expect($original->override_by)->toBeNull(); // the suspected row is preserved, not edited
    expect(PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::OverrideApproved->value)->count())->toBe(1);
});

it('requires a non-empty reason to override', function (): void {
    $scn = paymentScenario(500000);
    [$response] = recordDuplicate($scn);
    $check = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();

    test()->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-reference-checks/{$check->ulid}/override", ['reason' => ''])
        ->assertStatus(422);
});

it('denies the override to Front Office and other non-Finance roles', function (MerchantUserRole $role): void {
    $scn = paymentScenario(500000);
    [$response] = recordDuplicate($scn);
    $check = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();
    [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);

    test()->actingAs($user, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-reference-checks/{$check->ulid}/override", ['reason' => 'Attempted override.'])
        ->assertForbidden();
})->with([
    'front office (the maker role)' => [MerchantUserRole::FrontOffice],
    'branch manager' => [MerchantUserRole::BranchManager],
    'personnel' => [MerchantUserRole::Personnel],
]);

it('requires a fresh MFA step-up to override', function (): void {
    $scn = paymentScenario(500000);
    [$response] = recordDuplicate($scn);
    $check = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();

    // Finance has a confirmed MFA credential but a STALE step-up assertion — the
    // duplicate override must still be denied (re-challenge), not proceed.
    confirmedTotp($scn['finance']);
    $stale = now()->subMinutes(30)->getTimestamp();

    test()->statefulMfa($stale)->actingAs($scn['finance'], 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-reference-checks/{$check->ulid}/override", ['reason' => 'No fresh step-up present.'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'step_up_required');
});

it('forbids the recording maker from overriding their own exception group (maker is checker)', function (): void {
    $scn = paymentScenario(500000);
    [$financeMaker] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Finance);

    // A first unique reference, then the SAME reference recorded by Finance as a maker exception.
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertCreated();
    recordPaymentGroup($financeMaker, $scn['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')], null, '/exception')
        ->assertStatus(409);

    $check = PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->firstOrFail();

    // The same Finance user who recorded it may not override it.
    test()->actingAs($financeMaker, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/payment-reference-checks/{$check->ulid}/override", ['reason' => 'Trying to self-clear.'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'maker_is_checker');
});

it('does not conflict across different tenants for the same reference', function (): void {
    $a = paymentScenario(500000);
    $b = paymentScenario(500000);

    recordPaymentGroup($a['frontOffice'], $a['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertCreated();
    // Same reference, different merchant → unique (no cross-tenant conflict).
    recordPaymentGroup($b['frontOffice'], $b['invoice']->ulid, [referencedComponent(100000, 'mpesa_offline', 'QGX7YT1ABC')])->assertCreated();

    expect(PaymentReferenceCheck::query()->where('result', PaymentReferenceCheckResult::DuplicateSuspected->value)->count())->toBe(0);
});
