# Requirement traceability — controlled vocabulary and enforcement

`servana-requirements.csv` is the Plan §85 requirement-traceability matrix. Since Phase 23 it is a
**checked contract**, not a document: every rule below is enforced by
[`tests/Feature/Traceability/Phase23TraceabilityTest.php`](../../tests/Feature/Traceability/Phase23TraceabilityTest.php),
which CI invokes. Editing the CSV without satisfying the guard fails the build.

## 1. Columns (all 15 required, in this order)

```text
scope_section, requirement_id, description, phase, db_objects, service_or_action,
controller_or_endpoint, policy_and_permission, frontend_route_and_component,
queue_or_scheduler, audit_event, automated_tests, manual_verification, status, evidence
```

Every cell must carry something. Use an explicit `n/a` (with the reason evident from the
surrounding cells) where a column genuinely does not apply — never a blank.

## 2. Status vocabulary (closed — seven values)

`status` is a **single token from this list**. Nothing else is accepted.

| Value | Means | Requires |
|---|---|---|
| `verified_complete` | The owning phase is merged with green CI and phase-completion evidence. | The phase must appear in the guard's verified-phase list. |
| `local_complete` | Implemented and green locally; the owning phase's PR is not merged. | Names the branch/state in `evidence`. |
| `implemented` | Code is present but the owning phase has produced no completion evidence yet. | Used for the phase currently in flight. |
| `architecture_adopted` | Only the architecture/contract is adopted; no runtime exists by design. | The adoption PR in `evidence` — or, before that PR merges, the adoption **branch** and the fact that no PR exists yet. |
| `blocked_external_gate` | Deliberately absent behind a **named** external gate. | Owning phase ∈ {20D-W, 21R-B, 21N}; names the gate; names an **absence/non-regression test**. |
| `deferred_future_phase` | Deliberately deferred to a **named** later phase. | Owning phase ∈ `P23_DEFERRABLE_PHASES` — currently {21N, 25, UI-01 … UI-17}. |
| `not_applicable` | Genuinely not applicable. | Reason in `evidence`. |

**Rejected outright:**

- `not_implemented` and `partially_implemented` — they say nothing about ownership. A requirement is
  delivered, locally complete, in flight, deliberately blocked behind a named gate, deliberately
  deferred to a named phase, or not applicable. (`SRV-AUDIT-004` sat at `not_implemented` for two
  phases while the work had actually shipped in Phase 19.)
- Any prose, parenthetical, or multi-line narrative in `status`. Evidence detail belongs in
  `evidence`. (`SRV-PAYMENT-001/002` each carried a full CI history inside the status cell.)
- Any value not in the table above.

## 3. Truthfulness rules the guard enforces

- **No stale completion.** `verified_complete` is impossible for a phase that is not itself verified
  complete — this is what caught the Phase 19, 20F and 20G rows.
- **Phase 23 can never be `verified_complete`** before its PR merges and CI/governance verification.
- **Blocked means blocked.** A `blocked_external_gate` row must name Gate W (or §80.1/§80.2) and must
  still name a real absence test. The guard additionally asserts Gate W is *currently* closed
  (`docs/integrations/wallet/` and `docs/proof/phase-20d-w.md` absent), so the rows cannot go stale
  if the gate opens.
- **Every referenced suite must exist.** Suite-shaped entries in `automated_tests` are resolved
  against the filesystem. Prose is allowed only where there is genuinely no suite to name yet
  (deferred/blocked rows), and a blocked row still needs its absence test.
- **Claims must map to reality.** Any `/api/v1/...` path a delivered row claims must exist in the live
  route table, and any route name it claims must exist in the screen inventory.
- **Gate-blocked work must stay modelled.** The guard requires all three blocked phases (20D-W,
  21R-B, 21N) to be represented, so deliberately-absent work can never quietly disappear from the
  matrix.

## 3A. Phase vocabulary

`phase` must name a phase the guard knows: `P23_VERIFIED_PHASES` (merged and verified) or
`P23_UNVERIFIED_PHASES` (exists, not verified). `P23_IN_FLIGHT_PHASE` names the phase whose branch
is open; its rows may never claim `verified_complete`. Advance that constant when the in-flight
phase merges and the next phase's branch reconciles it — the convention that promoted Phase 23 after
PR #48 (`13f54a4`) and Phase 24 after PR #49 (`db3827b`).

Since Phase UI-00 the matrix also models the **corrective UI programme** (`UI-00` … `UI-17`, from
`Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md` §25). UI phases are sequenced
independently of the backend roadmap, and a UI requirement deferred to a later UI phase names that
phase exactly, so it can never become an unowned promise.

## 4. Screen inventory

The companion guard is
[`resources/spa/src/screens/screenInventory.spec.ts`](../../resources/spa/src/screens/screenInventory.spec.ts)
(Vitest, run by the CI Frontend job). Beyond route/spec coverage it enforces:

- no orphan spec file — every generated spec is owned by an inventory entry;
- a `planned` screen may not be owned by a **verified-complete** phase unless it is a
  **registered release gap** (see `REGISTERED_RELEASE_GAPS`, each keyed to a `REM-*` item);
- the registered-gap list is exact — when the owning phase delivers the screen, the guard fails and
  forces the entry's removal;
- every other `planned` screen names a phase that genuinely has not shipped;
- a route-less live screen is allowed only for the declared access-state boundaries
  (`unsupported-role`, `no-branch-assignment`).

`inventory.json` is the source of truth. `inventory.yaml` is a Vitest file snapshot of it and
`docs/frontend/screens/**/*.md` are generated by `node scripts/generate-screen-specs.mjs` — never
hand-edit either.

## 5. Changing the CSV

1. Edit the row(s) with live evidence — routes, policies, tests, proof files, merge commits.
2. Put narrative in `evidence`, never in `status`.
3. Run the guards:

```bash
docker compose exec -T app php artisan test tests/Feature/Traceability/
```

```bash
npx vitest run resources/spa/src/screens/screenInventory.spec.ts
```
