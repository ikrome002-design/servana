<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Finder\Finder;

uses()->group('security', 'phase23', 'threat-model');

/*
 |==============================================================================
 | Phase 23 Increment 2 — whole-product threat-model verification (Plan §9.1, §73).
 |
 | This is the MACHINE-CHECKED source of truth for the Phase 23 attacker-model matrix.
 | `docs/security/phase-23-threat-model-verification.md` is the human-readable rendering of
 | the same data and is kept in sync by the last case in this file.
 |
 | The point of this suite is NOT to re-prove business behaviour that the referenced suites
 | already prove exhaustively — duplicating them would rot. It is to make the threat matrix
 | LOAD-BEARING: if a control's suite is deleted or renamed, or a scenario silently loses its
 | evidence, this fails. Scenarios whose control is an ABSENCE (no SSRF surface, no Wallet
 | webhook route while Gate W is closed) are proven here directly, because absence has no
 | natural home suite.
 |
 | Status vocabulary is closed (Plan §73 requires a definite disposition per scenario):
 |   automated            — proven by an automated suite that exists and runs
 |   absence_proof        — proven by a non-regression/absence guard (the capability must NOT exist)
 |   blocked_external_gate— owned by a phase blocked behind a named external gate; absence proven
 |   not_applicable       — genuinely inapplicable, with a precise reason
 |
 | "covered" / "looks safe" are deliberately NOT permitted values.
 */

const P23_TM_STATUSES = ['automated', 'absence_proof', 'blocked_external_gate', 'not_applicable'];

/**
 * The Phase 23 threat matrix. Every row: scenario id => [title, status, tests[], note].
 * `tests` are repository-relative paths that MUST exist and MUST contain at least one case.
 *
 * @var array<string, array{title: string, status: string, tests: list<string>, note: string}>
 */
