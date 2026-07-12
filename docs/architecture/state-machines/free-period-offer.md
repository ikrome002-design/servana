# Free-Period Offer — Status Machine (Plan §53; Phase 20C)

> `free_period_offers.status` is the lifecycle of one platform-governed free-period (trial-length)
> offer. It is **never** assigned directly by a controller; changes run through named actions via
> `FreePeriodOfferStateMachine`; an unlisted pair returns `422 invalid_state_transition`. There is
> **no** generic status endpoint. Only an `active` record whose effective window contains the trial
> anchor instant (Gate C3) is eligible for resolution (`ResolveFreePeriodOffer`). Approval, MFA and
> fresh step-up are enforced at the action/route layer (Super-Administrator only; Gate C6). Successful
> transitions emit one typed high-severity audit event; failed/rolled-back transitions emit none.

## States (mirror the DB CHECK)

```text
draft      editable configuration + targets; not yet approved; not resolvable
scheduled  approved, awaiting activation; not yet resolvable
active     approved + effective window current; resolvable
paused     approved but temporarily withheld from resolution (availability only)
expired    terminal — effective window ended
cancelled  terminal — withdrawn before taking effect / while scheduled
```

## Transition inventory

```text
draft      → scheduled    ApproveFreePeriodOffer   (approval always yields scheduled)
draft      → cancelled    CancelFreePeriodOffer    (withdraw an unapproved draft)
scheduled  → active       ProcessPromotionLifecycle activation (effective window current)
scheduled  → cancelled    CancelFreePeriodOffer    (withdraw before it takes effect)
active     → paused        PauseFreePeriodOffer    (availability only; terms unchanged)
active     → expired       ProcessPromotionLifecycle (effective_to reached)
paused     → active        ResumeFreePeriodOffer   (rejected after effective_to)
```

**No direct `draft → active`** — unlike the promotional-discount machine, approval of a free-period
offer always lands in `scheduled`; a same-day-effective offer becomes `active` via the lifecycle
activation transition (§12). Any other pair → `422 invalid_state_transition`. `expired` and
`cancelled` are **terminal**; a change requires a **new** offer, never a rewrite of approved terms.

## Approval + immutability (Gate C6)

- **Drafts** are fully editable (`UpdateFreePeriodOfferDraft`); **targets** may be added/removed only
  while `draft`.
- **Approval** (`ApproveFreePeriodOffer`) records `approved_by` + `approved_at`, requires
  Super-Administrator + MFA + **fresh step-up**, and moves `draft → scheduled`.
- Once approved, `free_period_days`, `effective_from`, `effective_to`, `target_scope` and the target
  rows are immutable (supersede with a new record). **No** separate maker/checker role.
- **Pause/resume** change availability only. **Resume** is rejected once `effective_to` has passed.
- A **sanitized, mandatory `change_reason`** accompanies every state-changing action.

## Lifecycle scheduler (`ProcessPromotionLifecycle`)

Shared scheduler with promotions (Nairobi business time §67; bounded, row-locked, idempotent,
`withoutOverlapping()->onOneServer()`). Activates due `scheduled` offers (`scheduled → active`) and
expires due `active` offers (`active → expired`); one audit event per real transition; never edits an
existing trial snapshot.

---

### ApproveFreePeriodOffer — draft → scheduled
```text
actor: Super Administrator | platform_mutation | MFA + fresh step-up | high severity
writes: status = scheduled; approved_by; approved_at
audit: free_period_offer.approved
guard: draft only; days + window + targets frozen thereafter; reason mandatory
```

### CancelFreePeriodOffer — draft | scheduled → cancelled
```text
writes: status = cancelled
audit: free_period_offer.cancelled
guard: only from draft or scheduled; reason mandatory
```

### PauseFreePeriodOffer — active → paused
```text
writes: status = paused (excluded from resolution)
audit: free_period_offer.paused
guard: active only; reason mandatory
```

### ResumeFreePeriodOffer — paused → active
```text
writes: status = active
audit: free_period_offer.resumed
guard: paused only; rejected if effective_to has passed; reason mandatory
```

### ProcessPromotionLifecycle — scheduled → active, active → expired
```text
actor: scheduler | idempotent | row-locked
writes: status = active (activation) | expired (window ended)
audit: free_period_offer.activated | free_period_offer.expired (one per real transition)
```

## Notes
- Resolution (`ResolveFreePeriodOffer`) considers only `active` + in-window records anchored at the
  Merchant-Administrator creation instant (Gate C3); other states never match (tested).
- A resolved free-period offer sets the new subscription's `trial_days_snapshot` once; later edits,
  pause, cancellation or expiry **never** change an existing trial (tested).
- State/timestamp coherence is DB-enforced (CHECKs; see data dictionary).
- Positive / invalid-transition / authorization / audit tests live in `tests/Feature/Billing`.
