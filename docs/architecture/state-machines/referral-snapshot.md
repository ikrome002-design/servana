# Referral Snapshot — State Machine (Plan §25.6, §58A.1, §13.17; ADR-013; Phase 21R-A)

> Named mandatory state-machine specification (Plan §25.1). A `referral_snapshots` row records that a
> merchant registered with a Citrus R&E referral code, and tracks that claim's fate. Servana is a
> **source product**: it never decides whether a referrer earns anything — R&E owns attribution and
> reward truth. This machine records what Servana observed and what R&E answered.

Aggregate: `referral_snapshots` (one row per merchant, ever). Transitions run through
`App\Domain\Integrations\ReferEarn\Actions\TransitionReferralSnapshot`; nothing else may write
`snapshot_status`.

## States (mirror the DB CHECK)

```text
captured             code accepted at registration and structurally valid; nothing sent to R&E yet
invalid_format       code was malformed; NEVER sent to R&E (terminal)
validating           validation in flight with R&E; transient failures stay here and retry
validated            R&E confirmed the code is a real, usable referral code
rejected             R&E rejected the code or the attribution (invalid/expired/ineligible/conflict) (terminal)
confirmed            R&E confirmed the attribution; re_attribution_public_id stored (terminal)
expired_unconfirmed  the R&E confirm window lapsed without confirmation (terminal; audited)
```

## Transitions (mirror `ReferralSnapshotStatus::canTransitionTo()`)

```text
captured    -> validating | invalid_format
validating  -> validated | rejected
validated   -> confirmed | expired_unconfirmed
confirmed              -> (terminal)
rejected               -> (terminal)
invalid_format         -> (terminal)
expired_unconfirmed    -> (terminal)
```

**No regression, ever.** There is no path back to an earlier state and no self-transition: a
`validating` snapshot whose validation call times out simply **stays** `validating` and is retried
with backoff, so a retry is never a state change. The database trigger `referral_snapshots_guard`
rejects any status change out of a terminal state, and independently freezes the capture evidence
(`merchant_id`, `ulid`, `raw_code_encrypted`, `code_normalized`, `capture_channel`, `captured_at`,
`landing_metadata`) at **every** status.

Invalid transitions raise `ReferralSnapshotStateException` → HTTP 422 `invalid_state_transition`
where a route is involved (Phase 21R-A exposes none).

## Per-transition contract

| Transition | Trigger | Guards | Side effects |
|---|---|---|---|
| → `captured` (insert) | `CaptureReferralSnapshot` inside the self-registration transaction | structurally valid normalized code; no existing snapshot for the merchant (unique `merchant_id`) | encrypted raw code + normalized code + channel + allowlisted landing metadata persisted; `re.referral_captured` audit; `ValidateReferralCodeJob` queued **after commit** |
| → `invalid_format` (insert) | same action, malformed submission | `code_normalized` must be NULL (DB CHECK) | snapshot stored as evidence; `re.referral_captured` audit records the malformed outcome; **nothing is queued and nothing is ever sent to R&E** |
| `captured` → `validating` | `ValidateReferralCode` starting a validation attempt | status is `captured` | `last_transition_at` set |
| `validating` → `validated` | R&E answered "valid" | status is `validating` | `re_validation_result_code` stored verbatim; `ConfirmAttributionJob` queued |
| `validating` → `rejected` | R&E answered "invalid/expired/ineligible" | status is `validating` | result code stored; `re.attribution_rejected` audit; **no further events are emitted for this merchant** |
| `validating` → `validating` | transient failure (5xx/timeout/rate limit) | — | **not a transition**; the job retries with backoff |
| `validated` → `confirmed` | `ConfirmAttribution` succeeded | status is `validated` | `re_attribution_public_id` + `confirmed_at` stored (CHECK ties them to the status); `re.attribution_confirmed` audit |
| `validated` → `rejected` | R&E rejected the attribution (e.g. another referrer already effective — Plan §58B.5 R-04) | status is `validated` | result code stored; `re.attribution_rejected` audit |
| `validated` → `expired_unconfirmed` | confirm window lapsed (`REFER_EARN_CONFIRM_WINDOW_HOURS`, default 168 h) | status is `validated`; window elapsed since `captured_at` | audited; snapshot retained as evidence |

> `validated → rejected` is included because R&E's confirm call is where an attribution **conflict**
> surfaces (Plan §58B.5 R-04), and a conflict is a rejection of the claim, not of the code. It is a
> forward move to a terminal state, so it violates neither the no-regression rule nor the trigger.

## Emission-scope consequence (Plan §58B.1)

`ReferralSnapshotStatus::permitsEventEmission()` is the data-minimization boundary:

| Status | Emits `merchant.*` to R&E? |
|---|---|
| no snapshot at all (unreferred merchant) | **no** |
| `invalid_format` | **no** — the code never reached R&E, so R&E has no claim to reason about |
| `rejected` | **no** — the claim is settled against the referrer |
| `captured`, `validating`, `validated`, `confirmed`, `expired_unconfirmed` | yes |

R&E has no business need for facts about merchants it has no live claim on, and Servana must not
stream its merchant lifecycle to a partner system beyond that need.

## Audit (Plan §70)

`re.referral_captured` (info) · `re.attribution_confirmed` (info) · `re.attribution_rejected` (info).
Audit context carries the snapshot ULID, merchant ULID, channel and status — **never** the decrypted
referral code (Plan §24.5) and never any referrer identity (Servana does not have one).

## Tests

`tests/Feature/Integrations/ReferEarn/ReferralCaptureTest.php` (capture paths, R-01/02/03) and
`tests/Feature/Integrations/ReferEarn/AttributionLifecycleTest.php` (validate → confirm, R-04
rejection, expiry, non-regression trigger, no-referrer-PII schema assertion).