const P23_THREAT_MATRIX = [
    'TM-01' => [
        'title' => 'Cross-tenant resource access',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Isolation/CrossTenantAccessTest.php',
            'tests/Feature/Isolation/CrossTenantBranchOwnedModelTest.php',
            'tests/Feature/Isolation/RouteBindingTenantSafetyTest.php',
            'tests/Feature/Security/TenancyStaticAnalysisTest.php',
        ],
        'note' => 'Merchant global scope + scoped route binding; foreign ULID → 404, no existence leak (Plan §9 rule 1).',
    ],
    'TM-02' => [
        'title' => 'Cross-branch resource access',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Isolation/CrossBranchAccessTest.php',
            'tests/Feature/Isolation/BranchRouteBindingTest.php',
        ],
        'note' => 'Same-tenant out-of-branch → documented 403 no_branch_scope posture (Plan §9 rule 2).',
    ],
    'TM-03' => [
        'title' => 'Cross-personnel own-scope access',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Search/SearchServedClientOwnScopeTest.php',
            'tests/Feature/Auth/AuthorityBoundariesTest.php',
        ],
        'note' => 'staff_profile_id derived from the membership; no route accepts another personnel id (Plan §9 rule 3).',
    ],
    'TM-04' => [
        'title' => 'Over-privileged staff (role boundary escape)',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Auth/PermissionRoleBoundaryTest.php',
            'tests/Feature/Auth/HrSelfEscalationTest.php',
            'tests/Feature/Auth/PermissionNonOverridableTest.php',
            'tests/Feature/Auth/AuthorityBoundariesTest.php',
        ],
        'note' => 'Plan §10.2 authority boundaries; HR cannot self-escalate; non-overridable keys cannot be granted.',
    ],
    'TM-05' => [
        'title' => 'Suspended user reusing an active session',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Security/MidSessionSuspensionTest.php',
            'tests/Feature/Auth/SessionRevocationTest.php',
            'tests/Feature/Auth/RevocationMiddlewareOrderTest.php',
        ],
        'note' => 'EnsureActivePrincipal re-checks membership per request; next request after suspension is denied.',
    ],
    'TM-06' => [
        'title' => 'Deactivated user reusing an active session',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Auth/SessionRevocationTest.php',
            'tests/Feature/Auth/SanctumTokenRevocationTest.php',
            'tests/Feature/Auth/InactiveMerchantUserCannotLoginTest.php',
        ],
        'note' => 'Deactivation revokes sessions, tokens and unconsumed Magic Links (Plan §9 rule 7).',
    ],
    'TM-07' => [
        'title' => 'Compromised email replaying a Magic Link',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Auth/ReusedMagicLinkTest.php',
            'tests/Feature/Auth/ExpiredMagicLinkTest.php',
            'tests/Feature/Security/MagicLinkTokenSecurityTest.php',
        ],
        'note' => 'Hashed at rest (SHA-256), 15-minute expiry, single-use (Plan §9 rule 6).',
    ],
    'TM-08' => [
        'title' => 'Duplicate Magic-Link consumption (atomic single-use)',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Auth/MagicLinkConsumeTest.php',
            'tests/Feature/Auth/ReusedMagicLinkTest.php',
            'tests/Feature/Auth/MagicLinkRevocationTest.php',
        ],
        'note' => 'Atomic consume; the second consumption of the same token fails uniformly.',
    ],
    'TM-09' => [
        'title' => 'Replayed idempotent financial mutation',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Idempotency/IdempotentReplayTest.php',
            'tests/Feature/Idempotency/ReplayResponseSecurityTest.php',
        ],
        'note' => 'Replay returns the stored response and produces exactly one effect (Plan §9 rule 15).',
    ],
    'TM-10' => [
        'title' => 'Same idempotency key with a different payload',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Idempotency/IdempotencyConflictTest.php',
            'tests/Feature/Idempotency/CanonicalRequestHashTest.php',
        ],
        'note' => 'Canonical request hash mismatch → 409, never a silent second effect.',
    ],
    'TM-11' => [
        'title' => 'Concurrent invoice writes',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Invoicing/FinalizeInvoiceTest.php',
            'tests/Feature/Idempotency/IdempotencyConcurrencyTest.php',
        ],
        'note' => 'Row lock + transaction; unique invoice number is also a DB invariant (Plan §9 rule 16).',
    ],
    'TM-12' => [
        'title' => 'Concurrent payment writes against one invoice balance',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Payments/PaymentGroupValidationAtomicityTest.php',
            'tests/Feature/Payments/PaymentDuplicateReferenceTest.php',
        ],
        'note' => 'Atomic group validation + duplicate-reference control (Plan §9.1 two-Front-Office case).',
    ],
    'TM-13' => [
        'title' => 'Concurrent receipt writes (receipt-number uniqueness)',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Receipts/ReceiptNumberConcurrencyTest.php',
            'tests/Feature/Receipts/ReceiptIssuanceTest.php',
        ],
        'note' => 'Receipt number allocated under lock; DB unique constraint is the final backstop.',
    ],
    'TM-14' => [
        'title' => 'Concurrent refund writes / over-refund',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Refunds/RefundAllocationTest.php',
            'tests/Feature/Refunds/RefundWorkflowTest.php',
        ],
        'note' => 'Allocation is bounded by validated paid amount; corrections are reversal-only.',
    ],
    'TM-15' => [
        'title' => 'Maker/checker self-approval',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Auth/PermissionMakerCheckerTest.php',
            'tests/Feature/Refunds/RefundStepUpTest.php',
        ],
        'note' => 'Incompatible key pairs + per-transaction actor guard (requester != approver).',
    ],
    'TM-16' => [
        'title' => 'Stale MFA step-up reused for a sensitive action',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Auth/MfaStepUpTest.php',
            'tests/Feature/Auth/PermissionStepUpCoverageTest.php',
            'tests/Feature/Auth/PermissionMfaCoverageTest.php',
            'tests/Feature/Auth/MfaMiddlewareOrderTest.php',
        ],
        'note' => 'RequireFreshMfa freshness window; MFA is checked after auth and before tenant context.',
    ],
    'TM-17' => [
        'title' => 'Personnel contact extraction',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Hr/StaffReadAuthorizationTest.php',
            'tests/Feature/Branches/BranchPersonnelOptionsTest.php',
            'tests/Feature/Messaging/SmsContactExportProhibitionTest.php',
            'tests/Feature/Search/SearchPhoneLookupTest.php',
        ],
        'note' => 'Phase 23 PH23-SEC-001 closed the unauthenticated-by-permission roster read; RK-05.',
    ],
    'TM-18' => [
        'title' => 'Guessed export-shaped routes',
        'status' => 'absence_proof',
        'tests' => [
            'tests/Feature/Security/ForbiddenRouteAbsenceTest.php',
            'tests/Feature/Messaging/SmsContactExportProhibitionTest.php',
        ],
        'note' => 'Export-shaped personnel/client contact routes do not exist; guessing yields 404 + audit.',
    ],
    'TM-19' => [
        'title' => 'Search filter injection',
        'status' => 'automated',
        'tests' => ['tests/Feature/Search/SearchInjectionSafetyTest.php'],
        'note' => 'Allowlisted sort/filter; parameterised queries only (Plan §9 rule 9).',
    ],
    'TM-20' => [
        'title' => 'Search tenant injection',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Search/SearchTenantIsolationTest.php',
            'tests/Feature/Search/SearchScopePurityTest.php',
        ],
        'note' => 'Caller-supplied merchant filters cannot widen the server-resolved scope.',
    ],
    'TM-21' => [
        'title' => 'Search branch injection',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Search/SearchTenantIsolationTest.php',
            'tests/Feature/Search/SearchScopePurityTest.php',
        ],
        'note' => 'A requested branch outside scope narrows, never widens; foreign branch ULID is an unknown filter.',
    ],
    'TM-22' => [
        'title' => 'Poisoned search-index candidates',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Search/SearchEngineIntegrationTest.php',
            'tests/Feature/Search/SearchIndexDocumentTest.php',
        ],
        'note' => 'Engine candidates are re-filtered under tenant scopes and re-checked per record against the type policy.',
    ],
    'TM-23' => [
        'title' => 'File MIME spoofing',
        'status' => 'automated',
        'tests' => ['tests/Feature/Files/FileUploadValidationTest.php'],
        'note' => 'Magic-byte detection; browser MIME/filename are never trusted (Plan §9 rule 10).',
    ],
    'TM-24' => [
        'title' => 'Double-extension upload',
        'status' => 'automated',
        'tests' => ['tests/Feature/Files/FileUploadValidationTest.php'],
        'note' => 'Per-purpose extension allowlist rejects double extensions.',
    ],
    'TM-25' => [
        'title' => 'Malware upload (EICAR)',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Files/ClamAvEicarIntegrationTest.php',
            'tests/Feature/Files/FileScanPipelineTest.php',
        ],
        'note' => 'Real ClamAV EICAR scan; infected files are quarantined, never served.',
    ],
    'TM-26' => [
        'title' => 'Polyglot / active-content upload',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Files/FileUploadValidationTest.php',
            'tests/Feature/Files/FilePurposeRegistryTest.php',
        ],
        'note' => 'Executables/scripts/active-SVG/macro-office rejected; only image purposes are uploadable.',
    ],
    'TM-27' => [
        'title' => 'Foreign file ULID access',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Isolation/FileTenantIsolationTest.php',
            'tests/Feature/Files/FileDownloadAuthorizationTest.php',
        ],
        'note' => 'Tenant/branch/purpose checks before bytes; foreign ULID → 404 (Plan §9.1 malicious-tenant case).',
    ],
    'TM-28' => [
        'title' => 'Expired signed download',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Files/FileSignedUrlExpiryTest.php',
            'tests/Feature/Security/SignedUrlIntegrityTest.php',
        ],
        'note' => 'Short-lived signed access; authorization is rechecked at download time.',
    ],
    'TM-29' => [
        'title' => 'SSRF through a server-side URL fetch',
        'status' => 'absence_proof',
        'tests' => ['tests/Feature/Security/Phase23ThreatModelCoverageTest.php'],
        'note' => 'No user-controlled outbound fetch exists; the single HTTP client targets a config-pinned base URL. Proven directly below.',
    ],
    'TM-30' => [
        'title' => 'Secret leakage in logs',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Security/LogRedactionTest.php',
            'tests/Feature/Security/FileLogRedactionTest.php',
            'tests/Feature/Security/MfaSecretRedactionTest.php',
        ],
        'note' => 'Plan §24.5 binding redaction list.',
    ],
    'TM-31' => [
        'title' => 'Secret leakage in error responses',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Security/HealthResponseRedactionTest.php',
            'tests/Feature/Security/EmailHeaderInjectionTest.php',
        ],
        'note' => 'Structured error envelope; probes never echo configuration or credentials.',
    ],
    'TM-32' => [
        'title' => 'Secret leakage in audit records',
        'status' => 'automated',
        'tests' => ['tests/Feature/Audit/AuditRedactionTest.php'],
        'note' => 'Audit values are masked/redacted; no credential ever becomes an audit value.',
    ],
    'TM-33' => [
        'title' => 'Secret leakage in exports',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Audit/AuditExportMaskingTest.php',
            'tests/Feature/Finance/FinanceExportTest.php',
        ],
        'note' => 'Exports are permission-masked; no raw contact or credential material is emitted.',
    ],
    'TM-34' => [
        'title' => 'Audit-chain mutation (hash-chain tamper)',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Audit/AuditChainVerificationTest.php',
            'tests/Feature/Audit/AuditChainFailureSignalTest.php',
        ],
        'note' => 'Per-merchant/platform hash chain; the verifier detects tampering and signals.',
    ],
    'TM-35' => [
        'title' => 'Audit-log UPDATE',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Audit/AuditImmutabilityTest.php',
            'tests/Feature/Audit/AuditSourceMutationDenialTest.php',
        ],
        'note' => 'DB trigger blocks UPDATE; the Audit role may write only flagged-event review metadata.',
    ],
    'TM-36' => [
        'title' => 'Audit-log DELETE',
        'status' => 'automated',
        'tests' => ['tests/Feature/Audit/AuditImmutabilityTest.php'],
        'note' => 'DB trigger blocks DELETE (append-only, Plan §9 rule 14).',
    ],
    'TM-37' => [
        'title' => 'R&E outbox payload mutation after insert',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Integrations/ReferEarn/OutboxTransactionGuardTest.php',
            'tests/Feature/Integrations/ReferEarn/OutboxEmissionTest.php',
        ],
        'note' => 'Append-only trigger; a queued payload cannot be mutated (Plan §9 rule 22).',
    ],
    'TM-38' => [
        'title' => 'R&E event replay / delivery idempotency',
        'status' => 'automated',
        'tests' => [
            'tests/Feature/Integrations/ReferEarn/OutboxDeliveryTest.php',
            'tests/Feature/Integrations/ReferEarn/AttributionLifecycleTest.php',
        ],
        'note' => 'Stable ULID X-Citrus-Event-Id reused across retries with the same body hash.',
    ],
    'TM-39' => [
        'title' => 'Forged R&E inbound reconciliation request',
        'status' => 'blocked_external_gate',
        'tests' => ['tests/Feature/Security/Phase23ThreatModelCoverageTest.php'],
        'note' => 'No inbound R&E reconciliation route exists yet — it is owned by Phase 21R-B, blocked behind Phase 20D-W / External Gate W. Absence proven below.',
    ],
    'TM-40' => [
        'title' => 'Wallet webhook forgery / replay',
        'status' => 'blocked_external_gate',
        'tests' => [
            'tests/Feature/Security/NoDirectProviderIntegrationTest.php',
            'tests/Feature/Security/Phase23ThreatModelCoverageTest.php',
        ],
        'note' => 'No Wallet webhook route exists — owned by Phase 20D-W, blocked behind External Gate W (docs/integrations/wallet/ absent). No route was created to test it.',
    ],
];

