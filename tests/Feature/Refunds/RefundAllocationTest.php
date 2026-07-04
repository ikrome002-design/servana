<?php

declare(strict_types=1);

use App\Domain\Payments\Models\PaymentRecord;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Refunds\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'refunds');

beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

function validatedComponent(int $amount = 500000): array
{
    $scn = paymentScenario($amount);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent($amount)]))->assertCreated();

    return [PaymentRecord::query()->where('merchant_id', $scn['merchant']->id)->firstOrFail(), $scn];
}

function postRefund(User $actor, string $componentUlid, int $amount): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/refunds', ['payment_record' => $componentUlid, 'amount_minor' => $amount, 'method' => 'cash', 'reason' => 'return']);
}

it('rejects a refund exceeding the validated component amount', function (): void {
    [$component, $scn] = validatedComponent(500000);

    postRefund($scn['finance'], $component->ulid, 600000)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'refund_exceeds_refundable');

    expect(Refund::query()->count())->toBe(0);
});

it('rejects a second refund that exceeds the remaining refundable amount', function (): void {
    [$component, $scn] = validatedComponent(500000);

    // First refund reserves 400000 of the 500000 validated (in-flight counts).
    postRefund($scn['finance'], $component->ulid, 400000)->assertCreated();

    // A second refund of 200000 exceeds the remaining 100000.
    postRefund($scn['finance'], $component->ulid, 200000)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'refund_exceeds_refundable');

    expect(Refund::query()->count())->toBe(1);
});

it('rejects a non-positive refund amount at validation', function (): void {
    [$component, $scn] = validatedComponent(500000);

    postRefund($scn['finance'], $component->ulid, 0)->assertStatus(422);
    expect(Refund::query()->count())->toBe(0);
});

it('returns 404 refunding a foreign-tenant component', function (): void {
    [, $scn] = validatedComponent(500000);
    [$foreign] = validatedComponent(500000);

    postRefund($scn['finance'], $foreign->ulid, 100000)->assertNotFound();
});

it('never exposes the plaintext external reference in the refund resource', function (): void {
    [$component, $scn] = validatedComponent(500000);

    $response = test()->actingAs($scn['finance'], 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson('/api/v1/refunds', [
            'payment_record' => $component->ulid, 'amount_minor' => 100000,
            'method' => 'mpesa_offline', 'reason' => 'return', 'reference' => 'RQREF12345',
        ])->assertCreated();

    expect(json_encode($response->json()))->not->toContain('RQREF12345')
        ->and($response->json('data.reference_masked'))->toContain('•')->toEndWith('2345');
});
