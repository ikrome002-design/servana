# Platform Billing Settings — Effective-Dated Versioning (Plan §13.9, §47; Phase 20A)

> `platform_billing_settings` has no status column; it is an **append-only,
> effective-dated version series**. There is exactly **one current version** at any
> instant — the row with the greatest `effective_from <= now()`. An update never
> mutates a prior version; it **inserts a new version**. Actor: **Super
> Administrator** (`platform.billing_settings.update`; `platform_mutation`; mandatory
> MFA + step-up). Read: `platform.billing_settings.view` (MFA). Money is integer
> minor units; currency uppercase ISO; effective logic `Africa/Nairobi`.

## Version lifecycle (derived from effective_from, not stored)

```text
superseded   an older version (a newer effective_from exists)      immutable, retained
current      greatest effective_from <= now()                      the version the platform reads
future       effective_from > now() (optional scheduling)          becomes current at its instant
```

## update settings — insert a new current/future version
```text
actor: Super Admin | permission: platform.billing_settings.update | class: platform_mutation | MFA: mandatory | step_up: required
input_validation: billing_mode ∈ BillingMode(3); default_trial_days(>=0); grace_days(>=0); currency(upper ISO); settings jsonb OBJECT with documented keys only; effective_from(timestamptz). Undocumented settings keys rejected.
transaction_boundary: single transaction | rows_locked: advisory lock serializing version creation
preconditions: no existing version at the same effective_from instant (UNIQUE(effective_from))
writes: one new platform_billing_settings row (updated_by=actor); prior versions UNCHANGED
audit_event: platform_billing.settings_changed (high; before/after mode/trial/grace/currency, effective_from)
failure_codes: 409 duplicate_effective_instant, 422 validation (incl. undocumented settings key), 403
tests: current resolves to greatest effective_from<=now; new version leaves history intact; canonical mode/currency; negative trial/grace rejected; undocumented settings key rejected; non-Super-Admin denied; MFA + step-up enforced
```

## read current — resolve the effective version
```text
actor: Super Admin | permission: platform.billing_settings.view | MFA: mandatory
resolution: SELECT ... ORDER BY effective_from DESC WHERE effective_from <= now() LIMIT 1
seed default: launch version billing_mode=fixed_amount (§50), currency=KES, documented trial/grace defaults
tests: default launch version is fixed_amount; view requires MFA; non-platform roles denied
```

## Notes
- No `PATCH`; the only mutation is a new-version insert.
- Financial primitives (mode/trial/grace/currency) are first-class columns — never
  hidden in `settings` jsonb; only documented keys are accepted (validation +
  DB object CHECK).
- Default launch mode is `fixed_amount` (§50) unless an authoritative later version
  configures otherwise.
- Positive/negative/authorization/MFA/step-up/audit tests in `tests/Feature/Billing`.
