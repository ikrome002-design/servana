<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditExportStatus;
use App\Domain\Audit\Models\AuditExport;
use App\Domain\Audit\Services\AuditExportAuthorization;
use App\Domain\Auth\Enums\PermissionOverrideEffect;
use App\Domain\Auth\Models\MerchantUserPermissionOverride;
use App\Domain\Auth\Models\Permission;
use App\Domain\Auth\Seeders\PermissionSeeder;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Enums\MerchantUserStatus;
use App\Domain\Merchants\Models\MerchantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('audit', 'audit-exports', 'security');

function auditExportDomainScenario(): array
{
    $scenario = auditExportScenario();
    // Fixture creation queues Scout indexing. Measure only the export operation
    // while preserving the stronger assertion that no jobs at all are enqueued.
    Queue::fake();

    return $scenario;
}

function denyAuditExportKey(array $scenario, string $key): void
{
    $membership = MerchantUser::query()->where('user_id', $scenario['audit']->id)->firstOrFail();
    MerchantUserPermissionOverride::query()->create([
        'merchant_id' => $scenario['merchant']->id,
        'merchant_user_id' => $membership->id,
        'permission_id' => Permission::query()->where('key', $key)->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
        'granted_by' => $scenario['admin']->id,
        'reason' => 'Export domain authorization regression',
    ]);
}

beforeEach(function (): void {
    $this->seed(PermissionSeeder::class);
    Storage::fake((string) config('files.disk'));
    Queue::fake();
});

it('denies a domain export when its read key is revoked before creation', function (string $domain, string $key): void {
    $scenario = auditExportDomainScenario();
    denyAuditExportKey($scenario, $key);
    requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid,
        'reason' => 'Review restricted domain',
        'domains' => [$domain],
    ])->assertForbidden();
    expect(AuditExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    ['general', 'audit.branch_events.view'],
    ['finance', 'audit.finance.view'],
    ['compensation', 'audit.compensation.view'],
    ['finance', 'audit.export'],
]);

it('rejects the entire mixed domain request when any read key is missing', function (string $key): void {
    $scenario = auditExportDomainScenario();
    denyAuditExportKey($scenario, $key);
    requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Mixed review',
        'domains' => ['finance', 'compensation'],
    ])->assertForbidden();
    expect(AuditExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['audit.finance.view', 'audit.compensation.view']);

it('requires general read for an omitted or empty domain selection', function (array $selection): void {
    $scenario = auditExportDomainScenario();
    denyAuditExportKey($scenario, 'audit.branch_events.view');
    requestAuditExport($scenario['audit'], array_merge([
        'branch' => $scenario['branch']->ulid, 'reason' => 'Implicit scope review',
    ], $selection))->assertForbidden();
    Queue::assertNothingPushed();
})->with(['omitted' => [[]], 'empty' => [['domains' => []]]]);

it('validates malformed and unknown domain selections', function (mixed $domains): void {
    $scenario = auditExportDomainScenario();
    requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Invalid scope review', 'domains' => $domains,
    ])->assertUnprocessable();
    Queue::assertNothingPushed();
})->with(['unknown' => [['all']], 'scalar' => ['finance'], 'nested' => [[['finance']]], 'null' => [null]]);

it('does not widen a finance-only actor to compensation or implicit general scope', function (array $selection): void {
    $scenario = auditExportDomainScenario();
    denyAuditExportKey($scenario, 'audit.compensation.view');
    denyAuditExportKey($scenario, 'audit.branch_events.view');
    requestAuditExport($scenario['audit'], array_merge([
        'branch' => $scenario['branch']->ulid, 'reason' => 'Finance-only scope review',
    ], $selection))->assertForbidden();
    expect(AuditExport::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    [['domains' => ['compensation']]],
    [['domains' => ['finance', 'compensation']]],
    [[]],
    [['domains' => []]],
]);

it('fails closed on unsupported persisted scope instead of falling back to export rights', function (): void {
    $scenario = auditExportDomainScenario();
    foreach ([['domains' => ['all']], ['domains' => 'finance'], ['domains' => null], ['all' => true]] as $scope) {
        expect(app(AuditExportAuthorization::class)->allows(
            $scenario['audit'], $scenario['merchant']->id, $scenario['branch']->id, $scope,
        ))->toBeFalse();
    }
});

it('generates and streams masked selected domains for the legitimate default Audit role', function (): void {
    $scenario = auditExportDomainScenario();
    $recorder = app(AuditRecorder::class);
    foreach ([AuditDomain::Finance, AuditDomain::Compensation] as $domain) {
        $recorder->record(AuditEvent::from(AuditEvent::actionsIn($domain)[0]), $scenario['admin'],
            $scenario['merchant']->id, $scenario['branch']->id, $scenario['branch'],
            ['email' => 'synthetic@example.test', 'reference' => 'SYNTHETIC-REFERENCE', 'salary' => '100000']);
    }
    $id = requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Allowed domain review',
        'domains' => ['finance', 'compensation'],
    ])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $id)->firstOrFail();
    runAuditExportJob($export);
    $export->refresh();
    expect($export->status)->toBe(AuditExportStatus::Ready)->and($export->row_count)->toBe(2);
    $csv = Storage::disk((string) config('files.disk'))->get((string) $export->file?->final_path);
    expect($csv)->not->toContain('synthetic@example.test')->not->toContain('SYNTHETIC-REFERENCE')
        ->toContain('[restricted]')->not->toContain(AuditEvent::BranchDayOpened->value);
    $fileId = $export->file?->ulid;
    $fileUrl = $this->actingAs($scenario['audit'], 'sanctum')
        ->postJson("/api/v1/files/{$fileId}/download-link")->assertOk()->json('data.url');
    $this->get($fileUrl)->assertOk();
    streamAuditExport($scenario['audit'], $id)->assertOk();
    expect($export->refresh()->download_count)->toBe(1);
});

