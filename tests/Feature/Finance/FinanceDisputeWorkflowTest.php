<?php

declare(strict_types=1);

use App\Domain\Files\Models\UploadedFile;
use App\Domain\FinanceOps\Enums\FinanceDisputeStatus;
use App\Domain\FinanceOps\Models\FinanceDispute;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'finance-disputes');

function openDispute(User $actor, array $payload): TestResponse
{
    return test()->actingAs($actor, 'sanctum')->postJson('/api/v1/finance-disputes', $payload);
}

it('creates a dispute linked to an invoice, then reviews and resolves it — source untouched', function (): void {
    $scn = paymentScenario(500000);
    $invoice = $scn['invoice'];

    $disputeUlid = (string) openDispute($scn['finance'], ['invoice' => $invoice->ulid, 'reason' => 'Client disputes the charge.'])
        ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-disputes/{$disputeUlid}/start-review")
        ->assertOk()->assertJsonPath('data.status', 'under_review');

    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-disputes/{$disputeUlid}/resolve", ['resolution_note' => 'Charge confirmed valid.'])
        ->assertOk()->assertJsonPath('data.status', 'resolved');

    expect(FinanceDispute::query()->firstOrFail()->status)->toBe(FinanceDisputeStatus::Resolved);
    // The disputed invoice is never mutated by the dispute workflow.
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Issued);
});

it('requires an invoice or payment record linkage', function (): void {
    $scn = paymentScenario(500000);

    openDispute($scn['finance'], ['reason' => 'no linkage'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'dispute_linkage_required');
});

it('requires a resolution note to resolve or reject', function (): void {
    $scn = paymentScenario(500000);
    $disputeUlid = (string) openDispute($scn['finance'], ['invoice' => $scn['invoice']->ulid, 'reason' => 'x-reason'])->assertCreated()->json('data.id');

    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-disputes/{$disputeUlid}/reject", ['resolution_note' => ''])
        ->assertStatus(422);
});

it('rejects an invalid dispute transition (resolve from open)', function (): void {
    $scn = paymentScenario(500000);
    $disputeUlid = (string) openDispute($scn['finance'], ['invoice' => $scn['invoice']->ulid, 'reason' => 'x-reason'])->assertCreated()->json('data.id');

    // resolve is only valid from under_review.
    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-disputes/{$disputeUlid}/resolve", ['resolution_note' => 'note'])
        ->assertStatus(422)->assertJsonPath('error.code', 'invalid_state_transition');
});

it('attaches private evidence and never exposes the storage path', function (): void {
    $scn = paymentScenario(500000);
    $evidence = UploadedFile::factory()->create([
        'merchant_id' => $scn['merchant']->id, 'branch_id' => $scn['branch']->id, 'purpose' => 'dispute_evidence',
    ]);

    $response = openDispute($scn['finance'], ['invoice' => $scn['invoice']->ulid, 'reason' => 'with evidence', 'evidence_file' => $evidence->ulid])
        ->assertCreated();

    expect($response->json('data.has_evidence'))->toBeTrue()
        ->and(json_encode($response->json()))->not->toContain('final_path')
        ->and($response->json('data'))->not->toHaveKey('evidence_file_id');
});

it('forbids Front Office and HR from managing disputes (403)', function (): void {
    $scn = paymentScenario(500000);
    [$hr] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Hr);

    openDispute($scn['frontOffice'], ['invoice' => $scn['invoice']->ulid, 'reason' => 'x-reason'])->assertForbidden();
    openDispute($hr, ['invoice' => $scn['invoice']->ulid, 'reason' => 'x-reason'])->assertForbidden();
    expect(FinanceDispute::query()->count())->toBe(0);
});

it('returns 404 creating a dispute against a foreign-tenant invoice', function (): void {
    $scn = paymentScenario(500000);
    $other = paymentScenario(500000);

    openDispute($scn['finance'], ['invoice' => $other['invoice']->ulid, 'reason' => 'x-reason'])->assertNotFound();
});