// ---- matrix integrity -------------------------------------------------------------

it('declares a closed, definite status for all 40 threat scenarios', function (): void {
    expect(P23_THREAT_MATRIX)->toHaveCount(40);

    $problems = [];
    foreach (P23_THREAT_MATRIX as $id => $row) {
        if (! preg_match('/^TM-\d{2}$/', $id)) {
            $problems[] = "{$id}: malformed scenario id";
        }
        if (! in_array($row['status'], P23_TM_STATUSES, true)) {
            $problems[] = "{$id}: status '{$row['status']}' is not in the closed vocabulary";
        }
        if (trim($row['title']) === '' || trim($row['note']) === '') {
            $problems[] = "{$id}: title/note must not be blank";
        }
        if ($row['tests'] === []) {
            $problems[] = "{$id}: at least one evidence test is required";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));

    // Every scenario id TM-01..TM-40 must be present — no silent gap in the matrix.
    // (`toHaveKey` treats a second argument as the expected VALUE, not a message, so the
    // assertion is written as a boolean with the message on `toBeTrue`.)
    $absent = [];
    for ($i = 1; $i <= 40; $i++) {
        $id = sprintf('TM-%02d', $i);
        if (! array_key_exists($id, P23_THREAT_MATRIX)) {
            $absent[] = $id;
        }
    }
    expect($absent)->toBe([], 'Scenario ids missing from the threat matrix: '.implode(', ', $absent));
});

