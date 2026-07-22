# Citrus Refer & Earn — Contract Pins

> Plan §81 rule 23: *"record the Wallet OpenAPI hash and R&E schema versions in
> `docs/integrations/*/contract-pins.md`; any pin change is its own reviewed commit."*

**Status at Phase 21R-A: partially pinned from the Servana Plan; NOT verified against the R&E
platform.** The authoritative R&E documents are not present in this repository (see
`credentials-receipt.md`), so everything below is either (a) transcribed from the Servana Plan, which
quotes the R&E dev plan, or (b) explicitly **UNPINNED** and configured to fail closed.

Deferred-verification item: `REM-RE-002` (`docs/remediation/register.yaml`).

---

## Pinned from the Servana Plan (rank-1 source)

| Pin | Value | Source |
|---|---|---|
| Outbound delivery endpoint | `POST {RE_BASE}/api/v1/integrations/products/{productCode}/events` | Plan §58A.2 |
| Signature header set | `X-Citrus-Key-Id`, `X-Citrus-Event-Id`, `X-Citrus-Event-Type`, `X-Citrus-Event-Version`, `X-Citrus-Timestamp`, `X-Citrus-Nonce`, `X-Citrus-Content-SHA256`, `X-Citrus-Signature` | Plan §58A.2 (R&E dev plan §11.7) |
| Idempotency header | `Idempotency-Key = event_id` | Plan §58A.2 |
| Canonical signing string | `METHOD\nPATH\nTIMESTAMP\nNONCE\nCONTENT_SHA256\nEVENT_ID\nEVENT_TYPE\nEVENT_VERSION` (LF-joined, no trailing newline) | Plan §9 rule 22 |
| `content_sha256` | lowercase hex SHA-256 over the **canonical JSON body actually sent** (sorted keys, no insignificant whitespace) | Plan §58A.2, §13.17 |
| Event id | ULID, generated once at outbox insert, stable across every retry | Plan §9 rule 22 |
| Response handling | `202` → delivered; `409 EVENT_ID_PAYLOAD_MISMATCH` → dead-letter (tamper signal, never mutate-and-resend); `401/403` → pause + alert; `422` → dead-letter (contract drift); `429/5xx`/timeout → backoff with jitter, base 30 s, cap 1 h, max age 7 days → dead-letter | Plan §58A.2 |
| Referral code shape | `SERVANA-` + uppercase alphanumerics (e.g. `SERVANA-X8T2K`) | Plan §58A.1, §13.17 |
| Payload envelope | `product_code`, `environment`, `merchant_public_id`, `event_id`, `occurred_at`, `sequence_no`, `schema_version` | Plan §58B.2 |
| Phase 21R-A event types | `merchant.registration_started`, `merchant.admin_created`, `merchant.setup_completed`, `merchant.status_changed`, `merchant.identity_snapshot_changed` | Plan §58B.1 |
| Status-change reason categories | `fraud`, `security`, `legal`, `compliance`, `manual` — **category only**, never free text | Plan §58B.1 |

Payload JSON Schemas as built: `docs/integrations/refer-earn/schemas/*.v1.json` — schema version `1`.

---

## UNPINNED — fail closed until `REM-RE-002` closes

| Pin | Status | Behaviour today |
|---|---|---|
| Signing algorithm identifier | **UNPINNED.** ADR-015 forbids hardcoding HMAC-SHA-256 without an authoritative contract pin. | `CitrusEventSigner` selects the routine by configured algorithm identifier and **throws** on an unknown/missing value. The shipped default is `null`; the sandbox/dev configuration selects the one algorithm implemented (`hmac-sha256`) explicitly, so the choice is always a recorded configuration decision rather than a silent default. |
| `X-Citrus-Key-Id` value | **UNPINNED** — no key issued. | `env('REFER_EARN_SIGNING_KEY_ID')`, no default. Delivery is disabled when absent. |
| R&E product code for Servana | **UNPINNED.** Plan §81 rule 24 lists this as a blocking ambiguity and records `SRV` as an *assumption*. | `env('REFER_EARN_PRODUCT_CODE', 'SRV')` — the assumed value is used and is flagged here so a later pin is a one-line configuration change, not a code change. |
| R&E base URL | **UNPINNED** — no sandbox issued. | `env('REFER_EARN_BASE_URL')`, no default. Delivery is disabled when absent. |
| Attribution confirm window (expiry to `expired_unconfirmed`) | **UNPINNED.** Plan §81 rule 24 lists "the R&E confirm-window for attribution expiry" as a blocking ambiguity. | Configured (`REFER_EARN_CONFIRM_WINDOW_HOURS`, default 168 h = 7 days) and applied only by an explicit expiry transition; **no** event is emitted or suppressed differently because of it in 21R-A beyond the documented emission-scope rule. Recorded here so the pin replaces a configuration value, not logic. |
| Validation / confirmation companion endpoints (`…/referral-codes/validate`, `…/attributions/confirm`) response code → `snapshot_status` mapping | **PARTIALLY PINNED.** Plan §58A.2 names the endpoints and says "response codes map to `snapshot_status`" but does not enumerate the R&E result codes. | The client returns a typed outcome (`valid` / `invalid` / `retryable`) and the action maps it to `validated` / `rejected` / stay-in-`validating`. The raw R&E result code is stored verbatim in `re_validation_result_code` for evidence, so a later pin is a mapping-table change with the historical codes already on record. |

---

## Change protocol

Any change to this file is its own reviewed commit (Plan §81 rule 23). A pin change that alters an
emitted payload requires a `schema_version` bump plus a new committed JSON Schema; per Plan §58A.2 a
schema-version replacement replay uses the outbox `superseded` delivery status and never mutates an
already-inserted payload.
