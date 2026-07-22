# Refer & Earn Integration — Data Dictionary (Plan §13.17, §58A, §58B; Phases 21R-A, 21R-B)

> Servana implements referral capture, signed outbound facts, qualification decisions, and inbound
> reconciliation — **not** reward ledgers, referrer accounts, campaigns, or payouts (ADR-013).
>
> **Ownership:** Servana owns referral-activity truth and qualification answers. **R&E owns
> referral-reward truth** (campaigns, calculation, ledger, payouts, statements).
>
> **Build status:** the three **Phase 21R-A** tables below are **BUILT** (migrations
> `2026_07_22_000001…000003`). The four **Phase 21R-B** tables are **specification only** — no
> migration exists and none may be authored before Phase 21R-B, whose entry criteria require
> Phase 20D-W and therefore External Gate W.

---

## Controlling sources

- Plan §13.17 (canonical DDL), §58A (capture/outbox/delivery), §58B (catalogue/payloads/qualification/
  reconciliation), §25.6 (state machines), §17.1 (machine identities), §9 rules 22–24,
  §24.5 (redaction), §74 (privacy/retention), §80.1 (21R-A parallel with 20C–20E; 21R-B dependencies)
- ADR-013 (integration authority + event contract), ADR-015 (machine identity + signing)
- `Refer_and_Earn_Project_Scope.md` + `Citrus_Refer_and_Earn_Production_Software_Development_Plan.md`
  (**not present in this repository** — see `docs/integrations/refer-earn/contract-pins.md` for what
  is pinned from the Servana Plan and what remains unpinned)

---

## Phase 21R-A tables (BUILT)

### `referral_snapshots` — Servana's immutable local referral evidence

Migration: `database/migrations/2026_07_22_000001_create_referral_snapshots_table.php`.
Model: `App\Domain\Integrations\ReferEarn\Models\ReferralSnapshot`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity | internal PK |
| `ulid` | char(26) unique | public identifier |
| `merchant_id` | bigint FK `merchants` RESTRICT, **unique** | at most one snapshot per merchant, ever |
| `raw_code_encrypted` | text, encrypted | the code **exactly as submitted**, as evidence; decrypted value is §24.5 redacted material and is `$hidden` on the model |
| `code_normalized` | varchar(64) nullable, indexed | uppercased/trimmed form; **NULL iff** `snapshot_status = 'invalid_format'` (CHECK) |
| `capture_channel` | varchar(16) CHECK | `query_param` / `manual_entry` / `central_redirect` |
| `captured_at` | timestamptz | set inside the registration transaction |
| `landing_metadata` | jsonb nullable | allowlisted, non-PII (see allowlist below) |
| `snapshot_status` | varchar(24) CHECK | `captured` / `invalid_format` / `validating` / `validated` / `rejected` / `confirmed` / `expired_unconfirmed` |
| `re_validation_result_code` | varchar(64) nullable | R&E's raw result code, kept verbatim as evidence and to let a later contract pin become a mapping change |
| `re_attribution_public_id` | varchar(64) nullable | R&E's **opaque public** attribution id — the only R&E-side identifier Servana ever stores |
| `confirmed_at` | timestamptz nullable | **NOT NULL iff** `snapshot_status = 'confirmed'` (CHECK) |
| `last_transition_at` | timestamptz | updated by every state transition |
| `created_at` / `updated_at` | timestamptz | |

Indexes: `ulid` unique; `merchant_id` unique; `code_normalized`;
`(snapshot_status, last_transition_at)`.

**Trigger `referral_snapshots_guard` (BEFORE UPDATE).** Enforces Plan §13.17's *"Immutable after
'confirmed'/'rejected' (status may not regress; trigger-enforced)"*:

1. no status change out of `confirmed`, `rejected`, `invalid_format` or `expired_unconfirmed`;
2. the capture evidence — `merchant_id`, `ulid`, `raw_code_encrypted`, `code_normalized`,
   `capture_channel`, `captured_at`, `landing_metadata` — is immutable at **any** status.

**Data minimization (Plan §9 rule 23, §74).** There is **no referrer identity column of any kind** —
no referrer name, account, contact, payout method or reward amount. Servana holds the submitted code
and R&E's opaque public attribution id. Nothing about the referrer is ever stored **or displayed**.

