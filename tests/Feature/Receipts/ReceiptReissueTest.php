<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Receipts\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'receipts');

beforeEach(fn () => Queue::fake([GenerateReceiptPdf::class]));

function validatedReceiptUlid(array $scn, int $amount = 500000): string
{
    $groupUlid = recordPendingGroup($scn, [cashComponent($amount)]);

    return (string) validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated()->json('data.receipt.id');
}

function reissueReceipt(User $actor, string $receiptUlid, string $reason = 'Client requested a duplicate copy.'): TestResponse
{
    return test()->actingAs($actor, 'sanctum')
        ->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/receipts/{$receiptUlid}/reissue", ['reason' => $reason]);
}

it('reissues a receipt: a new immutable row + a new gap-free number referencing the original', function (): void {
    $scn = paymentScenario(500000);
    $originalUlid = validatedReceiptUlid($scn);
    $original = Receipt::query()->where('ulid', $originalUlid)->firstOrFail();

    $response = reissueReceipt($scn['finance'], $originalUlid)->assertCreated();

    expect($response->json('data.is_reissue'))->toBeTrue()
        ->and($response->json('data.receipt_number'))->toBeGreaterThan($original->receipt_number)
        ->and($response->json('data.amount.amount'))->toBe($original->amount_minor);

    // Two receipts now exist; the reissue references the immutable original.
    expect(Receipt::query()->count())->toBe(2);
    $reissue = Receipt::query()->where('reissue_of_receipt_id', $original->id)->firstOrFail();
    expect($reissue->receipt_number)->toBe($original->receipt_number + 1)
        ->and($reissue->reissue_of_receipt_id)->toBe($original->id)
        ->and($reissue->file_generation_status)->toBe('pending');

    // The original is unchanged (immutable).
    $original->refresh();
    expect($original->reissue_of_receipt_id)->toBeNull()
        ->and($original->receipt_number)->toBe($original->receipt_number);

    // A new receipt-PDF outbox job was dispatched for the reissue.
    Queue::assertPushed(GenerateReceiptPdf::class);

    // Safe audit — never a storage path/signature/internal id.
    $audit = AuditLog::query()->where('action', 'receipt.reissued')->firstOrFail();
    expect($audit->context['reissue_of_receipt_id'] ?? null)->toBe($original->ulid)
        ->and(json_encode($audit->context))->not->toContain('/');
});

it('requires a reason to reissue', function (): void {
    $scn = paymentScenario(500000);
    $originalUlid = validatedReceiptUlid($scn);

    reissueReceipt($scn['finance'], $originalUlid, '')->assertStatus(422);
    expect(Receipt::query()->count())->toBe(1);
});

it('forbids Front Office from reissuing a receipt (403)', function (): void {
    $scn = paymentScenario(500000);
    $originalUlid = validatedReceiptUlid($scn);

    reissueReceipt($scn['frontOffice'], $originalUlid)->assertForbidden();
    expect(Receipt::query()->count())->toBe(1);
});

it('reissuing a reissue still references the original, not the reissue', function (): void {
    $scn = paymentScenario(500000);
    $originalUlid = validatedReceiptUlid($scn);
    $original = Receipt::query()->where('ulid', $originalUlid)->firstOrFail();

    $firstReissueUlid = (string) reissueReceipt($scn['finance'], $originalUlid)->assertCreated()->json('data.id');
    reissueReceipt($scn['finance'], $firstReissueUlid)->assertCreated();

    // Every reissue points at the ORIGINAL receipt.
    expect(Receipt::query()->where('reissue_of_receipt_id', $original->id)->count())->toBe(2)
        ->and(Receipt::query()->whereNull('reissue_of_receipt_id')->count())->toBe(1);
});

it('returns 404 reissuing a foreign-tenant receipt', function (): void {
    $scn = paymentScenario(500000);
    $other = paymentScenario(500000);
    $foreignReceipt = validatedReceiptUlid($other);

    reissueReceipt($scn['finance'], $foreignReceipt)->assertNotFound();
});
