# Blocking ambiguities — decisions required from the product owner

This file records governing-source questions an implementation phase could not resolve from the
repository or the source-of-truth hierarchy without inventing a rule. Each entry states the exact
citations, what is missing, what the phase did instead, and the smallest decision required.

Nothing here is a defect record. Defects live in the owning phase's `defect-closure.json`.

---

## UI08-AMBIG-001 — Four Super Administrator contract pages have no backend owner anywhere in the roadmap

**Raised by:** Phase UI-08 (Super Administrator experience), Increment 1 readiness audit.
**Status:** `resolved_by_product_owner` — see the resolution at the end of this entry.
**Resolved by:** [`COR-UI08-001`](cor-ui08-001-super-administrator-backend-enablement.md)
**Blocked (while open):** `implemented` status for four of the twenty-two Super Administrator contract pages.

> The measured evidence below is preserved exactly as it was when the phase stopped. The gap was
> real, the stop was correct, and nothing here is rewritten to suggest otherwise. The resolution is
> appended at the end.

### The four pages

| Map section | Page | Canonical route | Contract status after UI-08 |
|---|---|---|---|
| 5.4.9 | SMS Billing Settings | `/billing/sms` | `planned` |
| 5.4.13 | Subscription Operations | `/billing/subscriptions` | `planned` |
| 5.4.19 | Internal Platform Access | `/platform-access` | `planned` |
| 5.4.20 | Feature Flags | `/platform/feature-flags` | `planned` |

### What each governing source says

1. **`Servana Software Development Plan.md` §80 (feature roadmap) — authority 1.**
   The complete remaining roadmap is Phase 20D-W (Gate W), 21R-B, 21N, and Phase 25. None of them
   delivers platform SMS pricing configuration, platform-wide subscription monitoring, internal
   platform-user administration, or a feature-flag runtime. §64 (Phase 21S — Personnel Bulk SMS)
   creates `sms_billing_entries` but no Super-Administrator pricing surface; the unit cost is read
   from `config('sms.pricing.unit_cost_minor')` (`app/Domain/Messaging/Sms/Support/SmsCostCalculator.php:45`),
   which is deployment configuration, not an effective-dated, versioned, audited platform setting.
   The Plan contains no occurrence of "feature flag" as a product capability, and no
   platform-user-administration section.

2. **`Servana Project Scope.md` (Super Administrator capabilities) — authority 2.**
   Lists, among the Super Administrator's abilities: "Configure SMS billing settings.",
   "View subscription status, subscription invoices, and M-Pesa payment attempts.",
   "Manage internal Citrus Labs Limited platform roles.", "Control platform-level feature flags."
   The Scope therefore asserts the capability. It does not schedule it, and the Scope is explicitly
   subordinate to the Plan on architecture and phase ownership.

3. **`Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md` §5.4.9 / §5.4.13 / §5.4.19 / §5.4.20 — authority 3.**
   Defines all four as full authenticated pages with sub-features and primary actions. §15.3 also
   states: "When the backend phase is future work, the route must not falsely appear implemented."

4. **`docs/frontend/navigation/servana-user-account-navigation-map.yaml` — authority 4 (UI-07, merged).**
   Records all four as `implementation_status: planned`, `backend_owner_phase: null`,
   `runtime_route_name: null`, `permission_any: []`, `permission_all: []`.
   `docs/frontend/audits/ui-07/owner-phase-matrix.json` records the remaining dependency for each as
   "Component, read model, authorization, tests and browser proof."

5. **`docs/auth/permission-matrix.yaml` — authority 5.**
   167 keys (132 active, 35 planned). It contains **no** key for platform SMS billing configuration,
   platform-scope subscription or invoice reading, platform-user administration, or feature-flag
   administration. The twenty-six `platform.*` keys that exist are enumerated in
   `docs/frontend/audits/ui-08/permission-matrix.json`.

6. **The repository — authority 6.**
   `routes/api.php` registers no endpoint for any of the four. There is no `platform_users`,
   `feature_flags`, or platform SMS-pricing table under `database/migrations/`. There is no
   controller, policy, resource, or service for any of them.

### Why this is not something UI-08 may resolve

Delivering any of the four requires at minimum a **new permission key** and, for three of them, a
**new migration** and a **new state machine or approval rule**. The UI-08 authorisation forbids both:

- "UI-08 is not authorized to create or alter permission keys." (directive §23.1)
- "Do not map a missing permission to a 'close enough' key." (directive §23.1)
- "Do not invent it. If a required mutation is absent and implementing it would require a new
  permission, new financial rule, new transition, new migration, new integration contract, or new
  maker/checker rule — stop with the exact missing contract and owner." (directive §18.4)