**`landing_metadata` allowlist** (enforced by
`App\Domain\Integrations\ReferEarn\Support\LandingMetadataAllowlist`, not merely documented):
`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `landing_path`,
`referrer_host`, `capture_variant`. Values are trimmed and length-bounded; unknown keys are dropped,
never stored. Explicitly forbidden: names, emails, phone numbers, IP addresses, user agents, free
text, raw headers, cookies, session ids and any tracking identifier not listed above.

**Tenancy.** Classified `TenantOwnership::EXEMPT`. `merchant_id` is NOT NULL, unique and indexed
(asserted directly by `Phase21RASchemaTest`), but the row is written inside the **public,
unauthenticated** self-registration transaction where no `TenantContext` can exist, and it is read
only by platform-side R&E jobs. No merchant-facing route, policy or Resource exposes it.

### `re_outbound_events` — transactional outbox (append-only)

Migration: `database/migrations/2026_07_22_000002_create_re_outbound_events_table.php`.
Model: `App\Domain\Integrations\ReferEarn\Models\ReOutboundEvent`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity | |
| `ulid` | char(26) unique | |
| `event_id` | char(26) **unique** | ULID; becomes `X-Citrus-Event-Id` and `Idempotency-Key`; generated once at insert, **stable across every retry** |
| `event_type` | varchar(64) CHECK | Phase 21R-A: the five `merchant.*` types only |
| `event_version` | varchar(8) | `1` |
| `merchant_id` | bigint FK `merchants` RESTRICT, nullable | null reserved for product-level events — **none at launch** |
| `merchant_public_id` | char(26) nullable | denormalized merchant ULID for payload stability; NULL iff `merchant_id` is NULL (CHECK) |
| `sequence_no` | bigint | per-merchant monotonic; `UNIQUE (merchant_id, sequence_no)` |
| `payload` | jsonb | minimal-fact body per §58B.2; frozen by trigger |
| `content_sha256` | char(64) CHECK `^[0-9a-f]{64}$` | computed at insert over the canonical JSON |
| `occurred_at` | timestamptz | **business** time of the source fact (signing timestamp is delivery time — Plan §58B.5 R-21) |
| `delivery_status` | varchar(16) CHECK | `pending` / `delivering` / `delivered` / `dead_letter` / `superseded` |
| `delivered_at` | timestamptz nullable | NOT NULL when `delivered` (CHECK) |
| `attempt_count` | int default 0 | |
| `next_attempt_at` | timestamptz nullable | backoff schedule |
| `last_response_status` | smallint nullable | |
| `last_error_code` | varchar(64) nullable | |
| `created_at` | timestamptz | no `updated_at` — the row is append-only |

Indexes: `ulid` unique; `event_id` unique; `(merchant_id, sequence_no)` unique;
`(delivery_status, next_attempt_at)` (dispatcher sweep); `(merchant_id, event_type)`.

**Event-type CHECK is a scope guard.** It admits only
`merchant.registration_started`, `merchant.admin_created`, `merchant.setup_completed`,
`merchant.status_changed`, `merchant.identity_snapshot_changed`. The §58B.1 `subscription.*` and
`activity.*` rows belong to Phase 21R-B and are **rejected by the database** until that phase widens
the constraint — so 21R-B cannot be built here by accident.

**Trigger `re_outbound_events_append_only` (BEFORE UPDATE).** Freezes `ulid`, `event_id`,
`event_type`, `event_version`, `merchant_id`, `merchant_public_id`, `sequence_no`, `payload`,
`content_sha256`, `occurred_at`, `created_at` — only delivery-progress columns may move. This is the
DB half of Plan §9 rule 22 (*"mutating a queued outbox payload after insert is forbidden and
prevented by an append-only trigger"*). `delivered` and `dead_letter` are terminal, with the single
§25.6 exception that either may move to `superseded` for a schema-version replacement replay;
`superseded` is final.

**Trigger `re_outbound_events_no_delete` (BEFORE DELETE).** An emitted fact is evidence.

### `re_event_deliveries` — delivery attempt history (append-only)

Migration: `database/migrations/2026_07_22_000003_create_re_event_deliveries_table.php`.
Model: `App\Domain\Integrations\ReferEarn\Models\ReEventDelivery`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity | |
| `re_outbound_event_id` | bigint FK `re_outbound_events` RESTRICT | scope inherited from the event |
| `attempted_at` | timestamptz | |
| `duration_ms` | int | |
| `response_status` | smallint nullable | null for transport errors (no HTTP status observed) |
| `response_class` | varchar(24) CHECK | `accepted` / `payload_mismatch` / `unauthorized` / `schema_rejected` / `rate_limited` / `server_error` / `transport_error` / `unexpected` |
| `error_code` | varchar(64) nullable | R&E error code or transport failure class |
| `response_body_truncated_redacted` | varchar(512) nullable | truncated **and** redacted before persistence |
| `created_at` | timestamptz | |

Index: `(re_outbound_event_id, attempted_at)`.

**Trigger `re_event_deliveries_append_only` (BEFORE UPDATE OR DELETE)** — every attempt is permanent.

**Redaction (Plan §24.5).** The stored body passes through
`App\Domain\Integrations\ReferEarn\Support\DeliveryResponseRedactor` before persistence. Request
headers, signature values, nonces and request payloads are never stored here at all.

---

## Phase 21R-B tables (SPECIFICATION ONLY — not built)

Authoring any of these before Phase 21R-B is a scope defect; their absence is asserted by the
Phase 21R-A forbidden-table scan.

| Table | Purpose | Owning phase |
|---|---|---|
| `re_activity_rule_versions` | Prospective Servana qualification rule versions (platform-managed; effective forward only; overlapping ranges rejected) | **21R-B** |
| `re_qualification_periods` | Bounded evaluation windows per merchant/period | **21R-B** |
| `re_qualification_decisions` | Append-only final Servana authority decisions with evidence checksums; corrections supersede-by-reference via `platform.referral.qualification.correct` (step-up, engine re-run — never free-form override) | **21R-B** |
| `re_inbound_requests` | Signed R&E→Servana reconciliation queries; nonce/replay store; verified per the ADR-015 inbound secret; retained 90 days (Plan §74) | **21R-B** |

> **Correction (Phase 21R-A).** A previous revision of this file labelled `re_inbound_requests` as a
> Phase 21R-A table. Plan §13.17 (line "re_inbound_requests (21R-B; replay protection …)"), Plan §12
> table inventory, and Plan §80's Phase 21R-B backend list ("the inbound reconciliation controller +
> query classes + replay store") all place it in **21R-B**. The Plan is source-of-truth rank 1; the
> label has been corrected. No code, migration or schema was affected — the table does not exist.

---

## Append-only and immutability summary

| Object | Rule | Enforcement |
|---|---|---|
| `referral_snapshots` capture evidence | immutable at any status | `referral_snapshots_guard` trigger |
| `referral_snapshots` terminal statuses | no exit from `confirmed`/`rejected`/`invalid_format`/`expired_unconfirmed` | same trigger + `ReferralSnapshotStatus::canTransitionTo()` |
| `re_outbound_events` identity + payload + hash | frozen after insert | `re_outbound_events_append_only` trigger |
| `re_outbound_events` rows | never deleted | `re_outbound_events_no_delete` trigger |
| `re_event_deliveries` rows | never updated, never deleted | `re_event_deliveries_append_only` trigger |
| `re_qualification_decisions` (21R-B) | append-only; supersede-by-reference with monotonic `decision_version` | future phase |

---

## Forbidden in Servana (ADR-013 ownership matrix)

| Forbidden | Owner |
|---|---|
| Referrer accounts and referrer identity | R&E |
| Referral codes as system of record | R&E |
| Referrer account balances | R&E |
| Campaign definition as system of record | R&E |
| Reward rules and reward calculation | R&E |
| Reward ledger / payout batches / reward statements | R&E |
| Payout amount calculation | R&E |
| Attribution uniqueness as the effective earning authority | R&E |

---

## Permissions

| Key | Owning phase | Status after Phase 21R-A |
|---|---|---|
| `platform.integrations.refer_earn.manage` | Phase 21R-A | **still `planned`** — 21R-A adds no R&E admin route (no rule-version management, no dead-letter replay endpoint, no inbound key-set management); those are the capabilities the key describes and they land with 21R-B / the Integrations Health screen |
| `platform.integrations.health.view` | Phase 20D-W | `planned` — the shared Integrations Health screen (§12.1 item 4) is owned by 20D-W and its Wallet panels cannot be built while Gate W is closed |
| `platform.referral.qualification.view` | Phase 21R-B | `planned` |
| `platform.referral.qualification.correct` | Phase 21R-B | `planned` |

Merchant roles receive **none** of the above. Phase 21R-A therefore changes **no** permission,
activates **no** key, and adds **no** authenticated route.

---

## Audit events

Phase 21R-A (Plan §70 integration audit rows): `re.referral_captured` (info),
`re.attribution_confirmed` (info), `re.attribution_rejected` (info), `re.event_dead_lettered` (high).

Phase 21R-B: `re.qualification_decided` (info), `re.qualification_corrected` (high),
`re.inbound_reconciliation_query` (info). `integration.credential_rotated` (critical) lands with the
rotation runbooks (Plan §77.1).

---

## Retention (Plan §74)

- `referral_snapshots` — retained for the life of the merchant record (attribution evidence).
- `re_outbound_events` / `re_event_deliveries` — append-only integration evidence; archival policy is
  set with the Phase 25 retention work, never destructive deletion.
- `re_inbound_requests` (21R-B) — 90 days.

---

## Migration manifest

`docs/architecture/migrations/manifest.yaml` carries the three Phase 21R-A entries
(`2026_07_22_000001…000003`, `owner_phase: "21R-A"`, `change_type: create`, all
`production_compatible: true`, `destructive: false`). Phase 21R-B entries are added when that phase
ships.
