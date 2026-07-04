<?php

declare(strict_types=1);

use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\FinanceOps\Actions\ExpireFinanceExport;
use App\Domain\FinanceOps\Enums\FinanceExportStatus;
use App\Domain\FinanceOps\Jobs\GenerateFinanceExport;
use App\Domain\FinanceOps\Models\FinanceExport;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('payments', 'finance-exports');

beforeEach(function (): void {
    Storage::fake((string) config('files.disk'));
});

function requestExport(User $finance, array $body): TestResponse
{
    return test()->statefulMfa(now()->getTimestamp())->actingAs($finance, 'sanctum')
        ->postJson('/api/v1/finance-exports', $body);
}

/** Run the generation job synchronously (queue is faked; this exercises the real job). */
function runExportJob(FinanceExport $export): void
{
    (new GenerateFinanceExport($export->id, $export->merchant_id, $export->branch_id))->handle();
}

function exportBytes(FinanceExport $export): string
{
    $file = $export->refresh()->file;

    return Storage::disk((string) config('files.disk'))->get((string) $file?->final_path);
}

it('requests → generates (masked, scoped) → downloads with atomic accounting', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);

    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'Monthly reconciliation.'])
        ->assertCreated()->assertJsonPath('data.status', 'queued')->json('data.id');

    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();
    runExportJob($export);

    $export->refresh();
    expect($export->status)->toBe(FinanceExportStatus::Ready)
        ->and($export->file_id)->not->toBeNull()
        ->and($export->row_count)->toBe(1)
        ->and($export->expires_at)->not->toBeNull();

    // Download link: signed URL only, accounting recorded, no path/signature leaked.
    $dl = test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-exports/{$ulid}/download-link")->assertOk();
    expect(json_encode($dl->json()))->not->toContain('final_path')->not->toContain('generated/');

    $export->refresh();
    expect($export->download_count)->toBe(1)
        ->and($export->first_downloaded_at)->not->toBeNull()
        ->and($export->last_downloaded_at)->not->toBeNull();
});

it('rejects an unsupported export type with 422 unsupported_export_type', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);

    foreach (['compensation', 'payouts', 'billing'] as $type) {
        requestExport($scn['finance'], ['export_type' => $type, 'reason' => 'x-reason'])
            ->assertStatus(422)->assertJsonPath('error.code', 'unsupported_export_type');
    }
    expect(FinanceExport::query()->count())->toBe(0);
});

it('masks references and never writes a full/normalized reference into the CSV', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    $reference = 'SECRETREF1234';
    $component = cashUpComponent($scn, PaymentMethod::MpesaOffline, 100000);
    // Force a known reference to assert masking.
    $component->forceFill(['reference_normalized' => $reference, 'reference_display_encrypted' => $reference])->save();

    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertCreated()->json('data.id');
    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();
    runExportJob($export);

    $csv = exportBytes($export);
    expect($csv)->toContain('••••1234')      // masked suffix present
        ->and($csv)->not->toContain($reference) // full reference NEVER present
        ->and($csv)->not->toContain('SECRETREF'); // nor the normalized prefix
});

it('is idempotent: re-running the job does not double-generate', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);

    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertCreated()->json('data.id');
    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();

    runExportJob($export);
    $fileId = $export->refresh()->file_id;

    runExportJob($export); // second run is a no-op (status no longer queued)
    expect($export->refresh()->file_id)->toBe($fileId)
        ->and($export->status)->toBe(FinanceExportStatus::Ready);
});

it('counts every download and refuses download once expired (409)', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);

    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertCreated()->json('data.id');
    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();
    runExportJob($export);

    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-exports/{$ulid}/download-link")->assertOk();
    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-exports/{$ulid}/download-link")->assertOk();
    expect($export->refresh()->download_count)->toBe(2);

    app(ExpireFinanceExport::class)->handle($export);
    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-exports/{$ulid}/download-link")
        ->assertStatus(409)->assertJsonPath('error.code', 'finance_export_not_ready');
});

it('revokes a ready export so it is no longer downloadable', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);

    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertCreated()->json('data.id');
    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();
    runExportJob($export);

    test()->actingAs($scn['finance'], 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
        ->postJson("/api/v1/finance-exports/{$ulid}/revoke")->assertOk()->assertJsonPath('data.status', 'revoked');

    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-exports/{$ulid}/download-link")->assertStatus(409);
});

it('forbids a non-Finance role from requesting an export (403)', function (): void {
    $scn = cashUpScenario();

    requestExport($scn['branchManager'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertForbidden();
    requestExport($scn['frontOffice'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertForbidden();
});

it('returns 404 for a foreign-tenant export ULID', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertCreated()->json('data.id');

    $other = cashUpScenario();
    confirmedTotp($other['finance']);
    test()->actingAs($other['finance'], 'sanctum')->getJson("/api/v1/finance-exports/{$ulid}")->assertNotFound();
});

it('emits the export audit chain (requested → generated → downloaded)', function (): void {
    $scn = cashUpScenario();
    confirmedTotp($scn['finance']);
    cashUpValidatedComponent($scn, PaymentMethod::Cash, 100000);

    $ulid = (string) requestExport($scn['finance'], ['export_type' => 'payments', 'reason' => 'x-reason'])->assertCreated()->json('data.id');
    $export = FinanceExport::query()->where('ulid', $ulid)->firstOrFail();
    runExportJob($export);
    test()->actingAs($scn['finance'], 'sanctum')->postJson("/api/v1/finance-exports/{$ulid}/download-link")->assertOk();

    $actions = AuditLog::query()->pluck('action')->all();
    expect($actions)->toContain(AuditEvent::FinanceExportRequested->value)
        ->and($actions)->toContain(AuditEvent::FinanceExportGenerated->value)
        ->and($actions)->toContain(AuditEvent::FinanceExportDownloaded->value);
});