- Guardrail: "Stop and ask the human before … altering the permission matrix (Plan §10.3)."
  (`CLAUDE.md` §9)

Fabricating a page over an absent contract is also forbidden outright (directive §9.3; UI/UX plan
§15.2 "Real data only"). A "coming soon" production page is forbidden by the same clause.

### What UI-08 did instead

Each of the four keeps `implementation_status: planned` in the canonical authority, which means —
by UI-07's own definition — **no runtime route and no navigation link**. Nothing dead, fake, or
misleading ships. Each carries an exact owner, blocker, and entry condition rather than a vague
deferral, recorded in `docs/frontend/audits/ui-08/gate-disposition.json` and
`docs/frontend/audits/ui-08/page-readiness-matrix.json`.

The distinction from the five Gate-W pages matters and is preserved: those five have a **canonical
external gate** (`external_gate_w`) and therefore render *visible and disabled, naming the gate*.
These four have **no gate and no owner**, so they render nothing at all until a backend phase exists.

### The smallest decision required

For each of the four pages, the product owner must choose exactly one:

- **(a) Authorise a backend phase.** Name the phase, and authorise the permission keys, tables, and
  state machines it will add. UI-08 does not need to be reopened; the page becomes the new phase's
  frontend obligation, or a later UI phase's, at the product owner's discretion.
- **(b) Reclassify the page under an existing external gate**, if the product owner's position is
  that it is genuinely Wallet/Refer-&-Earn dependent. It then renders disabled and names that gate,
  exactly like the five current Gate-W pages.
- **(c) Remove the page from the 160-page contract by authority.** The page becomes
  `removed_by_authority`, and the total and the Super Administrator count change from 160/22 to the
  reduced figures. This is a change to the binding navigation map and must be made in Appendix A of
  the UI/UX plan, not in the generated map.

Until one of (a), (b), or (c) is chosen, the Super Administrator account cannot reach 22/22
`implemented`, and the UI/UX plan's final launch gate (§15.3, "all 160 pages implemented") cannot be
satisfied for this account. That launch gate belongs to UI-17 / Phase 25, not to UI-08.

### Closest-to-deliverable note (for whoever takes decision (a))

`/billing/subscriptions` (5.4.13) is the least expensive of the four. Phase 20B is
`verified_complete` and already owns `subscriptions` and `subscription_invoices`; the page needs one
new platform-scope **read** permission key and one read projection over existing truth — no
migration, no state machine, no financial calculation. The other three need new tables as well.

---

## Resolution

**Status:** `resolved_by_product_owner`
**Decision ID:** `COR-UI08-001`
**Decision record:** [`docs/decisions/cor-ui08-001-super-administrator-backend-enablement.md`](cor-ui08-001-super-administrator-backend-enablement.md)
**Date:** 2026-08-05
**Implementation branch:** `phase-ui-08-super-administrator-experience`

### Decision

**Option (a) — authorise the minimum backend enablement**, for all four pages.

```text
5.4.9   SMS Billing Settings        /billing/sms
5.4.13  Subscription Operations     /billing/subscriptions
5.4.19  Internal Platform Access    /platform-access
5.4.20  Feature Flags               /platform/feature-flags
```

All four remain mandatory launch pages and must reach `implemented` inside Phase UI-08, under the
bounded corrective backend owner `COR-UI08-001`.

### Options not selected

- **(b) Gate-W reclassification — not selected.** None of the four depends on Wallet by Citrus.
  Recording a Wallet gate on an unbuilt page would misreport why it is unavailable and would corrupt
  the meaning of `disabled_by_gate`.
- **(c) Contract removal — not selected.** Both product authorities require the capabilities;
  removal would reduce the Super Administrator count below 22 and the total below 160.
- **Leaving them `planned` at UI-08 completion — not selected.** Once a backend owner exists,
  `planned` becomes untruthful (UI/UX plan §15.3).

### Effect on this entry

The gap this entry recorded was **backend assignment and delivery**, not missing product intent.
That finding stands. `COR-UI08-001` supplies the authorization the original stop condition required,
including the two new permission keys (`platform.internal_access.view`,
`platform.internal_access.manage`) and the exact data, API, security, audit and testing rules.

The required final Super Administrator disposition becomes:

```text
17 implemented
5  disabled_by_gate
0  planned
0  removed_by_authority
22 total
```

Each of the four transitions to `implemented` only after its real backend contract, permission
mapping, route, component, tests and browser proof exist — never at authorization time.
