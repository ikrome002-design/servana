# Phase UI-07 audit artifacts

Everything here is **derived**. Nothing in this directory is hand-maintained except
`defect-closure.json`, which records judgement rather than measurement.

The one handwritten authority is:

```text
docs/frontend/navigation/servana-user-account-navigation-map.yaml
```

Regenerate and verify:

```bash
npm run nav:generate
```

```bash
npm run nav:check
```

```bash
npm run nav:negative-controls
```

CI runs `nav:check` and `nav:negative-controls`, so a stale artifact — or a guard that quietly
stopped rejecting the defect it exists to catch — fails the Frontend job.

## Two registers, reconciled and never summed

| Register | Authority | Answers |
|---|---|---|
| Contract | `servana-user-account-navigation-map.yaml` (160 pages) | what is **required** |
| Runtime | `docs/frontend/screens/inventory.json` (122 rows) | what is **built** |

UI-01 established the separation and merged it. A number from one register is never added to a
number from the other.

## The files

| File | What it records |
|---|---|
| `source-authority.json` | Which file is the authority, what is generated from it, and why every other candidate was rejected. |
| `page-count-matrix.json` | The §7.5 count, per account and summed. The total is derived, never written. |
| `account-page-matrix.json` | Every contract page, per account, in navigation order. |
| `status-matrix.json` | `implemented` / `planned` / `disabled_by_gate` / `removed_by_authority`, what each means at runtime, and every gate-blocked page. |
| `owner-phase-matrix.json` | One UI owner per page; backend ownership read from the screen inventory, never inferred from UI numbering; each page's remaining dependency. |
| `screen-spec-matrix.json` | One specification per page — no missing, no orphan, none shared. |
| `inventory-parity.json` | Contract against runtime register, with the explicit predicate that keeps public and excluded surfaces out of the 160. |
| `route-parity.json` | The contract against the **actual** Vue Router records, loaded through the real modules. Never parsed from source text — that is how an artifact ends up describing something the code does not do. |
| `navigation-parity.json` | The fixed filter order, what may be rendered, and what never is. |
| `permission-parity.json` | Every referenced key exists in the canonical matrix; UI-07 adds none. |
| `requires-account-coverage.json` | Every authenticated account tree declares and enforces its account; every route outside a tree carries a reason. |
| `code-splitting-matrix.json` | Every route lazily loaded; no page in the initial bundle; no runtime chunk for a planned page. |
| `browser-proof.json` | What a browser actually did with the eight shells and the account guard. Written by `tests/e2e/ui-07-navigation-screen-contracts.spec.ts`. |
| `negative-control-results.json` | Proof that each contract guard rejects the defect it exists to catch. Every mutation is applied to a disposable copy; control 0 proves the unmodified copy passes, so a later result cannot be vacuous. |
| `defect-closure.json` | Defects closed, predecessor closures promoted, and what was deliberately left to a named owner. |

## Screenshots

There are none, deliberately. UI-07 implements no account page and the navigation **markup** is
unchanged — only the entries it receives. Screenshots would picture UI-04's shell, not this
phase's work, and would not be release-approved baselines. Approved visual baselines belong to
**UI-16**.
