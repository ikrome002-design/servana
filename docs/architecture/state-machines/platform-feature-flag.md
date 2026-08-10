# State machine — Platform feature flag

**Table:** `platform_feature_flags` · **Column:** `state` · **Enum:**
`App\Domain\PlatformFeatureFlags\Enums\PlatformFeatureFlagState` · **Guard:**
`App\Domain\PlatformFeatureFlags\Services\PlatformFeatureFlagStateMachine` · **DB backstop:**
`platform_feature_flags_state_check`, `platform_feature_flags_scheduled_check`,
`platform_feature_flags_dating_check`, `platform_feature_flags_rollout_check`,
`UNIQUE (flag_key, environment)`

Plan §9 rule 7 (backed enums + DB CHECKs), §18, §24.1, §70; ADR-008;
[`COR-UI08-001`](../../decisions/cor-ui08-001-super-administrator-backend-enablement.md) §12;
Phase UI-08.

## States

| State | Meaning |
|---|---|
| `inactive` | Known to the catalogue, never switched on in this environment. The default for a newly registered key. |
| `scheduled` | An applied change set a future `effective_from`. The flag is **not** on yet. |
| `active` | `effective_from <= now()` and (`effective_to` is null or in the future). Subject to targets and rollout. |
| `paused` | Emergency stop. Evaluates exactly like `inactive` — deny — while preserving the configuration for resumption. |
| `retired` | Permanently withdrawn. **Terminal.** Evaluates deny forever. |

## Transitions

```
inactive ─┬─► scheduled ─► active ─┬─► paused ─┬─► active   (resume, via a new change request)
          │                        │           └─► retired  (terminal)
          └─► active               ├─► inactive (change request setting it off)
                                   └─► retired  (terminal)
```

| From | To | Driver |
|---|---|---|
| `inactive` | `scheduled` / `active` | an **applied** change request |
| `scheduled` | `active` | the clock, once `effective_from` arrives — no write, no actor |
| `active` | `inactive` | an applied change request |
| `active` / `scheduled` | `paused` | `PausePlatformFeatureFlag` — the **only** single-actor path (see below) |
| `paused` | `active` / `inactive` | an applied change request |
| any | `retired` | an applied change request. Terminal |

Every transition except the clock and the emergency pause requires an **approved** change request
(`platform-feature-flag-change-request.md`). There is no unaudited instant-enable path anywhere.

## The emergency pause is the single deliberate exception — and it can only restrict

`PausePlatformFeatureFlag` needs no second approver because it moves a flag **towards deny** and
never away from it. It still requires `platform.settings.update`, MFA, a fresh
`platform_feature_flag_change` step-up and a mandatory reason, and it writes a `paused` history row
like every other transition. Turning a flag back on always goes through maker/checker.

## Evaluation order (fail closed)

`PlatformFeatureFlagEvaluator::allows()` — the single server-side authority — evaluates in this
order and denies at the first failure:

```text
1. code allowlist        unknown key -> deny (config/platform-feature-flags.php)
2. environment           no row for this environment -> deny
3. external gate         the definition's external_gate is closed -> deny
4. flag state            state not active -> deny
5. effective dates       outside [effective_from, effective_to) -> deny
6. target                targets exist and the subject matches none -> deny
7. rollout               deterministic bucket >= rollout_basis_points -> deny
```

**A flag may turn an otherwise-authorized capability off. It may never turn an unauthorized
capability on.** Permission, entitlement, billing state and account context are evaluated
independently and are never consulted by, or replaced by, this evaluator.
`Ui08FeatureFlagEvaluationTest` proves each of the four non-bypass properties separately.

## External Gate W is not a feature flag

A definition may declare `external_gate: 'W'`, which the evaluator reads as an additional **deny**
condition. Nothing in this surface can open a gate: there is no API, no column and no state that
sets a gate to open. Gate W remains a separate, evidence-based launch gate, and a flag can never
make Wallet or Refer & Earn functionality available while it is closed.
`Ui08FeatureFlagEvaluationTest` asserts that an `active`, fully rolled-out, correctly targeted flag
still evaluates deny behind a closed gate.

## Rollout determinism

`rollout_basis_points` is an integer 0–10000 — never a float percentage. The bucket is
`crc32(flag_key . ':' . subject_ulid) % 10000`, so the same subject always lands in the same bucket
for the same flag, a rollout only ever widens, and there is no randomness to reproduce.

## Never evaluated in the browser

The evaluator has no client counterpart. The frontend receives only the resulting capability
booleans for the current user, as UX hints, and every protected request re-evaluates server-side.
