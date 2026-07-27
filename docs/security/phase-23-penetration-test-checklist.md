# Phase 23 — Penetration-test checklist

> Plan §80 Phase 23: *"the pen-test checklist adds webhook forgery/replay and outbox tamper cases"*.
>
> This checklist is the **manual/adversarial** companion to the automated matrix in
> [`phase-23-threat-model-verification.md`](phase-23-threat-model-verification.md). Every item names
> the automated guard that holds the line continuously; the checklist exists so a human tester can
> attempt the attack directly and confirm the posture end to end.

## How to read a result

| Result | Meaning |
|---|---|
| `PASS (auto)` | The automated guard proves it; no manual step is required to keep it true. |
| `PASS (manual)` | Verified by hand against a running stack, with the observation recorded. |
| `PASS (absent)` | The capability does not exist and a guard keeps it absent. |
| `BLOCKED` | The target does not exist yet because its owning phase is gated. Not a pass, not a failure. |

Nothing in this checklist may be marked passing on the basis of a code reading alone when an
automated guard exists for it — cite the guard.

---

## A. Authentication and session

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| A1 | Request a Magic Link for an address that does not exist | Uniform 202; no enumeration signal, no timing oracle | `Auth/NoAccountEnumerationTest`, `Security/MerchantRegistrationEnumerationTest` | `PASS (auto)` |
| A2 | Consume the same Magic Link twice | Second attempt fails uniformly; exactly one session created | `Auth/ReusedMagicLinkTest`, `Auth/MagicLinkConsumeTest` | `PASS (auto)` |
| A3 | Consume a Magic Link after 15 minutes | Rejected | `Auth/ExpiredMagicLinkTest` | `PASS (auto)` |
| A4 | Read a Magic-Link token from the database and use it | Tokens are SHA-256 hashed at rest; the stored value is not usable | `Security/MagicLinkTokenSecurityTest` | `PASS (auto)` |
| A5 | Keep using a session after the membership is suspended | Next authenticated request denied | `Security/MidSessionSuspensionTest`, `Auth/RevocationMiddlewareOrderTest` | `PASS (auto)` |
| A6 | Keep using a Sanctum token after deactivation | Token revoked | `Auth/SanctumTokenRevocationTest` | `PASS (auto)` |
| A7 | Reuse an MFA step-up assertion beyond its freshness window | Denied; re-challenge required | `Auth/MfaStepUpTest` | `PASS (auto)` |
| A8 | Reach a privileged route with MFA enrolled but unconfirmed | Denied before tenant context resolves | `Auth/PrivilegedMfaMiddlewareTest`, `Auth/MfaMiddlewareOrderTest` | `PASS (auto)` |

## B. Tenancy, branch and own-scope

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| B1 | Request another merchant's resource by ULID | 404, no existence leak, high-severity audit row | `Isolation/CrossTenantAccessTest`, `Isolation/UnauthorizedAccessAuditTest` | `PASS (auto)` |
| B2 | Request a same-tenant branch you are not assigned to | 403 `no_branch_scope` (authority denial, not an existence leak) | `Isolation/CrossBranchAccessTest` | `PASS (auto)` |
| B3 | Pass another personnel's identifier to an own-scope route | No route accepts one; own scope derives from the membership | `Search/SearchServedClientOwnScopeTest` | `PASS (auto)` |
| B4 | Add `merchant_id` / `branch_id` query parameters to widen a list | Ignored; server-resolved scope wins | `Search/SearchScopePurityTest`, `Branches/BranchPersonnelOptionsTest` | `PASS (auto)` |

## C. Personnel contact protection (RK-05)

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| C1 | Enumerate the staff roster as Front Office / Personnel / Audit / Finance / Branch Manager | 403 on both `GET /api/v1/staff` and `GET /api/v1/staff/{staff}` | `Hr/StaffReadAuthorizationTest` | `PASS (auto)` — **closed by Phase 23 PH23-SEC-001** |
| C2 | Read personnel phone numbers via the Branch Manager schedule picker | The narrow options endpoint returns only `{id, display_name}` | `Branches/BranchPersonnelOptionsTest` | `PASS (auto)` |
| C3 | Reach staff records through global search as a Merchant Admin | Withheld — search never exceeds the page its results link to | `Search/SearchTenantIsolationTest` | `PASS (auto)` |
| C4 | Guess an export-shaped personnel contact route (`/export`, `.csv`, `.xlsx`, `/download`) | 404 + high-severity audit; **no such route exists** | `Security/ForbiddenRouteAbsenceTest`, `Messaging/SmsContactExportProhibitionTest` | `PASS (absent)` |
| C5 | Extract full phone numbers in bulk through the SMS recipient flow | Masked display only; no full-phone list is ever returned | `Messaging/SmsContactExportProhibitionTest` | `PASS (auto)` |
| C6 | Look up a client by a phone-like search term | Own-scope, masked, rate-limited; the term is never echoed or logged | `Search/SearchPhoneLookupTest`, `Search/SearchRateLimitTest` | `PASS (auto)` |

## D. Financial integrity

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| D1 | Replay a financial POST with the same `Idempotency-Key` | Stored response replayed; exactly one effect | `Idempotency/IdempotentReplayTest` | `PASS (auto)` |
| D2 | Reuse an `Idempotency-Key` with a different body | 409 on canonical-hash mismatch | `Idempotency/IdempotencyConflictTest` | `PASS (auto)` |
| D3 | Two Front Office users record against one invoice balance concurrently | Row lock + validated-amount check; no over-application | `Payments/PaymentGroupValidationAtomicityTest` | `PASS (auto)` |
| D4 | Force duplicate receipt numbers concurrently | Unique constraint holds under contention | `Receipts/ReceiptNumberConcurrencyTest` | `PASS (auto)` |
| D5 | Approve your own maker-submitted record | Denied by maker/checker incompatibility and the actor guard | `Auth/PermissionMakerCheckerTest` | `PASS (auto)` |
| D6 | Refund more than the validated paid amount | Bounded by validated allocation | `Refunds/RefundAllocationTest` | `PASS (auto)` |

