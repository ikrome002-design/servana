<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Receipts\Jobs\GenerateReceiptPdf;
use App\Domain\Receipts\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'receipts');

/** Validate a group, run the PDF outbox job, and return the now-ready receipt. */
function readyReceipt(array $scn): Receipt
{
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);
    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();
    $receipt = Receipt::query()->firstOrFail();
    (new GenerateReceiptPdf($receipt->id, $receipt->merchant_id, $receipt->branch_id))->handle();

    return $receipt->refresh();
}

function downloadLink(User $actor, string $receiptUlid): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->postJson("/api/v1/receipts/{$receiptUlid}/download-link");
}

it('issues an authorized signed download link for a ready receipt and audits receipt.downloaded', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    Storage::fake((string) config('files.disk'));
    $scn = paymentScenario(500000);
    $receipt = readyReceipt($scn);

    $response = downloadLink($scn['finance'], $receipt->ulid)->assertOk();

    // A short-lived signed URL is returned (the URL is the ONLY place a signature appears).
    expect($response->json('data.url'))->toContain('signature=')
        ->and($response->json('data.expires_at'))->not->toBeNull();

    // receipt.downloaded audit carries only safe context — no path/signature/internal id.
    $audit = AuditLog::query()->where('action', 'receipt.downloaded')->firstOrFail();
    expect($audit->context['receipt_id'] ?? null)->toBe($receipt->ulid)
        ->and(json_encode($audit->context))->not->toContain('signature')
        ->and(json_encode($audit->context))->not->toContain('/storage')
        ->and($audit->context)->not->toHaveKey('file_id');
});

it('lets Front Office download a receipt (receipt.view)', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    Storage::fake((string) config('files.disk'));
    $scn = paymentScenario(500000);
    $receipt = readyReceipt($scn);

    downloadLink($scn['frontOffice'], $receipt->ulid)->assertOk();
});

it('returns 409 for a receipt whose PDF is not ready', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    $scn = paymentScenario(500000);
    $groupUlid = recordPendingGroup($scn, [cashComponent(500000)]);
    validatePaymentGroup($scn['finance'], $groupUlid)->assertCreated();
    $receipt = Receipt::query()->firstOrFail();

    // The PDF outbox job has not run — the receipt is still pending.
    downloadLink($scn['finance'], $receipt->ulid)->assertStatus(409);
});

it('returns 404 for a foreign-tenant receipt download link', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    Storage::fake((string) config('files.disk'));
    $scn = paymentScenario(500000);
    $other = paymentScenario(500000);
    $foreign = readyReceipt($other);

    downloadLink($scn['finance'], $foreign->ulid)->assertNotFound();
});

it('never exposes a storage path, file id, or signature in the receipt resource', function (): void {
    Queue::fake([GenerateReceiptPdf::class]);
    Storage::fake((string) config('files.disk'));
    $scn = paymentScenario(500000);
    $receipt = readyReceipt($scn);

    $body = test()->actingAs($scn['finance'], 'sanctum')->getJson("/api/v1/receipts/{$receipt->ulid}")->assertOk()->json('data');

    expect($body)->not->toHaveKey('file_id')
        ->and($body)->not->toHaveKey('id_internal')
        ->and($body['id'])->toBe($receipt->ulid)
        ->and(json_encode($body))->not->toContain('final_path')
        ->and(json_encode($body))->not->toContain('signature');
    // The public id is the ULID, never the internal bigint.
    expect((string) $body['id'])->not->toBe((string) $receipt->id);
});
