# Phase 4 Proof — Frontend Foundation

**Branch:** `phase-4-frontend-foundation`
**Date:** 2026-06-14
**Plan sections implemented:** §6 (Frontend Architecture), §12 (Design System), §13 (Responsive), §14 (Dark Mode), §15 (Accessibility), §16 (Forms)

---

## 1. Evidence of requirement

Phase 4 is mandated by Plan §27 Phase 4 objective: "§6 skeleton + §12 design system core." Required deliverables:

- 8 role layouts with accessible landmarks
- Router with UX-only guard stubs
- 6 typed Pinia stores
- `services/apiClient.ts` mapping Phase 3 error envelopes
- `composables/useForm<T>` with server 422 field merging
- 9 core UI components (SvButton, SvInput, SvSelect, SvTextarea, SvCard, SvModal, SvToast, SvStateBoundary, SvEmptyState)
- Light + dark theme tokens (already in Phase 1; extended/verified)
- Head theme flash-prevention script (already in Phase 1; verified)
- Storybook-style demo page at `/dev/design-system`
- Vitest and Playwright coverage
- axe clean on demo page

---

## 2. Files created

### Layouts
- `resources/spa/src/layouts/AuthLayout.vue`
- `resources/spa/src/layouts/PlatformAdminLayout.vue`
- `resources/spa/src/layouts/MerchantLayout.vue`
- `resources/spa/src/layouts/BranchLayout.vue`
- `resources/spa/src/layouts/FrontOfficeLayout.vue`
- `resources/spa/src/layouts/PersonnelLayout.vue`
- `resources/spa/src/layouts/FinanceLayout.vue`
- `resources/spa/src/layouts/AuditLayout.vue`

Each includes: skip link, `<header>`, `<nav>` (where relevant), `<main id="main-content">`, ARIA landmarks per Plan §15.1 and §15.9.

### Router
- `resources/spa/src/router/index.ts` (updated — integrates all route modules)
- `resources/spa/src/router/guards.ts`
- `resources/spa/src/router/routes/auth.ts`
- `resources/spa/src/router/routes/platform.ts`
- `resources/spa/src/router/routes/merchant.ts`
- `resources/spa/src/router/routes/branch.ts`
- `resources/spa/src/router/routes/hr.ts`
- `resources/spa/src/router/routes/finance.ts`
- `resources/spa/src/router/routes/frontOffice.ts`
- `resources/spa/src/router/routes/personnel.ts`
- `resources/spa/src/router/routes/audit.ts`

Guards are UX-only stubs (`requiresAuth`, `requiresRole`, `requiresPermission`, `requiresActiveMerchant`). Backend is the security boundary.

### Stores
- `resources/spa/src/stores/authStore.ts`
- `resources/spa/src/stores/merchantStore.ts`
- `resources/spa/src/stores/branchStore.ts`
- `resources/spa/src/stores/permissionStore.ts`
- `resources/spa/src/stores/themeStore.ts`
- `resources/spa/src/stores/notificationStore.ts`

### Services / Composables
- `resources/spa/src/services/apiClient.ts` — axios instance, `parseApiError()`, interceptor
- `resources/spa/src/composables/useForm.ts` — typed values, dirty, touched, errors, `mergeServerErrors()`, `handleSubmit()`

### Types / Utils
- `resources/spa/src/types/api.ts` — `ApiError`, `ApiErrorCode`, `ApiErrorEnvelope`, `Paginated<T>`
- `resources/spa/src/types/models.ts`
- `resources/spa/src/types/enums.ts`
- `resources/spa/src/utils/money.ts`
- `resources/spa/src/utils/dates.ts`

### UI Components
- `resources/spa/src/components/ui/SvButton.vue`
- `resources/spa/src/components/ui/SvInput.vue`
- `resources/spa/src/components/ui/SvSelect.vue`
- `resources/spa/src/components/ui/SvTextarea.vue`
- `resources/spa/src/components/ui/SvCard.vue`
- `resources/spa/src/components/ui/SvModal.vue`
- `resources/spa/src/components/ui/SvToast.vue`
- `resources/spa/src/components/ui/SvStateBoundary.vue`
- `resources/spa/src/components/ui/SvEmptyState.vue`

### Demo / Stubs
- `resources/spa/src/pages/dev/DesignSystemDemo.vue`
- `resources/spa/src/pages/auth/LoginStub.vue`
- `resources/spa/src/pages/auth/VerifyStub.vue`
- 8 × `DashboardStub.vue` for each role area

### Tests
- `resources/spa/src/services/apiClient.spec.ts` (10 cases)
- `resources/spa/src/composables/useForm.spec.ts` (8 cases)
- `resources/spa/src/components/ui/SvStateBoundary.spec.ts` (8 cases)
- `tests/e2e/frontend-foundation.spec.ts` (11 Playwright cases)

### Config
- `playwright.config.ts`

---

## 3. Accessibility violations found and fixed

**Bug Fix Protocol applied:**

**Violation 1: `aria-prohibited-attr`**
- Observed: `aria-label` on a `<div>` with no ARIA role is prohibited (axe `wcag2a`).
- Root cause: `SvStateBoundary` loading state used `aria-label` on a plain `div`.
- Fix: Added `role="status"` to the loading skeleton div — semantically correct (`role="status"` implies a live region; `aria-label` is permitted).
- File: `resources/spa/src/components/ui/SvStateBoundary.vue`