## E. Files

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| E1 | Upload a PHP payload renamed `.png` | Rejected by magic-byte detection | `Files/FileUploadValidationTest` | `PASS (auto)` |
| E2 | Upload `payload.png.php` | Rejected by the extension allowlist | `Files/FileUploadValidationTest` | `PASS (auto)` |
| E3 | Upload an EICAR test file | Quarantined by ClamAV; never served | `Files/ClamAvEicarIntegrationTest` | `PASS (auto)` |
| E4 | Upload an active-content SVG or macro-office document | Rejected | `Files/FileUploadValidationTest` | `PASS (auto)` |
| E5 | Download another tenant's file by ULID | 404 before any byte is served | `Isolation/FileTenantIsolationTest` | `PASS (auto)` |
| E6 | Reuse a signed download URL after expiry | Rejected | `Files/FileSignedUrlExpiryTest` | `PASS (auto)` |
| E7 | Tamper with a signed URL's parameters | Signature invalid | `Security/SignedUrlIntegrityTest` | `PASS (auto)` |

## F. Audit integrity

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| F1 | `UPDATE audit_logs` directly | Blocked by DB trigger | `Audit/AuditImmutabilityTest` | `PASS (auto)` |
| F2 | `DELETE FROM audit_logs` | Blocked by DB trigger | `Audit/AuditImmutabilityTest` | `PASS (auto)` |
| F3 | Alter a chained row and re-verify | Verifier reports a broken chain and signals | `Audit/AuditChainVerificationTest`, `Audit/AuditChainFailureSignalTest` | `PASS (auto)` |
| F4 | Mutate a source record as the Audit role | Only flagged-event review metadata is writable | `Audit/AuditSourceMutationDenialTest` | `PASS (auto)` |

## G. Partner integration — outbox tamper (Plan §80 Phase 23 requirement)

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| G1 | `UPDATE` a queued `re_outbound_events` payload after insert | Blocked by the append-only trigger | `Integrations/ReferEarn/OutboxTransactionGuardTest` | `PASS (auto)` |
| G2 | Force a retry to change the event id or body hash | Same ULID `X-Citrus-Event-Id` and `content_sha256` reused across retries | `Integrations/ReferEarn/OutboxDeliveryTest` | `PASS (auto)` |
| G3 | Emit an event carrying client names, phones or raw payment references | Data-minimised payload rejects them | `Integrations/ReferEarn/ReferEarnPayloadDataMinimizationTest` | `PASS (auto)` |
| G4 | Read a partner secret from a delivery response, log or audit row | Redacted before leaving the client | `Security/LogRedactionTest` | `PASS (auto)` |

## H. Partner integration — webhook forgery/replay (Plan §80 Phase 23 requirement)

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| H1 | POST a forged `payment.succeeded` webhook to Servana | **No Wallet webhook route exists.** Owned by Phase 20D-W, blocked behind External Gate W. | `Security/Phase23ThreatModelCoverageTest` (asserts `docs/integrations/wallet/` is absent and no `*/mpesa/*`, `wallet/webhook`, `stk-callback`, `c2b/` route exists) | `BLOCKED` |
| H2 | Replay a captured genuine Wallet webhook | Same as H1 — no target exists | same | `BLOCKED` |
| H3 | POST a forged R&E inbound reconciliation request | **No inbound R&E write route exists.** Owned by Phase 21R-B, blocked behind 20D-W. | `Security/Phase23ThreatModelCoverageTest` | `BLOCKED` |
| H4 | Introduce a direct Safaricom/Daraja credential, SDK or callback | Rejected by static analysis and route inspection | `Security/NoDirectProviderIntegrationTest` | `PASS (absent)` |

> **H1–H3 are `BLOCKED`, not passing.** Servana must not create a webhook route merely to test
> forgery against it — doing so would implement a Wallet-owned capability inside Servana and violate
> Plan §9 rule 20 and the §2.2 ownership matrix. The signature-verification, replay-protection and
> first-seen-`wallet_event_id` controls are specified in Plan §9 rule 21 and will be exercised by the
> Phase 20D-W acceptance suite once Gate W opens. The guard asserts the gate is still closed, so this
> checklist fails loudly rather than going stale if that changes.

## I. Injection and transport

| # | Attempt | Expected | Guard | Result |
|---|---|---|---|---|
| I1 | Inject SQL through a search term, sort or filter | Parameterised queries; allowlisted sort/filter | `Search/SearchInjectionSafetyTest` | `PASS (auto)` |
| I2 | Point a server-side fetch at an internal address (SSRF) | **No user-controlled outbound fetch exists**; the one HTTP client targets a config-pinned base URL | `Security/Phase23ThreatModelCoverageTest` | `PASS (absent)` |
| I3 | Inject headers through an email field | Rejected | `Security/EmailHeaderInjectionTest` | `PASS (auto)` |
| I4 | Read configuration or credentials from a health probe | Redacted | `Security/HealthResponseRedactionTest` | `PASS (auto)` |
| I5 | Mass-assign a protected attribute | Form Request allowlists only | `Security/RouteSecurityContractTest` | `PASS (auto)` |

## Residual manual items

These require a running stack and a human observer; they are recorded in
[`docs/proof/phase-23.md`](../proof/phase-23.md) as they are executed:

- keyboard-only traversal of the critical workflows (Increment 8);
- 200% browser-zoom usability (Increment 8);
- visual confirmation that no personnel phone number renders on any Branch Manager surface.
