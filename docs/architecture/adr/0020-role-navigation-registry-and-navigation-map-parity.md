# ADR-020 — Role-Navigation Registry, Navigation Placement, and Navigation-Map Parity

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime parity deferred to Phase UI-07).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §2.2 (explicit UI placement supersession), §7.1–§7.5, §28.3, §28.4,
  §30; navigation map §3 (shared navigation shell), §13 (cross-role ownership matrix).
- **Related:** ADR-016, ADR-017; ADR-024 (fixed footer).

## Context

The repository already ships a role-navigation registry (`resources/spa/src/navigation/roleNavigation.ts`),
a `RoleNavigation.vue` component, and a screen inventory (`docs/frontend/screens/inventory.json`)
guarded by `screenInventory.spec.ts`. That substrate was built in Phase 11 and corrected in Phase 23.

The corrective UI programme binds a much larger contract: **160** authenticated pages across eight
accounts, specified in `servana-user-account-navigation-maps.md`.

## Problem proven

Phase UI-00 parsed the binding specification and the §30 register deterministically
(`node scripts/generate-ui-source-inventory.mjs`). Both describe the same 160 pages with identical
routes, hosts, placements and purposes:

| Account | Required pages |
|---|---:|
| Super Administrator | 22 |
| Merchant Administrator | 23 |
| Branch | 18 |
| Human Resource | 19 |
| Finance | 24 |
| Front Office | 19 |
| Personnel | 20 |
| Audit | 15 |
| **Total** | **160** |

The current screen inventory describes what is **built**, not what is **required**. Conflating the
two is the drift this ADR exists to prevent: treating the Phase 11 substrate as the finished contract
would silently discharge most of the 160-page requirement.

Separately, the product owner's directive supersedes the navigation map's generic shell statement on
one narrow point — navigation *placement*.

## Decision

### 1. Navigation placement (the supersession, placement only)

- The authenticated **Super Administrator** account uses **header** primary navigation on desktop.
- **Every other** authenticated account uses **left-side** primary navigation on desktop.
- Tablet may collapse left navigation into a collapsible rail.
- Mobile may collapse it into an accessible left-anchored drawer.

This changes placement and nothing else. It does not change page ownership, page names, routes,
permissions, tenant scope, branch scope, personnel own-scope, maker/checker, MFA or step-up, Audit
read-only behaviour, Merchant Administrator authority limits, or Super Administrator governance
limits.

### 2. Two registers, never merged

- **Required page contract** — the 160 pages, generated from the binding specification. It is what
  the programme owes.
- **Implementation inventory** — `docs/frontend/screens/inventory.json`, which states what exists.

A page moves from the first to the second only when it is genuinely built.

### 3. Allowed implementation statuses

Exactly `implemented`, `planned`, `disabled_by_gate`, `removed_by_authority` (plan §7.2).
`implemented` requires a real route, real component, truthful read model, authorization, tests and
browser proof. `planned` must never create a dead or fake navigation link.

### 4. Parity is machine-checked

The navigation map, screen specifications, `inventory.json`, `inventory.yaml`, Vue Router, the
navigation registry, the permission matrix and the tests must agree. A parity test fails on any
missing, duplicated, renamed or incorrectly owned page.

## Scope

Navigation registry, navigation placement, route table, screen inventory, screen specifications, and
their parity guards.

## Non-goals

Implementing any of the 160 routes; generating placeholder Vue pages; marking pages implemented;
replacing the as-built inventory with the requirements map; creating dead navigation links.

## Security implications

Navigation visibility is UX only. A navigation entry is not a permission, and hiding one is not a
control (ADR-017). The registry may filter entries by permission for usability, but the server
remains the authority. `removed_by_authority` entries must not appear in the router at all — this
is how the Personnel contact-export prohibition and the Super Administrator merchant-creation and
impersonation prohibitions stay structurally absent.

## Accessibility implications

Header, sidebar, rail and drawer are all landmark-labelled, keyboard operable, and expose current-page
state to assistive technology. The mobile drawer traps focus while open, closes on `Escape`, and
restores focus to its trigger. Icons never carry meaning alone.

## Responsive implications

Placement changes at the shipped breakpoints — mobile ≤767, tablet 768–1024, desktop ≥1025 — using
CSS media queries only. No JavaScript device detection.

## Operational implications

The 160-page contract becomes a generated artifact regenerated from the plan, so the register cannot
drift from the specification without CI noticing.

## Consequences

- The size of the remaining programme is explicit and countable rather than estimated.
- Eight role experiences can be delivered and reviewed independently (UI-08 … UI-15).
- Any attempt to satisfy the count with placeholders fails the `implemented` definition.

## Rejected alternatives

- **One shared navigation registry filtered by permission.** Rejected: produces one information
  architecture for eight different products, which plan §0 prohibits.
- **Treating the existing screen inventory as the 160-page contract.** Rejected: it records what is
  built; adopting it as the requirement would discharge the requirement by definition.
- **Header navigation for every account.** Rejected: contradicts the product-owner directive and
  does not scale to the Finance and Merchant Administrator page counts.

## Future implementation owner phase

**UI-07** owns the machine-readable navigation contract, the full screen-specification set, and
runtime router/registry parity. **UI-08 … UI-15** own the per-account experiences. UI-00 owns only
the source registration, the parser, and the count/identity guards.

## Required tests

- Per-account count assertions (22/23/18/19/24/19/20/15) and a total of 160 — `UiSourceContractTest`.
- Appendix A ↔ §30 register identity parity — `UiSourceContractTest`.
- No duplicate section identifier; no duplicate account/route pair.
- Generator determinism (`--check` produces no diff).
- Placement tests: Super Administrator header shell; left navigation for the other seven.
- (UI-07) router ↔ registry ↔ inventory ↔ permission-matrix parity.

## Traceability links

`SRV-UI-NAV-001`, `SRV-UI-NAV-002` in `docs/traceability/servana-requirements.csv`;
`docs/frontend/navigation/servana-user-account-navigation-maps.md`;
`docs/frontend/source-inventory/navigation-map.json`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Governed by ADR-017. Related to ADR-016 and ADR-024.