it('resolves every referenced evidence suite to a real file containing real tests', function (): void {
    $problems = [];

    foreach (P23_THREAT_MATRIX as $id => $row) {
        foreach ($row['tests'] as $path) {
            $full = base_path($path);
            if (! is_file($full)) {
                $problems[] = "{$id}: evidence suite does not exist — {$path}";

                continue;
            }
            $body = (string) file_get_contents($full);
            if (! str_contains($body, 'it(')) {
                $problems[] = "{$id}: evidence suite contains no test case — {$path}";
            }
        }
    }

    expect($problems)->toBe([], "Threat-matrix evidence drifted from the repository:\n".implode("\n", $problems));
});

it('keeps the human-readable threat-model document in sync with this matrix', function (): void {
    $doc = base_path('docs/security/phase-23-threat-model-verification.md');
    expect(is_file($doc))->toBeTrue('docs/security/phase-23-threat-model-verification.md must exist');

    $body = (string) file_get_contents($doc);
    $missing = [];
    foreach (array_keys(P23_THREAT_MATRIX) as $id) {
        if (! str_contains($body, $id)) {
            $missing[] = $id;
        }
    }

    expect($missing)->toBe([], 'Scenarios absent from the published document: '.implode(', ', $missing));
});

// ---- absence proofs (no natural home suite) ---------------------------------------

