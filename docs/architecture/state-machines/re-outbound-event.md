# R&E Outbound Event — State Machine (Plan §25.6, §58A.2, §13.17; §9 rule 22; ADR-013/015; Phase 21R-A)

> Named mandatory state-machine specification (Plan §25.1). A `re_outbound_events` row is one fact
> Servana owes Citrus R&E. It is inserted in the **same database transaction** as the source domain
> change, so the fact and its event commit or roll back together, and it is delivered by
> `DeliverReOutboxJob` with a signature over canonical bytes that never change.

Aggregate: `re_outbound_events`, with append-only child `re_event_deliveries` (one row per attempt).

## States (mirror the DB CHECK)

```text
pending      awaiting delivery; next_attempt_at is due-time
delivering   an attempt is in flight (claimed under a row lock)
delivered    R&E returned 202 after a durable write (terminal)
dead_letter  permanently stopped: 409 payload mismatch, 422 schema rejection, or max age (terminal)
superseded   replaced by a schema-version replacement replay (terminal; reserved)
```

## Transitions (mirror `ReDeliveryStatus::canTransitionTo()`)

```text
pending     -> delivering
delivering  -> delivered
delivering  -> pending      (retry with backoff; SAME event id, SAME body, SAME hash)
delivering  -> dead_letter
delivered   -> superseded   (schema-version replacement replay only)
dead_letter -> superseded   (schema-version replacement replay only)
superseded  -> (terminal)
```

The append-only trigger `re_outbound_events_append_only` enforces the terminal rules in the database
and freezes the event's identity and content: `ulid`, `event_id`, `event_type`, `event_version`,
`merchant_id`, `merchant_public_id`, `sequence_no`, `payload`, `content_sha256`, `occurred_at` and
`created_at` can never be rewritten. `re_outbound_events_no_delete` blocks deletion outright.

## Why the freeze matters

Plan §9 rule 22 requires that a retry carry **the same event id and the same body hash**. R&E dedupes
on `(event_id, content_sha256)` and answers a genuine `409 EVENT_ID_PAYLOAD_MISMATCH` when it sees the
same id with different content. That 409 is therefore a **tamper signal**, and it is only meaningful
because Servana physically cannot mutate a queued payload. Mutate-and-resend is forbidden; the event
dead-letters and a critical incident is raised instead.

## Delivery outcome → transition (Plan §58A.2)

| Outcome | `response_class` | Transition | Notes |
|---|---|---|---|
| `202` | `accepted` | `delivering → delivered` | `delivered_at` set (CHECK ties them together) |
| `409 EVENT_ID_PAYLOAD_MISMATCH` | `payload_mismatch` | `delivering → dead_letter` | stop permanently; `re.event_dead_lettered` audit at **high** severity; never mutate-and-resend |
| `422` schema | `schema_rejected` | `delivering → dead_letter` | contract drift; the fix ships as a code change with a `schema_version` bump, then replay |
| `401` / `403` | `unauthorized` | `delivering → pending` | credential problem: the event stays retriable, and the delivery pause flag + alert are raised so the queue does not burn attempts against a bad key |
| `429` | `rate_limited` | `delivering → pending` | honours `Retry-After` within the backoff cap |
| `5xx` | `server_error` | `delivering → pending` | exponential backoff with jitter |
| connect/timeout/TLS | `transport_error` | `delivering → pending` | no HTTP status observed |
| any other status | `unexpected` | `delivering → pending` | fail-closed: never treated as success |
| max age reached on any retryable outcome | (as observed) | `delivering → dead_letter` | Plan §58A.2 max age 7 days |

Backoff: exponential with jitter, base 30 s, cap 1 h, max age 7 days (Plan §58A.2), all configurable
in `config/refer-earn.php`. Every attempt — including the ones that keep the event `pending` — writes
one `re_event_deliveries` row with a truncated, redacted body.

## Ordering

`sequence_no` is per-merchant monotonic (`UNIQUE (merchant_id, sequence_no)`) because R&E workers
partition by merchant. The dispatcher preserves per-merchant order by refusing to deliver an event
while an **earlier** `sequence_no` for the same merchant is still undelivered; events for different
merchants are unordered with respect to each other and deliver concurrently.

`occurred_at` is the **business** time of the source fact. The signing timestamp
(`X-Citrus-Timestamp`) is **delivery** time, and R&E's tolerance window applies only to the signing
timestamp (Plan §58B.5 R-21).

## Audit (Plan §70)

`re.event_dead_lettered` (high) on every entry into `dead_letter`. Audit context carries the event
ULID, event id, event type, response class and status — never the signature, the nonce, the
credential, or the response body.

## Tests

`tests/Feature/Integrations/ReferEarn/OutboxEmissionTest.php` (same-transaction atomicity, sequence
monotonicity, schema validation, forbidden-field scan) and
`tests/Feature/Integrations/ReferEarn/OutboxDeliveryTest.php` (canonical-string signing vectors,
retry with the same id + hash, 409 → dead-letter, 422 → dead-letter, backoff caps, per-merchant
ordering).
