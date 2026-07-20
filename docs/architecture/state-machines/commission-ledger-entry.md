# Commission Ledger Entry — State Machine (Plan §61, §13.12; Phase 20G)

> Named mandatory state-machine specification (Plan §25.1). `commission_ledger` is an
> **append-only financial fact**: monetary/snapshot columns are immutable (DB trigger blocks their
> `UPDATE` and blocks all `DELETE`); only the documented `status` and the Phase 20H `payout_item_id`
> link transition, through named actions via `CommissionLedgerStateMachine`. No generic `PATCH`
> route. Money is integer minor units; timestamps UTC; business-event dates `Africa/Nairobi`;
> rounding ADR-005 (round-half-up + largest-remainder residual).

Aggregate: `commission_ledger` (branch-owned `merchant_id` + `branch_id`). Reversal/adjustment
amounts are **additive** rows, never edits of the original `earned` row (Plan §61).

## Entry types (mirror the DB CHECK)

```text
pending_preview  canonical value only — Phase 16C computes non-payable previews on the fly; 20G persists none
earned           original commission fact, created ONLY at Finance payment validation
reversal         additive NEGATIVE fact (exact negative of the original) from invoice void / refund / payment reversal
adjustment       canonical value; monetary corrections to already-paid history are compensation_adjustments rows
```

## States (mirror the DB CHECK) — apply to the `earned` original row

```text
pending             reserved (not used at launch; earned rows are created settled as `earned`)
earned              earned + available for a future Phase 20H payout
included_in_payout  linked to a personnel_payout_item (Phase 20H)
paid                the payout run was marked paid (Phase 20H)
reversed            a reversal row has offset this entry (non-monetary lifecycle marker)
adjusted            an adjustment offset this entry (non-monetary lifecycle marker)
cancelled           reserved (e.g. an earned row voided before any payout — not used at launch)
```

`reversal` rows are created `earned` (a realized negative available to net into a payout).

## Transition inventory (authoritative arrow set)

```text
(none)              → earned              EarnCommissionForValidationEvent (consumes the handoff outbox)
earned              → included_in_payout  Phase 20H (payout run build) — NOT Phase 20G
included_in_payout  → paid               Phase 20H (mark-paid) — NOT Phase 20G
earned              → reversed            ReverseCommissionEntry (not-yet-paid original)
(none)              → earned (reversal)   ReverseCommissionEntry writes the additive negative row
earned              → adjusted            (reserved) additive adjustment marker
```

No unlisted transition. No `UPDATE` of `amount_minor`/`calculation_basis_minor`/`rate_basis_points`/
`fixed_rate_minor`/snapshots/currency; no `DELETE`. Any attempt → DB trigger error /
`422 invalid_state_transition`.

---

### earn — (none) → earned  (`EarnCommissionForValidationEvent`)
```text
driver: successful Finance payment validation (P18B) via the durable commission_handoff_events outbox
input: validation event + its invoice's items (server-derived); effective plan/rule per item's staff on the business-event date
computation: eligible items only (item.eligible_for_commission AND — for selected_services rules — item.service_id ∈ commission_rule_services); basis per the shipped CommissionCalculationBasis (service_price|invoice_item_total|paid_amount|net_after_discount); paid_amount = largest-remainder split of the validation event's validated amount across eligible items; percentage = round_half_up(basis_minor * bps / 10000); fixed = min(fixed_rate_minor, item eligible validated allocation); preferred fee included iff rule.applies_to_preferred_personnel_fee
idempotency: DB UNIQUE (payment_validation_event_id, invoice_item_id, staff_profile_id, entry_type='earned') — replay/concurrency create no duplicate; the handoff `consumed_at` marks completion
writes: one earned row per eligible (validation event, item, staff): entry_type=earned, status=earned, earned_at=now(), snapshots (plan/rule/basis/rate/currency/source ids)
guarantee: salary_only plans and ineligible/zero-basis items create no row; a non-draft selected_services rule with no membership substrate fails closed (typed error), never falls back to all_services
audit_event: commission.earned (info; safe ULIDs, basis/rate/amount snapshot)
failure_codes: fail-closed on missing effective plan/rule/membership; over-allocation impossible (Σ ≤ validated allocation)
tests: recording-only payment earns nothing; session completion earns nothing; finalization earns nothing; validation earns; duplicate validation idempotent; salary_only excluded; selected_services membership gates eligibility; largest-remainder allocation; cross-tenant rejected; rollback ⇒ no row + no audit
```

### reverse — earned → reversed (+ additive negative row)  (`ReverseCommissionEntry`)
```text
driver: refund finalization via the handoff reversal outbox (Increment 4; product-owner resolution 2026-07-18). The consumer reverses earned rows ONLY when the cumulative finalized refunds for the validation event's recording group equal the whole validated allocation (exact-negative, never a fraction). A PARTIAL refund (cumulative < validated) is a valid no-effect event. INVOICE VOID does NOT invalidate the validated allocation (validated_paid_minor + payment records untouched) ⇒ it produces NO commission reversal; the refund seam is the authoritative reversal path. There is no canonical post-validation PAYMENT-REVERSAL event distinct from refund; pre-validation reject/correction earn nothing.
precondition: the original earned row is NOT yet paid (status earned|included_in_payout); an ALREADY-PAID original goes to compensation_adjustments (paid_commission_reversal) instead — paid history never rewritten
writes: NEW entry_type=reversal row, amount_minor = EXACT NEGATIVE of the original stored amount (never recomputed), source_entry_id → original, reversal_reason ∈ (invoice_voided|payment_reversed|refund_finalized); original row status → reversed; original monetary fields UNCHANGED
idempotency: DB UNIQUE (source_entry_id) WHERE entry_type='reversal' — one reversal per original. Cumulative finalized refunds > validated allocation ⇒ fail CLOSED (never over-reverse).
audit_event: commission.reversed (warning; source cause, original ULID → reversal ULID, reason)
failure_codes: period-lock block (423) where the correction period is locked; 403; commission_original_not_yet_earned + commission_cumulative_reversal_exceeds_allocation (retryable / fail-closed, consumer)
tests: full refund → exact-negative reversal; partial refund → no reversal (no fraction); completing refund → reverse once; invoice void → NO reversal; pre-validation reject/correction → nothing; exact negative; original unchanged; second reversal idempotent (DB unique); cumulative over-refund → fail closed; causal deferral when earning unconsumed; already-paid → adjustment not reversal; rollback ⇒ no success audit
```

## Notes
- The append-only DB trigger is the authoritative immutability guard (blocks monetary/snapshot
  `UPDATE` and all `DELETE`); the state machine + actions produce friendly errors and manage `status`
  + the Phase 20H `payout_item_id` link.
- `payout_item_id` is nullable + UN-CONSTRAINED until Phase 20H adds its FK (ADR-004 expand).
- Every transition has positive, invalid-transition, immutability, idempotency, concurrency,
  isolation, period-lock, and audit tests (`tests/Feature/Compensation`). Rolled-back actions write no
  success audit.
