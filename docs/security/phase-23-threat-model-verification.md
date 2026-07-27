# Phase 23 — Whole-product threat-model verification (Plan §9.1, §73)

> **Machine-checked.** The authoritative matrix is the `P23_THREAT_MATRIX` constant in
> [`tests/Feature/Security/Phase23ThreatModelCoverageTest.php`](../../tests/Feature/Security/Phase23ThreatModelCoverageTest.php).
> That suite fails if a scenario loses its evidence, if a referenced suite is renamed or deleted, if a
> status leaves the closed vocabulary, or if a scenario id disappears from **this** document. This
> page is the human-readable rendering of the same data — it is never the source of truth.

Phase 23 does not re-prove the business behaviour the referenced suites already prove exhaustively;
duplicating them would rot. Its job is to make the matrix **load-bearing**, and to prove directly the
scenarios whose control is an *absence* (no SSRF surface, no Wallet webhook route) — absence has no
natural home suite.

## Status vocabulary (closed)

| Status | Meaning |
|---|---|
| `automated` | Proven by an automated suite that exists and runs. |
| `absence_proof` | Proven by a non-regression guard: the capability must **not** exist. |
| `blocked_external_gate` | Owned by a phase blocked behind a named external gate; absence is proven, implementation is **not** claimed. |
| `not_applicable` | Genuinely inapplicable, with a precise reason. |

Vague dispositions such as "covered" or "looks safe" are rejected by the guard.

## Matrix

