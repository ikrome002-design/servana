# Phase 23 — Export, Report and Download Hardening Matrix

> **Authority:** Plan §65 (Files and Media), §67 (queues/scheduler), §74 (privacy, masking,
> retention, deletion), §13.5 + ADR-010 (Audit exports), §43 (receipts), §49 (subscription
> invoices), §63 (personnel earnings), §64 + ADR-010 (permanent contact-export prohibition).
>
> **This document is a rendering, not the source of truth.** The authoritative matrix is
> `P23_EXPORT_SURFACES` / `P23_NON_DOCUMENT_ROUTES` inside
> [`tests/Feature/Security/Phase23ExportHardeningTest.php`](../../tests/Feature/Security/Phase23ExportHardeningTest.php).
> The guard fails when a new export-shaped route ships unclassified, when a classified route
> disappears, or when a declared control is no longer present on the live route.

---

## 1. Scope — every document surface in the product

`php artisan route:list` was enumerated live and filtered on
`export|download|pdf|statement|receipt|invoice|report|document`. **23 surfaces** are document
surfaces; **13 further matches** are ordinary permissioned reads or mutations that serve no file
and are recorded with a reason in `P23_NON_DOCUMENT_ROUTES` (they are not silently ignored).

There is **no** report-download route, no scheduled-report delivery route, and no day-close /
cash-up PDF route in the live table: `FilePurpose::DayCloseReport` and `FilePurpose::CashUpReport`
exist in the Phase 10F registry as generated-only purposes whose generators belong to **Phase 21N**
(Plan §69). Phase 23 hardens what is implemented and fabricates nothing.

---

## 2. The matrix

Legend — `A` authenticated · `T` tenant-scoped · `B` branch-scoped · `P` permission middleware ·
`C` controller/policy authorization · `M` fresh step-up (`RequireFreshMfa`) · `R` reason required ·
`G` billing-read-only generation gate · `S` signature required · `F` Phase 10F `FileAccessService`
boundary · `#` download accounting.

| # | Surface | Verb | Kind | Authority | Controls | Evidence |
|---|---|---|---|---|---|---|
| 1 | `finance-exports.index` | GET | read | `FinanceExportPolicy::viewAny` | A T C | `FinanceExportTest`, `ProtectedReadAuthorizationCoverageTest` |
| 2 | `finance-exports.show` | GET | read | `FinanceExportPolicy::view` | A T C | `FinanceExportTest` (foreign-tenant 404) |
| 3 | `finance-exports.store` | POST | request | `finance_export.create` | A T P M R | `FinanceExportTest`, `RouteSecurityContractTest` |
| 4 | `finance-exports.download-link` | POST | link | `finance_export.download` | A T P F # | `FinanceExportTest`, `Phase23ExportHardeningTest` |
| 5 | `finance-exports.revoke` | POST | request | `finance_export.create` | A T P | `FinanceExportTest`, `Phase23ExportHardeningTest` |
| 6 | `audit-exports.index` | GET | read | `audit.export` | A T P | `AuditExportAuthorizationTest` |
| 7 | `audit-exports.show` | GET | read | `audit.export` | A T P | `AuditExportIsolationTest` |
| 8 | `audit-exports.store` | POST | request | `audit.export` | A T B P M R | `AuditExportStepUpTest`, `AuditExportWorkflowTest` |
| 9 | `audit-exports.download-link` | POST | link | `audit.export` | A T B P F | `AuditExportExpiryDownloadCountTest` |
| 10 | `audit-exports.download` | GET | **stream** | `AuditExportPolicy::download` | A T S C F # | `AuditExportExpiryDownloadCountTest`, `AuditExportAuditTest` |
| 11 | `audit-exports.revoke` | POST | request | `audit.export` | A T B P | `AuditExportWorkflowTest`, `Phase23ExportHardeningTest` |
| 12 | `files.show` | GET | read | `FileAccessService::authorizeView` | A T C F | `FileDownloadAuthorizationTest`, `FileTenantIsolationTest` |
| 13 | `files.download-link` | POST | link | `FileAccessService::authorizeDownload` | A T C F | `FileDownloadAuthorizationTest` |
| 14 | `files.download` | GET | **stream** | `FileAccessService::authorizeDownload` | A T S C F | `FileSignedUrlExpiryTest`, `FileDownloadAuthorizationTest` |
| 15 | `receipts.index` | GET | read | `receipt.view` | A T P | `ReceiptApiTest` |
| 16 | `receipts.show` | GET | read | `receipt.view` | A T P | `ReceiptApiTest` |
| 17 | `receipts.download-link` | POST | link | `receipt.view` + `ReceiptPolicy::download` | A T P F | `ReceiptDownloadAuthorizationTest` |
| 18 | `subscription-invoices.index` | GET | read | `merchant.subscription.invoice.view` | A T P | `SubscriptionInvoiceApiTest` |
| 19 | `subscription-invoices.show` | GET | read | `merchant.subscription.invoice.view` | A T P | `SubscriptionInvoiceApiTest` |
| 20 | `subscription-invoices.pdf.generate` | POST | generate | `merchant.subscription.invoice.download` | A T P G | `SubscriptionInvoicePdfTest`, `FileBillingReadOnlySeamTest` |
| 21 | `subscription-invoices.pdf.download-link` | GET | link | `merchant.subscription.invoice.download` | A T P F | `SubscriptionInvoicePdfDownloadTest`, `Phase23ExportHardeningTest` |
| 22 | `personnel.statements.generate` | POST | generate | `personnel.my_statements.download` + own-scope | A T P G F own | `EarningsStatementTest`, `FileDownloadAuthorizationTest` |

