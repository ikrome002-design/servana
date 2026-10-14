# COR-UI08-UI12-VIS-001 — product-owner visual review package

**Status of every "after" image in this package: `agent-reviewed / pending product-owner visual acceptance`.**
No product-owner approval has been given or is implied. Approval is a separate, tracked decision
and does not block the code pull request (prompt §8).

## How to review

1. Open the comparison sheets in [`review/`](review/) — each is **before (base `562ffbb`) | after**,
   plus **after with the synthetic populated overlay** where the deterministic fixtures render an
   empty page.
2. For any page, the full-resolution frames are in `screenshots/before/desktop-light/`,
   `screenshots/after/{desktop-light,desktop-dark,mobile-light}/` and
   `screenshots/populated/{desktop-light,desktop-dark,mobile-light}/`, named by contract key.
   Hashes are in `visual-baseline-index.json`.
3. Per-page design intent, root-cause family, reference mapping and the 15-dimension score are in
   `page-redesign-acceptance.json`.

## Five primary dashboards

| Account | Sheet | What changed |
|---|---|---|
| Super Administrator | [review/super_administrator.dashboard.png](review/super_administrator.dashboard.png) | Deep Earth header capsule with pill groups; lifecycle feature panel leads an asymmetric split; governance queue, commercial, registrations and audit regions; gated integrations stated |
| Merchant Administrator | [review/merchant_administrator.dashboard.png](review/merchant_administrator.dashboard.png) | Floating sidebar; editorial owner header; subscription-health feature; owner routes; KPI band; reporting gate |
| Branch | [review/merchant_branch.dashboard.png](review/merchant_branch.dashboard.png) | Branch-day pulse feature; today's flow as numbered steps; revenue KPI; gate notice |
| Human Resource | [review/merchant_human_resource.dashboard.png](review/merchant_human_resource.dashboard.png) | Readiness dimensions as ready-versus-gap bars; task queue; gap KPIs; payout handoff |
| Finance | [review/merchant_finance.dashboard.png](review/merchant_finance.dashboard.png) | Risk feature with linked checker dials; priority queue with step-up markers; close-control KPIs; per-currency money ledger. **Candid note:** UI-16 had already given this page a rich hero, so this is an evolution of that composition (separated editorial header, drill-through dials and KPIs, refined shell) rather than a new layout. |

## Critical archetypes

| Archetype | Sheet |
|---|---|
| Billing / rule workspace | [review/super_administrator.billing-settings.png](review/super_administrator.billing-settings.png) |
| Subscription statement | [review/merchant_administrator.subscription-invoice-detail.png](review/merchant_administrator.subscription-invoice-detail.png) |
| Account / profile | [review/merchant_administrator.account.png](review/merchant_administrator.account.png), [review/merchant_finance.settings.png](review/merchant_finance.settings.png) |
| Form (stepped, preview rail) | [review/merchant_human_resource.compensation-setup.png](review/merchant_human_resource.compensation-setup.png) |
| Data list / catalogue | [review/merchant_branch.services.png](review/merchant_branch.services.png), [review/merchant_human_resource.staff.png](review/merchant_human_resource.staff.png) |
| Cash-up reconciliation | [review/merchant_finance.cash-up.png](review/merchant_finance.cash-up.png) |
| Compensation contract | [review/merchant_human_resource.compensation-detail.png](review/merchant_human_resource.compensation-detail.png) |
| Period locks | [review/merchant_finance.periods.png](review/merchant_finance.periods.png) |
| Step-up / decision states | [review/merchant_finance.payments-validation-detail.png](review/merchant_finance.payments-validation-detail.png); decision dialog baseline `tests/e2e/cor-ui08-ui12-visual-regression.spec.ts-snapshots/overlay--finance-decision-dialog-chromium-win32.png` |

## Known limitations of this evidence

- **Before frames use only the default fixtures.** Pages whose fixtures are empty show an empty
  state before and after; their populated after frame has no populated before counterpart (the
  base source was not re-captured with the overlay).
- Dense dialog forms that take raw identifiers (refund request, open dispute, liability
  adjustment, payout filters) keep the shipped API contract's ID fields; these pages score 29.
- Visual judgement here is the agent's. The rubric totals (29–30) supplement, and never replace,
  the owner's own before/after comparison.

## Product-owner decision

| Field | Value |
|---|---|
| Decision | **pending** |
| Reviewer | — |
| Date | — |
| Pages accepted | — |
| Pages needing changes (with notes) | — |

## Codex review correction (2026-10-14)

Compensation Detail had clipped populated mobile terms despite the original agent score.
Review the [actual clipped frame](codex-defects/hr-compensation-detail-mobile-clipped.png)
and [corrected mobile terms](screenshots/populated/mobile-light/merchant_human_resource.compensation-detail.png).
The labels now stack above complete values; the updated comparison sheet uses the actual corrected
desktop frame. Two light/dark text-fit tests cover all required widths and 200% equivalent.
Product-owner approval remains pending for every page.
