# Promotional Discount — Status Machine (Plan §53; Phase 20C)

> `promotional_discounts.status` is the lifecycle of one platform-governed promotion. It is **never**
> assigned directly by a controller; changes run through named actions via
> `PromotionalDiscountStateMachine`; an unlisted pair returns `422 invalid_state_transition`. There is
> **no** generic status endpoint. Only an `active` record whose effective window contains the business
> instant is eligible for resolution (`ResolvePromotionalDiscount`). Approval, MFA and fresh step-up
> are enforced at the action/route layer (Super-Administrator only; Gate C6). Successful transitions
> emit one typed high-severity audit event; failed/rolled-back transitions emit none.

## States (mirror the DB CHECK)

```text
draft      editable configuration + targets; not yet approved; not resolvable
scheduled  approved, effective_from in the future; not yet resolvable
active     approved + effective window current; resolvable
paused     approved but temporarily withheld from resolution (availability only)
expired    terminal — effective window ended
cancelled  terminal — withdrawn before taking effect / while scheduled
```

## Transition inventory

```text
draft      → scheduled    ApprovePromotionalDiscount (approved; effective_from in the future)
draft      → active       ApprovePromotionalDiscount (approved; effective window already current)
draft      → cancelled    CancelPromotionalDiscount  (withdraw an unapproved draft)
scheduled  → active       (ProcessPromotionLifecycle activation, or ApprovePromotionalDiscount when current)
scheduled  → cancelled    CancelPromotionalDiscount  (withdraw before it takes effect)
active     → paused       PausePromotionalDiscount   (availability only; terms unchanged)
active     → expired      ProcessPromotionLifecycle  (effective_to reached)
paused     → active       ResumePromotionalDiscount  (rejected after effective_to)
```

Any other pair → `422 invalid_state_transition`. `expired` and `cancelled` are **terminal**. There is
no `paused → cancelled`, no `active → cancelled`, and no resurrection of a terminal record — a change
requires a **new** promotion, never a rewrite of approved terms.

## Approval + immutability (Gate C6)

- **Drafts** are fully editable (`UpdatePromotionalDiscountDraft`); **targets** may be added/removed
  only while `draft`.
- **Approval** (`ApprovePromotionalDiscount`) records `approved_by` + `approved_at`, requires
  Super-Administrator + MFA + **fresh step-up**, and moves `draft → scheduled` (future start) or
  `draft → active` (window already current).
- Once approved, the **financial terms** (`type`, `value`, `currency`, `effective_from`,
  `effective_to`, `target_scope`) and the **target rows** are immutable. Reuse the Phase 20A
  platform-configuration approval pattern — **no** separate maker/checker role.
- **Pause/resume** change availability only, never terms. **Resume** is rejected once `effective_to`
  has passed (the lifecycle expires it instead).
- A **sanitized, mandatory `change_reason`** accompanies every state-changing action.

## Lifecycle scheduler (`ProcessPromotionLifecycle`)

Nairobi business time (§67); bounded scan; row-locked; idempotent. Activates due `scheduled` records
(`scheduled → active`) and expires due `active` records (`active → expired`); one audit event per real
transition; never edits snapshots; `withoutOverlapping()->onOneServer()`.

---

### ApprovePromotionalDiscount — draft → scheduled | active
```text
actor: Super Administrator | platform_mutation | MFA + fresh step-up | high severity
writes: status = scheduled (effective_from > today) | active (window current); approved_by; approved_at
audit: promotion.approved (+ promotion.activated when it goes straight to active)
guard: draft only; terms + targets frozen thereafter; reason mandatory
```

### CancelPromotionalDiscount — draft | scheduled → cancelled
```text
writes: status = cancelled
audit: promotion.cancelled
guard: only from draft or scheduled (never from active/paused/terminal); reason mandatory
```

### PausePromotionalDiscount — active → paused
```text
writes: status = paused (excluded from resolution)
audit: promotion.paused
guard: active only; terms unchanged; reason mandatory
```

### ResumePromotionalDiscount — paused → active
```text
writes: status = active
audit: promotion.resumed
guard: paused only; rejected if effective_to has passed; reason mandatory
```

### ProcessPromotionLifecycle — scheduled → active, active → expired
```text
actor: scheduler | idempotent | row-locked
writes: status = active (activation) | expired (window ended)
audit: promotion.activated | promotion.expired (one per real transition)
```

## Notes
- Resolution (`ResolvePromotionalDiscount`) considers only `active` + in-window records; `draft`,
  `scheduled`, `paused`, `expired`, `cancelled` never match (tested).
- State/timestamp coherence (`approved_by`/`approved_at` set for `scheduled`/`active`/`paused`/
  `expired`) is DB-enforced (CHECKs; see data dictionary).
- Positive / invalid-transition / authorization (MFA + step-up) / audit tests live in
  `tests/Feature/Billing` (Phase 20C).