it('rechecks live requester rights before a queued worker generates any file', function (string $domain, string $key): void {
    $scenario = auditExportDomainScenario();
    $id = requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Queued revocation review', 'domains' => [$domain],
    ])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $id)->firstOrFail();
    denyAuditExportKey($scenario, $key);
    runAuditExportJob($export);
    expect($export->refresh()->status)->toBe(AuditExportStatus::Failed)
        ->and($export->file_id)->toBeNull()->and($export->failure_message_redacted)->toBe('Audit export generation failed.');
    expect(Storage::disk((string) config('files.disk'))->allFiles())->toBe([]);
})->with([
    ['finance', 'audit.finance.view'], ['compensation', 'audit.compensation.view'], ['general', 'audit.export'],
]);

it('denies previously issued signed streams and new links after revocation on both file routes', function (string $domain, string $key): void {
    $scenario = auditExportDomainScenario();
    $id = requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Stream revocation review', 'domains' => [$domain],
    ])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $id)->firstOrFail();
    runAuditExportJob($export);
    $fileId = $export->refresh()->file?->ulid;
    $auditUrl = $this->actingAs($scenario['audit'], 'sanctum')
        ->postJson("/api/v1/audit-exports/{$id}/download-link")->assertOk()->json('data.url');
    $fileUrl = $this->postJson("/api/v1/files/{$fileId}/download-link")->assertOk()->json('data.url');
    denyAuditExportKey($scenario, $key);
    $this->postJson("/api/v1/audit-exports/{$id}/download-link")->assertForbidden();
    $this->get($auditUrl)->assertForbidden();
    $this->postJson("/api/v1/files/{$fileId}/download-link")->assertForbidden();
    $this->get($fileUrl)->assertForbidden();
    expect($export->refresh()->download_count)->toBe(0);
})->with([
    ['general', 'audit.branch_events.view'], ['finance', 'audit.finance.view'],
    ['compensation', 'audit.compensation.view'], ['general', 'audit.export'],
]);

it('denies queued generation when requester identity membership or branch assignment is revoked', function (string $revocation): void {
    $scenario = auditExportDomainScenario();
    $id = requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Eligibility revocation review',
    ])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $id)->firstOrFail();
    $membership = MerchantUser::query()->where('user_id', $scenario['audit']->id)->firstOrFail();
    match ($revocation) {
        'identity' => $scenario['audit']->forceFill(['status' => 'suspended'])->save(),
        'membership' => $membership->forceFill(['status' => MerchantUserStatus::Suspended])->save(),
        'assignment' => $membership->branchAssignments()->update(['status' => 'revoked']),
    };
    runAuditExportJob($export);
    expect($export->refresh()->status)->toBe(AuditExportStatus::Failed)->and($export->file_id)->toBeNull();
})->with(['identity', 'membership', 'assignment']);

it('refuses both real signed streams outside the authorized merchant or branch', function (string $scope, int $status): void {
    $scenario = auditExportDomainScenario();
    $id = requestAuditExport($scenario['audit'], [
        'branch' => $scenario['branch']->ulid, 'reason' => 'Signed scope review',
    ])->assertCreated()->json('data.id');
    $export = AuditExport::query()->where('ulid', $id)->firstOrFail();
    runAuditExportJob($export);
    $fileId = $export->refresh()->file?->ulid;
    $auditUrl = $this->actingAs($scenario['audit'], 'sanctum')
        ->postJson("/api/v1/audit-exports/{$id}/download-link")->assertOk()->json('data.url');
    $fileUrl = $this->postJson("/api/v1/files/{$fileId}/download-link")->assertOk()->json('data.url');
    if ($scope === 'merchant') {
        $other = auditExportScenario()['audit'];
    } else {
        $branch = MerchantBranch::factory()->create(['merchant_id' => $scenario['merchant']->id]);
        [$other] = branchStaff($scenario['merchant'], $branch, MerchantUserRole::Audit);
    }
    $this->actingAs($other, 'sanctum')->get($auditUrl)->assertStatus($status);
    $this->get($fileUrl)->assertStatus($status);
    expect($export->refresh()->download_count)->toBe(0);
})->with([['merchant', 404], ['branch', 403]]);
