# UI-15 visual-language continuity — handed to UI-16

## Inherited and reused (no parallel design system)

| Primitive | Origin | UI-15 use |
|---|---|---|
| `SvTonalPageHeader` | UI-14 | Header of every Audit page except Dashboard, Get Started and Account |
| `SvOperationalHero` | UI-13 | Dashboard command centre; Audit Get Started orientation |
| `SvStatusBadge` | UI-04 | Review pill, export status, integrity status |
| `SvPagination`, `SvStateBoundary`, `SvSkeleton`, `SvErrorState`, `SvEmptyState`, `SvDialog`, `SvBreadcrumbs`, `SvDateTime`, `SvTextArea`, `SvSelect`, `SvCheckbox`, `SvAlert` | UI-04 | Throughout |
| `AccountAndSecurity` (`experience="audit"` added) | UI-08/UI-14 | Account and Preferences runtime |

## New, reusable Audit compositions (candidates for UI-16 registration)

`components/audit/`: `AuditSeverityBadge`, `AuditReviewBadge`, `AuditEventCard` (time-led evidence
record), `AuditFilterPanel` (URL-held filter rail), `AuditEventFeed` (server-total feed with
page-local repeat clustering), `AuditDiff` (table → stacked-card diff), `AuditFlagDialog`,
`AuditViewPage` (asymmetric lens page), `AuditExportStatusCard`. Support: `utils/auditPresentation.ts`,
`composables/useAuditRouteFilters.ts`. None is registered in `design-system/componentRegistry.ts`;
registration and promotion to `Sv*` belongs to UI-16 (primitive registry work deferred by UI-14).

## Shared-component changes made in UI-15 (cross-account effect)

- `SvBreadcrumbs` link is now a 44px target (`inline-flex min-h-sv-touch px-1`). Consumers: the three
  Audit records and UI-08 `MerchantDetail.vue` (UI15-A11Y-001). UI-16 should confirm the UI-08 page.
- `design-system/icons.ts` gained seven outline icons (flag, chain link, download, clock, eye-slash,
  shield-exclamation, fingerprint).
- `RoleGetStarted.vue` gained an Audit hero branch (UI-13/UI-14 precedent).

## Known older visual debt — recorded, not redrawn (owner: UI-16 after UI-15 merges)

| Account | Page groups to re-audit in UI-16 (not inspected page-by-page in UI-15; from the UI-14 handoff and component usage) |
|---|---|
| UI-08 Super Administrator | Billing catalogue, fee configuration and feature-flag pages — compare against the UI-13/UI-14/UI-15 tonal hierarchy |
| UI-09 Merchant Administrator | Staff overview, compensation summary and platform-fee pages — compare KPI and card treatment |
| UI-10 Branch | Service catalogue, calendar and branch audit pages; `BranchAudit.vue` composes `SvPageHeader` + `SvCard` (verified by import) and could adopt `AuditEventCard` |
| UI-11 Human Resource | HR audit activity and payout preparation — `hr/audit-activity` is a candidate for `AuditEventCard` |
| UI-12 Finance | `FinanceAuditView` still uses the Phase 19 `AuditDomainEvents` panel (verified; kept unchanged by UI-15 to avoid touching Finance) |
| Shared | `GetStartedChecklist` body (plain checkbox rows) on every account; `sm:`/`xl:` dead utilities (UI-14 sweep list); `rounded-pill` appearance check |

UI-15 deliberately did not modify those accounts beyond the two shared fixes above.