**Violation 2: `color-contrast`**
- Observed: White (`#ffffff`) text on Savannah Orange (`#f97316`) background gives 2.8:1 contrast — below WCAG AA 4.5:1 for 14px text.
- Root cause: All primary buttons and CTA buttons used `text-white` on `bg-primary`.
- Fix: Changed primary button text to `text-brand-deep` (`#4A2208`). Dark brown on orange: calculated contrast ≈ 4.78:1 ✓ passes AA.
- Files: `SvButton.vue`, `SvStateBoundary.vue` (empty-action button), `SvEmptyState.vue`

---

## 4. Test results

### Vitest — 27 passed, 0 failed

```
Test Files  4 passed (4)
     Tests  27 passed (27)
  Start at  09:32:27
  Duration  4.08s
```

Files covered:
- `apiClient.spec.ts` — 10 tests: error envelope parsing for all 7 named error codes + malformed/missing cases
- `useForm.spec.ts` — 8 tests: init, dirty, reset, field error, server 422 merge, duplicate-submit prevention, touch, error recovery
- `SvStateBoundary.spec.ts` — 8 tests: loading/empty/error/success states, emits, defaults
- `Home.spec.ts` — 1 test (Phase 1, passes)

### Playwright — 11 passed, 0 failed

```
11 passed (17.0s)
```

Tests:
1. App shell at 360px — no horizontal scroll ✓
2. App shell at 768px — no horizontal scroll ✓
3. App shell at 1280px — no horizontal scroll ✓
4. Demo at 360px — renders, no horizontal scroll ✓
5. Demo at 768px — no horizontal scroll ✓
6. Demo at 1280px — no horizontal scroll ✓
7. All core UI components visible ✓
8. Theme toggle light↔dark ✓
9. Keyboard focus reaches interactive controls ✓
10. Modal opens and closes with Escape ✓
11. axe WCAG 2 AA scan — 0 violations ✓

### Backend quality gates (local PHP 8.5)

```
Pint: PASS (no violations)
Larastan level 8: PASS (0 errors)
PHP tests: 40 passed, 1 skipped (DeepHealthTest — requires Docker DB+Redis, same as Phase 3)
composer audit: 1 ignored with documented rationale (CVE-2026-48019, tracked since Phase 1)
npm audit --audit-level=high: 0 vulnerabilities
gitleaks --no-git: no leaks found
```

---

## 5. Skipped items (deferred)

```
Skipped:
- Item: Full Magic Link authentication flow
- Reason: Phase 4 stubs auth routes only; no token issuance or session logic.
- Correct future phase: Phase 5 (Authentication)
- Risk if forgotten: no login.

Skipped:
- Item: Authenticated /me bootstrap and real auth store data
- Reason: Requires Phase 5 auth flow.
- Correct future phase: Phase 5
- Risk if forgotten: auth store stays empty; guards are always UX stubs.

Skipped:
- Item: Account and tenant model
- Correct future phase: Phase 6
- Risk if forgotten: no multi-tenancy.

Skipped:
- Item: Tenant middleware / tenant data hardening
- Correct future phase: Phase 6 / Phase 9
- Risk if forgotten: cross-tenant leakage not enforced.

Skipped:
- Item: Branches, memberships, invitations
- Correct future phase: Phase 7
- Risk if forgotten: no org structure.

Skipped:
- Item: Role and permission registry
- Correct future phase: Phase 8
- Risk if forgotten: no authorization; guards stay as stubs.

Skipped:
- Item: Full /api/v1 route surface and pagination traits
- Correct future phase: Phase 10 (API foundation)
- Risk if forgotten: no API endpoints.

Skipped:
- Item: Final role navigation implementation (full verbatim nav lists)
- Correct future phase: Phase 11
- Risk if forgotten: nav stubs stay; no working navigation.

Skipped:
- Item: Full responsive sweep across all product workflows
- Correct future phase: Phase 12
- Risk if forgotten: mobile gaps in product screens.

Skipped:
- Item: Full dark mode across all product workflows
- Correct future phase: Phase 13
- Risk if forgotten: dark mode incomplete in product screens.

Skipped:
- Item: Full accessibility release gate across all critical flows
- Correct future phase: Phase 14
- Risk if forgotten: axe CI gate not yet blocking for all gated pages.

Skipped:
- Item: Horizon, upload scanning, opcache, deployment
- Correct future phase: Phase 21 / Phase 23 / Phase 24 / Phase 25
- Risk if forgotten: same as Phase 3 carry-forward.
```

---

## 6. Known risks

- `DeepHealthTest` still requires Docker (PostgreSQL + Redis) to pass — unblocked by `make test`.
- CVE-2026-48019 still ignored-with-rationale; tracked since Phase 1.
- Primary button contrast fix (`text-brand-deep` on orange) is a deviation from the brand doc's "white on orange" assumption, but it is required for WCAG AA compliance. The brand's `#F97316` simply does not meet 4.5:1 with white text at 14px. This should be recorded for the brand owner's review.
- Router guards are UX stubs; backend authorization remains the only security boundary (correct, per Plan §6.2).

---

## 7. Context for Phase 5 (Authentication — Magic Link)

- Branch from merged main (after this PR merges) as `phase-5-authentication`.
- `authStore`, `apiClient`, `useForm`, and `AuthLayout` are ready for Phase 5 to wire in.
- Phase 5 implements: Magic Link request page, `/auth/verify` token consumption, Sanctum session bootstrap, `/api/v1/me` call, `authStore` population, and all 7 Scope §2.3 checks.
- `primeCsrfCookie()` in `apiClient.ts` is ready to be called before first mutating request in Phase 5.