Byte-serving routes are exactly **two** (rows 10 and 14). Both require a valid signature **and**
authentication **and** re-run the authorization check at stream time — a signature is transport,
never authorization. Every other surface issues a short-lived signed link
(`files.signed_url_ttl_minutes`, default 5) after authorizing at issuance.

---

## 3. Control-by-control disposition (prompt §9 checklist)

| # | Required control | Disposition | Evidence |
|---|---|---|---|
| 1 | Authenticated | **Yes**, all 22 | `Phase23ExportHardeningTest` control assertion (`auth`) |
| 2 | Tenant-scoped | **Yes** — `ResolveTenantContext` + `EnsureMerchantActive` on all 22; `FileAccessService` 404s a foreign merchant | `FileTenantIsolationTest`, `AuditExportIsolationTest` |
| 3 | Branch-scoped where applicable | **Yes** — Audit export create/link/revoke carry `EnsureBranchScope`; the CSV query pins `branch_id` | `AuditExportIsolationTest` |
| 4 | Permission-authorized | **Yes** — 16 by `EnsurePermission`, 6 by an explicit policy/service call | control assertion (`permission` / `authorization`) |
| 5 | Reason-gated where required | **Yes** — finance + audit export creation both require `reason` | control assertion (`reason`) |
| 6 | MFA-gated | **Yes** — `EnsurePrivilegedMfa` on the whole `api/v1` group | `PermissionMfaCoverageTest` |
| 7 | Fresh step-up where the Plan requires | **Yes** — `finance_export_create`, `audit_export_create` | `AuditExportStepUpTest`, `PermissionStepUpCoverageTest` |
| 8 | Billing read-only behaviour correct | **Yes** — new generation blocked, existing download always allowed | `FileBillingReadOnlySeamTest`, `SubscriptionInvoicePdfDownloadTest` |
| 9 | Private object storage | **Yes** — `files.disk` is private; writes confined to the file domain | `FileStorageBoundaryTest` |
| 10 | Short-lived signed access or authorized streaming | **Yes** — 5-minute TTL; both stream routes signed | `FileSignedUrlExpiryTest` |
| 11 | Authorization rechecked at download time | **Yes** — `authorizeDownload` runs at issuance **and** at the stream | `Phase23ExportHardeningTest` (stream case) |
| 12 | Download count recorded where required | **Yes** — finance at link issuance; audit at the stream (ADR-010) | `AuditExportExpiryDownloadCountTest`, `FinanceExportTest` |
| 13 | Expiry enforced | **Yes** — and, as of PH23-EXP-001, on the file path too | `Phase23ExportHardeningTest` |
| 14 | Revocation enforced | **FIXED — PH23-EXP-001** (was enforced only on the export's own route) | `Phase23ExportHardeningTest` |
| 15 | Masking applied | **Yes** — `AuditValueMasker`; finance CSV carries masked references only | `AuditExportMaskingTest`, `FinanceExportTest` |
| 16 | No raw contact export | **Yes** — no export type is contact-shaped; the CSV builder selects no contact column | `Phase23ExportHardeningTest`, `SmsContactExportProhibitionTest` |
| 17 | No secrets exposed | **Yes** | `Phase23ContractPrivacyTest`, `FileLogRedactionTest` |
| 18 | No sequential/internal ids exposed | **Yes** — ULIDs only | `AuditExportMaskingTest`, `RouteBindingTest` |
| 19 | No private storage path exposed | **Yes** — path/hash are `$hidden` | `AuditExportMaskingTest`, `ReceiptDownloadAuthorizationTest` |
| 20 | Generation bounded/chunked | **Yes** — both CSV builders stream `chunkById(500)` | source; `AuditExportWorkflowTest` |
| 21 | Generation idempotent where required | **Yes** | `AuditExportJobIdempotencyTest`, `FinanceExportTest`, `EarningsStatementTest` |
| 22 | Audit events occur exactly once | **Yes** | `AuditExportAuditTest`, `FinanceExportTest`, `AuditMutationCoverageTest` |

---

## 4. Audit-role specific requirements

| Requirement | Disposition | Evidence |
|---|---|---|
| Branch-scoped only | `EnsureBranchScope` on create/link/revoke; the snapshotted `branch_id` pins the query | `AuditExportIsolationTest` |
| Merchant-level rows (`branch_id IS NULL`) never exported | `AuditExportCsvBuilder::scopedQuery()` applies `where('branch_id', …)` **and** `whereNotNull('branch_id')` | `AuditExportIsolationTest` — *"never includes merchant-level (branch_id null) audit rows in a branch export"* |
| Review/export metadata is the only Audit write surface | Audit holds `audit.flag` + `audit.export` and no other mutating key; overrides can never grant one | `PermissionRoleBoundaryTest`, `Phase23PermissionActivationTest` |
| Source operational rows immutable | Audit holds no operational mutation key | `PermissionRoleBoundaryTest` |
| Source financial rows immutable | Audit holds no financial mutation key | `PermissionRoleBoundaryTest` |
| Hash-chain source rows immutable | DB trigger blocks `UPDATE`/`DELETE` on `audit_logs` | `AuditChainVerificationTest`, `AuditSchemaTest` |

---

## 5. Defects found and fixed in Increment 4

Both are recorded in full Bug-Fix-Protocol form in [`docs/proof/phase-23.md`](../proof/phase-23.md) §9.

### PH23-EXP-001 — export revocation and expiry did not reach the file domain

Revoking (or expiring) a finance/audit export set the **export aggregate's** status only. The
`UploadedFile` stayed `available/clean`, and the Phase 10F file routes authorize on the **file's**
lifecycle — so the generic `POST /api/v1/files/{ulid}/download-link` re-issued a fresh signed URL
for a revoked export **indefinitely**, and an in-flight signed URL kept streaming it. The caller
learns the file ULID from the very signed URL it was legitimately issued, so no guessing is needed.
Fixed by propagating the terminal state onto the file inside the same transaction; the retention
sweep now also sweeps `revoked` rows so byte cleanup is unchanged.

### PH23-EXP-002 — the billing invoice PDF purpose declared no resource permission

`FilePurpose::BillingInvoicePdf` carried `permission => null` with no owner scope, so tenant
membership alone authorized it: **Front Office and Personnel could download the merchant's
subscription invoice** through the generic file routes, bypassing the Merchant-Administrator-only
`merchant.subscription.invoice.download` that guards the domain route. Fixed by declaring that
existing key on the purpose. A new guard case now fails for **any** generated purpose that has
neither a resource permission nor owner scope — the mechanical check this defect escaped.

---

## 6. Residual risks

1. **Byte retention after a domain-triggered expiry.** `ExpireFinanceExport` / `ExpireAuditExport`
   now mark the file `expired`, which the retention sweep (`ExpireSignedExport`, `available` +
   `revoked`) does not re-select. In production this is inert: neither action is scheduled, and the
   hourly sweep fires at the same `retention_until` instant that sets `expires_at`. If a future
   phase schedules the domain expiry it must run **after** the file sweep, or the sweep must widen.
   Recorded as **REM-EXP-001**.
2. **The matrix is route-shaped.** A future document surface that is neither named nor routed with
   an export-shaped token would not be caught by the "nothing escapes" case. The generated-purpose
   authority guard (§5, PH23-EXP-002) is the second net and is shape-independent.
3. **Day-close / cash-up report purposes are registered but generator-less** (Phase 21N, Plan §69).
   They are permission-gated (`reports.view`) and retention-bounded today, so they are hardened in
   advance; their delivery controls belong to their owning phase.
