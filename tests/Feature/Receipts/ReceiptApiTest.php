<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Receipts\Models\Receipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class)->group('payments', 'receipts');

beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

it('lists scoped receipts (masked, ULID-only)', function (): void {
    $scn = paymentScenario(500000);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(500000)]))->assertCreated();

    $response = test()->actingAs($scn['finance'], 'sanctum')->getJson('/api/v1/receipts')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.id'))->toBeString()
        ->and($response->json('data.0.receipt_number'))->toBeInt()
        ->and($response->json('meta'))->not->toBeNull();
    // Never a full/normalized reference in a receipt payload.
    expect(json_encode($response->json()))->not->toContain('final_path');
});

it('filters receipts by invoice ULID', function (): void {
    $scn = paymentScenario(500000);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(500000)]))->assertCreated();

    // A second invoice + receipt for the same merchant/branch.
    $other = Invoice::factory()->issued(100000)->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'client_id' => $scn['client']->id,
        'subtotal_minor' => 100000, 'total_minor' => 100000,
    ]);
    $otherGroup = (string) recordPaymentGroup($scn['frontOffice'], $other->ulid, [cashComponent(100000)])->assertCreated()->json('data.id');
    validatePaymentGroup($scn['finance'], $otherGroup)->assertCreated();

    $firstInvoiceUlid = Invoice::query()->orderBy('id')->value('ulid');
    $response = test()->actingAs($scn['finance'], 'sanctum')
        ->getJson("/api/v1/receipts?invoice={$firstInvoiceUlid}")->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.invoice.id'))->toBe($firstInvoiceUlid);
});

it('shows a receipt by ULID with its invoice reference', function (): void {
    $scn = paymentScenario(500000);
    validatePaymentGroup($scn['finance'], recordPendingGroup($scn, [cashComponent(500000)]))->assertCreated();
    $receipt = Receipt::query()->firstOrFail();

    test()->actingAs($scn['finance'], 'sanctum')->getJson("/api/v1/receipts/{$receipt->ulid}")
        ->assertOk()
        ->assertJsonPath('data.id', $receipt->ulid)
        ->assertJsonPath('data.receipt_number', $receipt->receipt_number);
});

it('forbids a role without receipt.view from listing receipts (403)', function (): void {
    $scn = paymentScenario(500000);
    // HR has no receipt.view.
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);

    test()->actingAs($hr, 'sanctum')->getJson('/api/v1/receipts')->assertForbidden();
});
