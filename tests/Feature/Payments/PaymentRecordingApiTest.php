<?php

declare(strict_types=1);

use App\Domain\FinanceOps\Contracts\PeriodLockRepository;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Payments\Models\PaymentAllocation;
use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Payments\Models\PaymentRecordingGroup;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class)->group('payments', 'payment-api');

it('lets Front Office record a single cash payment that stays pending validation', function (): void {
    $scn = paymentScenario(500000);

    $response = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(200000)])
        ->assertCreated();

    expect($response->json('data.status'))->toBe('pending_validation')
        ->and($response->json('data.is_pending_validation'))->toBeTrue()
        ->and($response->json('data.total.amount'))->toBe(200000)
        ->and($response->json('data.components'))->toHaveCount(1)
        ->and($response->json('data.components.0.method'))->toBe('cash')
        ->and($response->json('data.components.0.status'))->toBe('pending_validation')
        ->and($response->json('data.components.0.reference_masked'))->toBeNull();

    // Invoice is UNCHANGED — no validated paid, no status change, no receipt.
    $invoice = Invoice::query()->firstOrFail();
    expect($invoice->validated_paid_minor)->toBe(0)
        ->and($invoice->status)->toBe(InvoiceStatus::Issued);

    expect(PaymentAllocation::query()->count())->toBe(1)
        ->and((int) PaymentAllocation::query()->sum('amount_minor'))->toBe(200000);
});

it('records a split/multi-method group with total equal to the component sum', function (): void {
    $scn = paymentScenario(500000);

    $response = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [
        cashComponent(150000),
        referencedComponent(250000, 'mpesa_offline', 'QGX7YT1ABC'),
    ])->assertCreated();

    expect($response->json('data.total.amount'))->toBe(400000)
        ->and($response->json('data.components'))->toHaveCount(2);

    $group = PaymentRecordingGroup::query()->firstOrFail();
    $componentSum = (int) PaymentRecord::query()->where('payment_recording_group_id', $group->id)->sum('amount_minor');
    expect($group->total_amount_minor)->toBe($componentSum)->toBe(400000);

    // The referenced component exposes only a masked suffix, never the raw reference.
    $masked = collect($response->json('data.components'))->firstWhere('method', 'mpesa_offline')['reference_masked'];
    expect($masked)->toContain('•')->toEndWith('1ABC');
});

it('requires an Idempotency-Key and replays the stored response without a second group', function (): void {
    $scn = paymentScenario(500000);
    $key = (string) Str::uuid();

    // Missing key → 422 idempotency_key_required.
    test()->actingAs($scn['frontOffice'], 'sanctum')
        ->postJson("/api/v1/invoices/{$scn['invoice']->ulid}/payment-recording-groups", ['components' => [cashComponent(100000)]])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'idempotency_key_required');

    $first = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)], $key)->assertCreated();
    $replay = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)], $key)->assertCreated();

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and(PaymentRecordingGroup::query()->count())->toBe(1)
        ->and(PaymentRecord::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(1);
});

it('rejects an Idempotency-Key reused with a different payload', function (): void {
    $scn = paymentScenario(500000);
    $key = (string) Str::uuid();

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)], $key)->assertCreated();
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(200000)], $key)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_key_reused_with_different_request');
});

it('rejects an overpayment beyond the invoice balance', function (): void {
    $scn = paymentScenario(500000);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(600000)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payment_overpayment');

    expect(PaymentRecordingGroup::query()->count())->toBe(0);
});

it('counts active pending recordings against the available balance', function (): void {
    $scn = paymentScenario(500000);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(300000)])->assertCreated();

    // 300k already pending; a further 300k would exceed the 500k balance.
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(300000)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'payment_overpayment');

    // A 200k top-up (total 500k pending) is exactly allowed.
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(200000)])->assertCreated();
});

it('enforces a single currency matching the invoice', function (): void {
    $scn = paymentScenario(500000);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [
        ['method' => 'cash', 'amount_minor' => 100000, 'currency' => 'USD'],
    ])->assertStatus(422)->assertJsonPath('error.code', 'mixed_currency');
});

