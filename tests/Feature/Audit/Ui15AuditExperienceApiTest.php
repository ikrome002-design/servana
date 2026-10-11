<?php

declare(strict_types=1);

use App\Domain\Audit\Contracts\AuditRecorder;
use App\Domain\Audit\Enums\AuditDomain;
use App\Domain\Audit\Enums\AuditEvent;
use App\Domain\Audit\Enums\AuditReviewView;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditChainSegmentVerifier;
use App\Domain\Audit\Support\AuditChainSegmentResult;
use App\Domain\Branches\Models\MerchantBranch;
use App\Domain\Merchants\Enums\MerchantUserRole;
use App\Domain\Merchants\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class)->group('audit', 'ui15', 'authority');

/*
 | Phase UI-15 — the Audit account's narrow read seams (UI/UX plan Appendix A §12.4.3–§12.4.13).
 |
 |   GET /audit-logs?view=…        server-decided review lenses over the general segment
 |   GET /audit-logs?flagged=…     review-state filter; correlation_id filter
 |   review summary on every row   mutable review status kept apart from immutable severity
 |   GET /audit-integrity          bounded read-only segment verification, branch-safe summary
 |
 | None of these adds a permission key, a mutation or a hash to any payload.
 */

/** @return array{admin: User, merchant: Merchant, branch: MerchantBranch, other: MerchantBranch, audit: User} */
function ui15Scenario(): array
{
    [$admin, $merchant] = activeAdmin();
    $branch = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    $other = MerchantBranch::factory()->create(['merchant_id' => $merchant->id]);
    [$audit] = branchStaff($merchant, $branch, MerchantUserRole::Audit);

    return compact('admin', 'merchant', 'branch', 'other', 'audit');
}

/** Record one event on a branch through the canonical recorder (hash-chained). */
function ui15Record(array $scn, AuditEvent $event, ?MerchantBranch $branch = null, array $context = []): AuditLog
{
    $branch ??= $scn['branch'];

    return app(AuditRecorder::class)->record($event, $scn['admin'], $scn['merchant']->id, $branch->id, $branch, $context);
}

/** Tamper one stored row inside the isolated test database only. */
function ui15Tamper(AuditLog $row): void
{
    DB::statement('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_no_update');
    DB::table('audit_logs')->where('id', $row->id)->update(['action' => 'tampered.value']);
    DB::statement('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_no_update');
}

it('classifies every review view inside the general segment only', function (): void {
    foreach (AuditReviewView::cases() as $view) {
        expect($view->actions())->not->toBeEmpty();

        foreach ($view->actions() as $action) {
            expect(AuditEvent::from($action)->domain())->toBe(AuditDomain::General);
        }
    }

    // A finance event is never selected by a view, so a lens cannot widen the general segment.
    expect(AuditReviewView::Operational->includes(AuditEvent::InvoiceCreated))->toBeFalse()
        ->and(AuditReviewView::Access->includes(AuditEvent::CompensationPlanApproved))->toBeFalse();
});

it('narrows the branch log to a review view server-side', function (): void {
    $scn = ui15Scenario();
    $operational = ui15Record($scn, AuditEvent::BranchDayOpened);
    $access = ui15Record($scn, AuditEvent::BranchAssignmentGranted);
    $privacy = ui15Record($scn, AuditEvent::ClientConsentOptedOut);
    $finance = ui15Record($scn, AuditEvent::InvoiceCreated, null, ['invoice_number' => 'INV-1']);

    $ids = fn (string $view): array => $this->actingAs($scn['audit'], 'sanctum')
        ->getJson('/api/v1/audit-logs?view='.$view)->assertOk()->json('data.*.id');

    expect($ids('operational'))->toContain($operational->ulid)->not->toContain($access->ulid)->not->toContain($finance->ulid)
        ->and($ids('access'))->toContain($access->ulid)->not->toContain($operational->ulid)->not->toContain($finance->ulid)
        ->and($ids('privacy_preferred'))->toContain($privacy->ulid)->not->toContain($operational->ulid)->not->toContain($finance->ulid);

    $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs?view=everything')
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['view']]]);
});