| ID | Scenario | Status | Evidence | Control |
|---|---|---|---|---|
| TM-01 | Cross-tenant resource access | `automated` | `Isolation/CrossTenantAccessTest`, `Isolation/CrossTenantBranchOwnedModelTest`, `Isolation/RouteBindingTenantSafetyTest`, `Security/TenancyStaticAnalysisTest` | Merchant global scope + scoped route binding; foreign ULID → 404, no existence leak (§9 rule 1). |
| TM-02 | Cross-branch resource access | `automated` | `Isolation/CrossBranchAccessTest`, `Isolation/BranchRouteBindingTest` | Same-tenant out-of-branch → documented 403 `no_branch_scope` (§9 rule 2). |
| TM-03 | Cross-personnel own-scope access | `automated` | `Search/SearchServedClientOwnScopeTest`, `Auth/AuthorityBoundariesTest` | `staff_profile_id` derived from the membership; no route accepts another personnel id (§9 rule 3). |
| TM-04 | Over-privileged staff (role-boundary escape) | `automated` | `Auth/PermissionRoleBoundaryTest`, `Auth/HrSelfEscalationTest`, `Auth/PermissionNonOverridableTest`, `Auth/AuthorityBoundariesTest` | §10.2 authority boundaries; HR cannot self-escalate; non-overridable keys cannot be granted. |
| TM-05 | Suspended user reusing an active session | `automated` | `Security/MidSessionSuspensionTest`, `Auth/SessionRevocationTest`, `Auth/RevocationMiddlewareOrderTest` | `EnsureActivePrincipal` re-checks membership per request. |
| TM-06 | Deactivated user reusing an active session | `automated` | `Auth/SessionRevocationTest`, `Auth/SanctumTokenRevocationTest`, `Auth/InactiveMerchantUserCannotLoginTest` | Deactivation revokes sessions, tokens and unconsumed Magic Links (§9 rule 7). |
| TM-07 | Compromised email replaying a Magic Link | `automated` | `Auth/ReusedMagicLinkTest`, `Auth/ExpiredMagicLinkTest`, `Security/MagicLinkTokenSecurityTest` | SHA-256 at rest, 15-minute expiry, single use (§9 rule 6). |
| TM-08 | Duplicate Magic-Link consumption | `automated` | `Auth/MagicLinkConsumeTest`, `Auth/ReusedMagicLinkTest`, `Auth/MagicLinkRevocationTest` | Atomic consume; second consumption fails uniformly. |
| TM-09 | Replayed idempotent financial mutation | `automated` | `Idempotency/IdempotentReplayTest`, `Idempotency/ReplayResponseSecurityTest` | Stored response replayed; exactly one effect (§9 rule 15). |
| TM-10 | Same idempotency key, different payload | `automated` | `Idempotency/IdempotencyConflictTest`, `Idempotency/CanonicalRequestHashTest` | Canonical request-hash mismatch → 409. |
| TM-11 | Concurrent invoice writes | `automated` | `Invoicing/FinalizeInvoiceTest`, `Idempotency/IdempotencyConcurrencyTest` | Row lock + transaction; unique invoice number is a DB invariant. |
| TM-12 | Concurrent payment writes on one invoice balance | `automated` | `Payments/PaymentGroupValidationAtomicityTest`, `Payments/PaymentDuplicateReferenceTest` | Atomic group validation + duplicate-reference control (§9.1 two-Front-Office case). |
| TM-13 | Concurrent receipt writes | `automated` | `Receipts/ReceiptNumberConcurrencyTest`, `Receipts/ReceiptIssuanceTest` | Receipt number allocated under lock; DB unique constraint is the backstop. |
| TM-14 | Concurrent refund writes / over-refund | `automated` | `Refunds/RefundAllocationTest`, `Refunds/RefundWorkflowTest` | Allocation bounded by validated paid amount; reversal-only corrections. |
| TM-15 | Maker/checker self-approval | `automated` | `Auth/PermissionMakerCheckerTest`, `Refunds/RefundStepUpTest` | Incompatible key pairs + per-transaction actor guard. |
| TM-16 | Stale MFA step-up reused | `automated` | `Auth/MfaStepUpTest`, `Auth/PermissionStepUpCoverageTest`, `Auth/PermissionMfaCoverageTest`, `Auth/MfaMiddlewareOrderTest` | `RequireFreshMfa` freshness window; MFA after auth, before tenant context. |
| TM-17 | Personnel contact extraction | `automated` | `Hr/StaffReadAuthorizationTest`, `Branches/BranchPersonnelOptionsTest`, `Messaging/SmsContactExportProhibitionTest`, `Search/SearchPhoneLookupTest` | **Phase 23 PH23-SEC-001** closed the unauthorized roster read; RK-05. |
| TM-18 | Guessed export-shaped routes | `absence_proof` | `Security/ForbiddenRouteAbsenceTest`, `Messaging/SmsContactExportProhibitionTest` | Contact-export routes do not exist; guessing yields 404 + audit. |
| TM-19 | Search filter injection | `automated` | `Search/SearchInjectionSafetyTest` | Allowlisted sort/filter; parameterised queries only (§9 rule 9). |
| TM-20 | Search tenant injection | `automated` | `Search/SearchTenantIsolationTest`, `Search/SearchScopePurityTest` | Caller-supplied merchant filters cannot widen server-resolved scope. |
| TM-21 | Search branch injection | `automated` | `Search/SearchTenantIsolationTest`, `Search/SearchScopePurityTest` | Out-of-scope branch narrows, never widens. |
| TM-22 | Poisoned search-index candidates | `automated` | `Search/SearchEngineIntegrationTest`, `Search/SearchIndexDocumentTest` | Engine candidates re-filtered under tenant scopes and re-checked per record. |
| TM-23 | File MIME spoofing | `automated` | `Files/FileUploadValidationTest` | Magic-byte detection; browser MIME/filename never trusted (§9 rule 10). |
| TM-24 | Double-extension upload | `automated` | `Files/FileUploadValidationTest` | Per-purpose extension allowlist. |
| TM-25 | Malware upload (EICAR) | `automated` | `Files/ClamAvEicarIntegrationTest`, `Files/FileScanPipelineTest` | Real ClamAV scan; infected files quarantined, never served. |
| TM-26 | Polyglot / active-content upload | `automated` | `Files/FileUploadValidationTest`, `Files/FilePurposeRegistryTest` | Executables/scripts/active-SVG/macro-office rejected. |
| TM-27 | Foreign file ULID access | `automated` | `Isolation/FileTenantIsolationTest`, `Files/FileDownloadAuthorizationTest` | Tenant/branch/purpose checks before bytes; foreign ULID → 404. |
| TM-28 | Expired signed download | `automated` | `Files/FileSignedUrlExpiryTest`, `Security/SignedUrlIntegrityTest` | Short-lived signed access; authorization rechecked at download. |
| TM-29 | SSRF via server-side URL fetch | `absence_proof` | `Security/Phase23ThreatModelCoverageTest` | **No user-controlled outbound fetch exists.** The single HTTP client (`HttpReferEarnClient`) targets `config('refer-earn.base_url')`. Guarded directly. |
| TM-30 | Secret leakage in logs | `automated` | `Security/LogRedactionTest`, `Security/FileLogRedactionTest`, `Security/MfaSecretRedactionTest` | §24.5 binding redaction list. |
| TM-31 | Secret leakage in error responses | `automated` | `Security/HealthResponseRedactionTest`, `Security/EmailHeaderInjectionTest` | Structured error envelope; probes never echo configuration. |
| TM-32 | Secret leakage in audit records | `automated` | `Audit/AuditRedactionTest` | Audit values masked/redacted. |
| TM-33 | Secret leakage in exports | `automated` | `Audit/AuditExportMaskingTest`, `Finance/FinanceExportTest` | Permission-masked exports; no raw contact or credential material. |
| TM-34 | Audit-chain mutation | `automated` | `Audit/AuditChainVerificationTest`, `Audit/AuditChainFailureSignalTest` | Per-merchant/platform hash chain; verifier detects tampering and signals. |
| TM-35 | Audit-log `UPDATE` | `automated` | `Audit/AuditImmutabilityTest`, `Audit/AuditSourceMutationDenialTest` | DB trigger blocks UPDATE; Audit writes only flagged-event review metadata. |
| TM-36 | Audit-log `DELETE` | `automated` | `Audit/AuditImmutabilityTest` | DB trigger blocks DELETE (§9 rule 14). |
| TM-37 | R&E outbox payload mutation after insert | `automated` | `Integrations/ReferEarn/OutboxTransactionGuardTest`, `Integrations/ReferEarn/OutboxEmissionTest` | Append-only trigger (§9 rule 22). |
| TM-38 | R&E event replay / delivery idempotency | `automated` | `Integrations/ReferEarn/OutboxDeliveryTest`, `Integrations/ReferEarn/AttributionLifecycleTest` | Stable ULID `X-Citrus-Event-Id` reused across retries with the same body hash. |
| TM-39 | Forged R&E inbound reconciliation request | `blocked_external_gate` | `Security/Phase23ThreatModelCoverageTest` | **No inbound R&E route exists.** Owned by **Phase 21R-B**, blocked behind Phase 20D-W / Gate W. Absence guarded; no route was created to test it. |
| TM-40 | Wallet webhook forgery / replay | `blocked_external_gate` | `Security/NoDirectProviderIntegrationTest`, `Security/Phase23ThreatModelCoverageTest` | **No Wallet webhook route exists.** Owned by **Phase 20D-W**, blocked behind External Gate W (`docs/integrations/wallet/` absent). Creating one to test forgery would itself violate §9 rule 20. |