it('enforces method-specific reference rules', function (string $method, ?string $reference, ?string $expectedCode): void {
    $scn = paymentScenario(500000);
    $component = ['method' => $method, 'amount_minor' => 100000];
    if ($reference !== null) {
        $component['reference'] = $reference;
    }

    $response = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [$component]);

    if ($expectedCode === null) {
        $response->assertCreated();
    } else {
        $response->assertStatus(422)->assertJsonPath('error.code', $expectedCode);
    }
})->with([
    'cash needs no reference' => ['cash', null, null],
    'mpesa requires a reference' => ['mpesa_offline', null, 'payment_reference_required'],
    'mpesa validates format' => ['mpesa_offline', 'no', 'invalid_payment_reference'],
    'mpesa accepts a valid receipt' => ['mpesa_offline', 'QGX7YT1ABC', null],
    'bank transfer requires a reference' => ['bank_transfer', null, 'payment_reference_required'],
    'bank transfer accepts evidence' => ['bank_transfer', 'DEP-99182', null],
    'card terminal requires evidence' => ['card_terminal', null, 'payment_reference_required'],
    'voucher requires evidence' => ['voucher', null, 'payment_reference_required'],
    'other requires evidence' => ['other', null, 'payment_reference_required'],
    'split_payment is not a component method' => ['split_payment', null, 'invalid_component_method'],
]);

it('refuses recording against a non-recordable invoice state', function (): void {
    $scn = paymentScenario(500000);
    Invoice::query()->whereKey($scn['invoice']->id)->update(['status' => InvoiceStatus::Paid->value]);

    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invoice_not_recordable');
});

it('denies recording to every non-maker role', function (MerchantUserRole $role): void {
    $scn = paymentScenario(500000);
    [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);

    recordPaymentGroup($user, $scn['invoice']->ulid, [cashComponent(100000)])->assertForbidden();
})->with([
    'finance (never the default maker)' => [MerchantUserRole::Finance],
    'branch manager' => [MerchantUserRole::BranchManager],
    'merchant admin' => [MerchantUserRole::MerchantAdmin],
    'hr' => [MerchantUserRole::Hr],
    'personnel' => [MerchantUserRole::Personnel],
    'audit' => [MerchantUserRole::Audit],
]);

it('lets Finance view the pending groups but not Front Office', function (): void {
    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])->assertCreated();

    test()->actingAs($scn['finance'], 'sanctum')->getJson('/api/v1/payment-recording-groups')
        ->assertOk()->assertJsonCount(1, 'data');

    // Front Office holds no customer_payment.view.
    test()->actingAs($scn['frontOffice'], 'sanctum')->getJson('/api/v1/payment-recording-groups')
        ->assertForbidden();
});

it('lets Finance record through the distinct maker-exception capability', function (): void {
    $scn = paymentScenario(500000);

    $response = recordPaymentGroup($scn['finance'], $scn['invoice']->ulid, [cashComponent(100000)], null, '/exception')
        ->assertCreated();

    $group = PaymentRecordingGroup::query()->firstOrFail();
    expect($response->json('data.status'))->toBe('pending_validation')
        ->and($group->maker_user_id)->toBe($scn['finance']->id);

    // Front Office cannot use the exception route.
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)], null, '/exception')
        ->assertForbidden();
});

it('returns 404 for a foreign-tenant invoice (no existence leak)', function (): void {
    $scn = paymentScenario(500000);
    $other = paymentScenario(500000);

    recordPaymentGroup($other['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])
        ->assertNotFound();
});

it('returns 423 when the financial period is locked', function (): void {
    app()->bind(PeriodLockRepository::class, fn () => new class implements PeriodLockRepository
    {
        public function isLocked(int $merchantId, ?int $branchId, CarbonInterface $businessDate): bool
        {
            return true;
        }
    });

    $scn = paymentScenario(500000);
    recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])
        ->assertStatus(423)
        ->assertJsonPath('error.code', 'financial_period_locked');
});

it('exposes no validation, receipt, or destructive payment route', function (): void {
    $scn = paymentScenario(500000);
    $groupUlid = recordPaymentGroup($scn['frontOffice'], $scn['invoice']->ulid, [cashComponent(100000)])
        ->assertCreated()->json('data.id');

    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/payment-recording-groups/{$groupUlid}/validate")->assertNotFound();
    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/payment-recording-groups/{$groupUlid}/reject")->assertNotFound();
    test()->actingAs($scn['finance'], 'sanctum')->postJson('/api/v1/receipts')->assertNotFound();
    test()->actingAs($scn['finance'], 'sanctum')->deleteJson("/api/v1/payment-recording-groups/{$groupUlid}")->assertStatus(405);
});