it('keeps a review view inside the caller assigned branch', function (): void {
    $scn = ui15Scenario();
    $mine = ui15Record($scn, AuditEvent::BranchDayOpened);
    $theirs = ui15Record($scn, AuditEvent::BranchDayOpened, $scn['other']);

    $ids = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs?view=operational')
        ->assertOk()->json('data.*.id');

    expect($ids)->toContain($mine->ulid)->not->toContain($theirs->ulid);
});

it('filters by flagged review state and by correlation id, and reports review status apart from severity', function (): void {
    $scn = ui15Scenario();
    $flagged = ui15Record($scn, AuditEvent::BranchDayClosed);
    $plain = ui15Record($scn, AuditEvent::BranchDayOpened);

    $flag = $this->actingAs($scn['audit'], 'sanctum')
        ->postJson('/api/v1/audit-flagged-events', ['audit_log' => $flagged->ulid, 'note' => 'Closed early'])
        ->assertCreated()->json('data');

    $flaggedIds = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs?flagged=flagged')
        ->assertOk()->json('data.*.id');
    $unflaggedIds = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs?flagged=unflagged')
        ->assertOk()->json('data.*.id');

    expect($flaggedIds)->toBe([$flagged->ulid])
        ->and($unflaggedIds)->toContain($plain->ulid)->not->toContain($flagged->ulid);

    $row = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs/'.$flagged->ulid)->assertOk()->json('data');
    expect($row['review'])->toBe(['flag' => $flag['id'], 'status' => 'open', 'flag_count' => 1])
        ->and($row['severity'])->toBe($flagged->severity->value);

    $plainRow = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs/'.$plain->ulid)->assertOk()->json('data');
    expect($plainRow['review'])->toBeNull();

    // Rows recorded inside a request carry its correlation id: the flag request's own audit row.
    $flagRow = AuditLog::query()->where('action', AuditEvent::AuditEventFlagged->value)->sole();
    expect($flagRow->correlation_id)->not->toBeNull();

    $correlated = $this->actingAs($scn['audit'], 'sanctum')
        ->getJson('/api/v1/audit-logs?correlation_id='.urlencode((string) $flagRow->correlation_id))
        ->assertOk()->json('data.*.id');
    expect($correlated)->toBe([$flagRow->ulid]);

    $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs?flagged=maybe')
        ->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed')->assertJsonStructure(['error' => ['fields' => ['flagged']]]);
});

it('never exposes hash-chain columns on the audit-log read', function (): void {
    $scn = ui15Scenario();
    $row = ui15Record($scn, AuditEvent::BranchDayOpened);

    $payload = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-logs/'.$row->ulid)->assertOk()->json('data');

    expect($payload)->not->toHaveKey('hash')->not->toHaveKey('previous_hash')->not->toHaveKey('ip_address');
});

it('reports a fully covered consistent chain as verified, counting only the caller branch', function (): void {
    $scn = ui15Scenario();
    ui15Record($scn, AuditEvent::BranchDayOpened);
    ui15Record($scn, AuditEvent::BranchDayOpened, $scn['other']);
    ui15Record($scn, AuditEvent::BranchDayClosed);

    $data = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-integrity')->assertOk()->json('data');

    expect($data['status'])->toBe('verified')
        ->and($data['complete_history'])->toBeTrue()
        ->and($data['branch_records_checked'])->toBe(2)
        ->and($data['first_break'])->toBeNull()
        ->and($data['scheduled_verification_exposed'])->toBeFalse()
        ->and($data['segment_limit'])->toBe(AuditChainSegmentVerifier::SEGMENT_LIMIT)
        ->and(json_encode($data))->not->toContain('previous_hash');
});

it('reports a consistent segment that does not reach genesis as partial, never verified', function (): void {
    $scn = ui15Scenario();
    for ($i = 0; $i < 5; $i++) {
        ui15Record($scn, AuditEvent::BranchDayOpened);
    }

    $result = app(AuditChainSegmentVerifier::class)->verifyMerchantSegment($scn['merchant']->id, 3);

    expect($result->status())->toBe(AuditChainSegmentResult::STATUS_PARTIAL)
        ->and($result->completeHistory)->toBeFalse()
        ->and($result->rows)->toHaveCount(3);
});