## Blocked scenarios — owners and future acceptance

| Scenario | Owner phase | Blocking gate | Gate evidence path (absent) | Future acceptance |
|---|---|---|---|---|
| TM-39 | Phase 21R-B | Phase 20D-W → External Gate W | `docs/integrations/wallet/gate-w-evidence.md` | Inbound reconciliation verification suite at 21R-B (Plan §58B.3, §75.1) |
| TM-40 | Phase 20D-W | External Gate W (§80.2) | `docs/integrations/wallet/gate-w-evidence.md`, `docs/proof/phase-20d-w.md` | Wallet webhook signature/replay suite at 20D-W (Plan §9 rule 21, §75.1) |

Both are recorded as **deliberately absent**, never as implemented. The guard asserts
`docs/integrations/wallet/` does **not** exist, so if Gate W opens this page fails and forces
re-evaluation rather than silently going stale.

## Scenarios whose control changed in Phase 23

**TM-17 (personnel contact extraction)** was the Phase 23 entry defect. `GET /api/v1/staff` carried no
`EnsurePermission` and no controller `authorize()`, while `StaffProfileResource` returns an unmasked
`phone`; every authenticated merchant member — Front Office, Personnel and the read-only Audit role
included — could enumerate the branch roster with phone numbers. See
[`docs/proof/phase-23.md`](../proof/phase-23.md) §4 (PH23-SEC-001) for the full defect record.