it('proves no user-controlled server-side URL fetch exists (TM-29 SSRF)', function (): void {
    // The ONLY outbound HTTP client is HttpReferEarnClient, whose target is
    // `config('refer-earn.base_url')` — configuration, never request input. Any new outbound call
    // built from a request value would be an SSRF surface and must fail this guard.
    $offenders = [];

    // sourceFilesUnder() replaces RecursiveDirectoryIterator, which truncates directory listings
    // on the dev bind mount: this SSRF absence proof read only ~89% of app/ while asserting it
    // had walked every file (PH23-SCAN-001). An absence proof that skips files proves nothing.
    $scanned = sourceFilesUnder(app_path(), ['php']);
    foreach ($scanned as $path) {
        $body = (string) file_get_contents($path);
        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);

        // A request-derived value flowing into an outbound call or a remote stream read.
        if (preg_match('/(Http::|->get\(|->post\()\s*\$?(request|input|url|target|endpoint|callback)/i', $body)) {
            $offenders[] = "{$relative}: outbound call appears to take a request-derived target";
        }
        if (preg_match('/(file_get_contents|fopen|curl_init)\s*\(\s*\$(request|input|url|target|endpoint)/i', $body)) {
            $offenders[] = "{$relative}: remote stream opened from a request-derived value";
        }
    }

    expect($offenders)->toBe([], "Potential SSRF surface introduced:\n".implode("\n", $offenders));

    // The absence proof is only as good as its coverage: assert the walk was exhaustive against an
    // independent enumeration, so a truncated scan can never masquerade as a clean result.
    $independent = iterator_count(
        (new Finder)->files()->in(app_path())->name('*.php'),
    );
    expect(count($scanned))->toBe($independent, sprintf(
        'SSRF scan coverage is incomplete: walked %d of %d PHP files under app/.',
        count($scanned),
        $independent,
    ));
});

it('proves no Wallet webhook or provider callback route exists while Gate W is closed (TM-40)', function (): void {
    // External Gate W evidence is absent, so Phase 20D-W has not started. Servana must therefore
    // expose NO Wallet webhook, provider callback, or reconciliation route. Creating one merely to
    // test forgery would itself violate Plan §9 rule 20 and §2.2 ownership.
    expect(is_dir(base_path('docs/integrations/wallet')))->toBeFalse('Gate W evidence appeared — re-evaluate 20D-W before trusting this absence proof');

    $forbidden = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower((string) $route->uri());
        // `mpesa_offline` is a legitimate merchant-CLIENT payment METHOD (Phase 18A) and must not
        // be caught here — match only provider RUNTIME path segments.
        foreach (['/mpesa/', 'daraja', 'webhook/wallet', 'wallet/webhook', 'stk-callback', 'c2b/'] as $needle) {
            if (str_contains($uri, $needle)) {
                $forbidden[] = "{$route->methods()[0]} {$uri}";
            }
        }
    }

    expect($forbidden)->toBe([], "A provider/Wallet runtime route exists while Gate W is closed:\n".implode("\n", $forbidden));
});

it('proves no inbound R&E reconciliation route exists while 21R-B is blocked (TM-39)', function (): void {
    // Phase 21R-A delivered the OUTBOUND outbox only. The inbound reconciliation endpoint is
    // Phase 21R-B, blocked behind Phase 20D-W. Its forgery scenario therefore has no live target.
    $inbound = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = strtolower((string) $route->uri());

        // `api/v1/testing/*` is the Phase R3 test-only security harness (routes/api.php §"Test-only
        // security harness"); it is NEVER registered outside the `testing` environment, so it is not
        // a shipped surface. Excluding it keeps this guard about real partner endpoints.
        if (str_starts_with($uri, 'api/v1/testing/')) {
            continue;
        }

        if (str_contains($uri, 'refer-earn') || str_contains($uri, 'refer_earn') || str_contains($uri, 'reconciliation')) {
            // A read-only platform view is acceptable; an inbound partner-WRITE endpoint is not.
            if (! in_array('GET', $route->methods(), true)) {
                $inbound[] = "{$route->methods()[0]} {$uri}";
            }
        }
    }

    expect($inbound)->toBe([], "An inbound partner-write route exists before 21R-B:\n".implode("\n", $inbound));
});