it('detects tampering inside the segment and names the event only when it is in the caller branch', function (): void {
    $scn = ui15Scenario();
    ui15Record($scn, AuditEvent::BranchDayOpened);
    $mine = ui15Record($scn, AuditEvent::BranchDayClosed);
    ui15Record($scn, AuditEvent::BranchDayOpened);
    ui15Tamper($mine);

    $data = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-integrity')->assertOk()->json('data');

    expect($data['status'])->toBe('broken')
        ->and($data['first_break'])->toMatchArray([
            'category' => 'hash_mismatch',
            'within_branch_scope' => true,
            'event' => $mine->ulid,
        ]);
});

it('withholds the identity of a break that sits in another branch', function (): void {
    $scn = ui15Scenario();
    ui15Record($scn, AuditEvent::BranchDayOpened);
    $theirs = ui15Record($scn, AuditEvent::BranchDayOpened, $scn['other']);
    ui15Tamper($theirs);

    $data = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-integrity')->assertOk()->json('data');

    expect($data['status'])->toBe('broken')
        ->and($data['first_break'])->toBe([
            'category' => 'hash_mismatch',
            'within_branch_scope' => false,
            'event' => null,
            'occurred_at' => null,
        ])
        ->and(json_encode($data))->not->toContain($theirs->ulid);
});

it('isolates merchant chains — another merchant broken chain never reaches this status', function (): void {
    $scn = ui15Scenario();
    ui15Record($scn, AuditEvent::BranchDayOpened);

    $foreign = ui15Scenario();
    $foreignRow = ui15Record($foreign, AuditEvent::BranchDayOpened);
    ui15Tamper($foreignRow);

    $data = $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-integrity')->assertOk()->json('data');

    expect($data['status'])->toBe('verified')->and(json_encode($data))->not->toContain($foreignRow->ulid);
});

it('verifies without writing a single audit row', function (): void {
    $scn = ui15Scenario();
    ui15Record($scn, AuditEvent::BranchDayOpened);
    ui15Record($scn, AuditEvent::BranchDayClosed);
    $before = AuditLog::query()->orderBy('id')->pluck('hash', 'id')->toArray();

    $this->actingAs($scn['audit'], 'sanctum')->getJson('/api/v1/audit-integrity')->assertOk();

    expect(AuditLog::query()->orderBy('id')->pluck('hash', 'id')->toArray())->toBe($before);
});

it('denies integrity status to the unauthenticated, to roles without the audit read key, and to an unassigned reader', function (): void {
    $scn = ui15Scenario();
    ui15Record($scn, AuditEvent::BranchDayOpened);

    $this->getJson('/api/v1/audit-integrity')->assertUnauthorized();

    foreach ([MerchantUserRole::FrontOffice, MerchantUserRole::BranchManager, MerchantUserRole::Hr, MerchantUserRole::Personnel] as $role) {
        [$user] = branchStaff($scn['merchant'], $scn['branch'], $role);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/audit-integrity')->assertForbidden();
    }

    $this->actingAs($scn['admin'], 'sanctum')->getJson('/api/v1/audit-integrity')->assertForbidden();

    // An Audit member with no active branch assignment holds the key but has no scope to review.
    [$unassigned] = branchStaff($scn['merchant'], $scn['branch'], MerchantUserRole::Audit, assigned: false);
    $this->actingAs($unassigned, 'sanctum')->getJson('/api/v1/audit-integrity')->assertForbidden();
});

it('offers no write verb on the integrity or audit-log surfaces', function (): void {
    $scn = ui15Scenario();
    $row = ui15Record($scn, AuditEvent::BranchDayOpened);

    foreach (['post', 'put', 'patch', 'delete'] as $verb) {
        $this->actingAs($scn['audit'], 'sanctum')->json($verb, '/api/v1/audit-integrity')->assertStatus(405);
        $this->actingAs($scn['audit'], 'sanctum')->json($verb, '/api/v1/audit-logs/'.$row->ulid)->assertStatus(405);
    }
});
