# Data dictionary — Personnel bulk SMS (Phase 21S)

Canonical DDL for the four Phase 21S tables. Sources: **Plan §13.13** (canonical DDL), **§64**
(Personnel Bulk SMS), **§20** (plan entitlements), **§22** (billing status), **§24.5** (log
redaction), **§74** (privacy/masking/retention); **ADR-010** (personnel contact protection),
**ADR-005** (integer minor units), **ADR-004** (forward-only migrations).

State machines: [`personnel-sms-campaign.md`](../state-machines/personnel-sms-campaign.md),
[`personnel-sms-recipient.md`](../state-machines/personnel-sms-recipient.md),
[`sms-billing-entry.md`](../state-machines/sms-billing-entry.md).

---

## The contact-protection contract (read this first)

ADR-010 and Plan §19.4 make personnel contact export **non-existent, not merely unauthorised**.
These four tables are where that has to be true structurally, not by convention:

| Guarantee | How the schema enforces it |
|---|---|
| No endpoint returns bulk full phone numbers | The only column holding a full number is `personnel_sms_recipients.phone_encrypted`. It is encrypted at rest, `$hidden` on the model, and referenced by exactly one class (`DeliverSmsRecipient`) immediately before the provider call. |
| A suppressed recipient's number is never even copied | `phone_encrypted` is **NULLABLE**; `personnel_sms_recipients_phone_required_check` permits NULL only for `opted_out` / `suppressed`, and the composition action writes NULL for them (Plan §74 data minimization). |
| Masked display is the maximum | `phone_last_four char(4)` + `phone_last_four ~ '^[0-9]{4}$'`. Every Resource renders through `PhoneNumberDisplayMasker`, which cannot return more than four digits. |
| No number can be smuggled through jsonb | `personnel_sms_recipients_snapshot_no_phone_check` rejects a `phone` / `phone_encrypted` / `msisdn` / `phone_number` key in `eligibility_snapshot_json` outright. |
| No number can survive a redactor regression | `sms_delivery_attempts_redaction_check` rejects any `provider_message_redacted` containing a run of 7+ digits. |
| No searchable phone index exists here | Deliberately absent. `clients.phone_index` (15A) is a Front-Office duplicate-prevention blind index; nothing in the SMS surface reads it. |
| Campaigns hold no contact at all | `personnel_sms_campaigns` has no phone, email or client column of any kind. |

---

## `personnel_sms_campaigns` (21S)

**Classification:** `BRANCH_OWNED` + `COMPOSITE_CONSISTENCY` (parent `merchant_branches` via
`branch_id`). Model `App\Domain\Messaging\Sms\Models\PersonnelSmsCampaign`
(`BelongsToMerchant` + `BelongsToBranch`). Public route key: `ulid`.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity PK | |
| `ulid` | char(26) UNIQUE | public reference + route key |
| `merchant_id` | FK `merchants` RESTRICT | |
| `branch_id` | FK `merchant_branches` RESTRICT | the personnel member's HOME branch (`staff_profiles.primary_branch_id`) |
| `staff_profile_id` | FK `staff_profiles` RESTRICT | **own scope**; derived from the authenticated membership, never client-supplied |
| `message_body_encrypted` | text | Eloquent `encrypted` cast; `$hidden`; never logged, never audited, never returned by a Resource (a personnel-authored message may name a client) |
| `message_template_id` | bigint NULL | §13.13 reserves it for a future template catalogue. Phase 21S ships none, so a CHECK pins it to NULL rather than allowing a dangling reference. |
| `recipient_count` | uint | eligible recipients at confirmation (the billing basis) |
| `message_character_count` | uint | computed by `SmsMessageSegmentCalculator`, server-side |
| `segment_count` | uint | GSM-7 160/153, UCS-2 70/67; extension-table characters count twice |
| `estimated_cost_minor` | bigint | integer minor units (ADR-005) |
| `final_cost_minor` | bigint NULL | set at settlement |
| `currency` | char(3) | `= upper()`, length 3 |
| `status` | varchar(20) | CHECK: `draft`, `confirmed`, `queued`, `sending`, `completed`, `partially_failed`, `failed`, `cancelled` |
| `failure_reason_code` | varchar(32) NULL | e.g. `all_recipients_failed` |
| `consent_snapshot_at` | timestamptz NULL | the instant consent was captured; required past `draft`/`cancelled` |
| `created_by` | FK `users` RESTRICT | |
| `confirmed_at` / `queued_at` / `completed_at` / `cancelled_at` | timestamptz NULL | |
| `created_at` / `updated_at` | timestamptz | |

**Composite FKs:** `(branch_id, merchant_id) → merchant_branches(id, merchant_id)`;
`(staff_profile_id, merchant_id) → staff_profiles(id, merchant_id)`.
**Extra unique:** `(id, merchant_id)` — backs the child tables' composite FKs.
**Indexes:** `(staff_profile_id, status)` (own-scope list), `(merchant_id, branch_id, status)`.

**CHECKs:** status enum · currency shape · non-negative costs · non-blank body · `segment_count ≥ 1
AND message_character_count ≥ 1` · `message_template_id IS NULL` · `confirmed_at` present past
draft (except cancelled) · `consent_snapshot_at` present past draft/cancelled · `recipient_count ≥
1` past draft/cancelled.

**Trigger `personnel_sms_campaigns_guard`:** terminal states (`completed`/`failed`/`cancelled`)
reject any status change; ownership columns (`ulid`, `merchant_id`, `branch_id`,
`staff_profile_id`, `created_by`) are immutable at all times; the composition/pricing snapshot is
frozen once the campaign leaves `draft`.

---

## `personnel_sms_recipients` (21S)

**Classification:** `BRANCH_OWNED` + `COMPOSITE_CONSISTENCY`. §13.13 lists only the distinguishing
columns; `merchant_id` + `branch_id` are added per repository convention for child tables
(`cash_up_lines`, `invoice_items`) so a recipient can never reference a client, session or campaign
across a merchant boundary **in the database**, not merely in application code.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity PK | |
| `merchant_id` / `branch_id` | FK RESTRICT | the CLIENT's own branch (may differ from the campaign's when a membership spans branches) |
| `campaign_id` | FK `personnel_sms_campaigns` RESTRICT | |
| `client_id` | FK `clients` RESTRICT | |
| `service_session_id` | FK `service_sessions` RESTRICT NULL | the completed session evidencing the served-client relationship |
| `phone_encrypted` | text **NULL** | delivery snapshot ONLY; `encrypted` cast; `$hidden`; **NULL for a recipient excluded at composition** |
| `phone_last_four` | char(4) | the maximum display identifier anywhere in the product |
| `eligibility_snapshot_json` | jsonb | safe ids/statuses/reason codes only |
| `consent_status_snapshot` | varchar(16) | CHECK: `opted_in`, `opted_out`, `missing` — the SMS vocabulary deliberately adds `missing`, which 15A's `ConsentState` has no value for |
| `delivery_status` | varchar(16) | CHECK: `pending`, `sent`, `delivered`, `failed`, `opted_out`, `suppressed` |
| `provider_message_id` | varchar(64) NULL | opaque provider handle; **not exposed by any Resource** |
| `cost_minor` | bigint NULL | provider-reported cost (advisory; Servana's tariff is authoritative for billing) |
| `created_at` / `updated_at` | timestamptz | |

**Composite FKs:** branch→merchant, campaign→merchant, client→merchant, session→merchant.
**Unique:** `(campaign_id, client_id)` — the Plan §64 dedupe key.
**Index:** `(campaign_id, delivery_status)` — the delivery queue and the roll-up read.

**CHECKs:** delivery-status enum · consent-snapshot enum · `phone_last_four` is 4 digits ·
non-blank phone when present · non-negative cost · **no phone key in the jsonb** · dispatchable
recipients carry a phone snapshot · undispatched recipients carry no provider id and no cost.

**Triggers:** `personnel_sms_recipients_guard` (snapshot columns immutable at all times; terminal
delivery statuses final) and `personnel_sms_recipients_no_delete` (rows are delivery evidence and
are never deleted).

---

## `sms_delivery_attempts` (21S)

**Classification:** `EXEMPT` — scope is inherited via `recipient_id` (itself branch-owned with
composite consistency FKs) and the table is **never route-bound**: no Resource and no endpoint
exposes an attempt. Same rationale as `re_event_deliveries` (21R-A) and `file_scan_events` (10F).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity PK | |
| `recipient_id` | FK `personnel_sms_recipients` RESTRICT | |
| `attempt_number` | usmallint | `≥ 1`; UNIQUE `(recipient_id, attempt_number)` |
| `provider` | varchar(32) | provider slug (`fake` in CI) |
| `status` | varchar(24) | CHECK: `accepted`, `transient_failure`, `permanent_failure` |
| `result_class` | varchar(32) | CHECK: `accepted`, `invalid_recipient`, `opted_out`, `rate_limited`, `insufficient_balance`, `provider_error`, `transport_error`, `unauthorized`, `unexpected` — the RETRY DECISION INPUT, stored so the policy is provable without a live provider |
| `provider_code` | varchar(64) NULL | the provider's own bounded code |
| `provider_message_redacted` | varchar(512) NULL | passed through `SmsProviderPayloadRedactor` **before** persistence |
| `duration_ms` | uint NULL | |
| `attempted_at` | timestamptz | |
| `next_retry_at` | timestamptz NULL | only a `transient_failure` may schedule one (CHECK) |
| `created_at` | timestamptz | |

**Indexes:** `(recipient_id, attempted_at)`, `next_retry_at`.
**CHECKs:** status enum · result-class enum · `attempt_number ≥ 1` · retry only on transient ·
**redaction check** — `provider_message_redacted !~ '[0-9]{7}'`, so a phone number cannot survive a
redactor regression.
**Trigger `sms_delivery_attempts_append_only`:** every UPDATE and DELETE is rejected.

---

## `sms_billing_entries` (21S)

**Classification:** `BRANCH_OWNED` + `COMPOSITE_CONSISTENCY` (§13.13 lists `merchant_id` +
`branch_id`).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint identity PK | |
| `ulid` | char(26) UNIQUE | |
| `merchant_id` / `branch_id` | FK RESTRICT | |
| `campaign_id` | FK `personnel_sms_campaigns` RESTRICT | |
| `quantity` | uint | billable recipients × segments |
| `unit_cost_minor` | bigint | per segment per recipient; configured placeholder pending **REM-SMS-002** |
| `amount_minor` | bigint | **CHECK `amount_minor = quantity * unit_cost_minor`** (ADR-005) |
| `currency` | char(3) | |
| `status` | varchar(16) | CHECK: `provisional`, `billable`, `invoiced`, `credited`, `cancelled` |
| `billing_invoice_line_id` | FK `subscription_invoice_items` RESTRICT NULL | the seam a FUTURE billing phase sets; Phase 21S never does |
| `created_at` / `updated_at` | timestamptz | |

**Composite FKs:** branch→merchant, campaign→merchant.
**Index:** `(merchant_id, status)`.
**Partial unique index `sms_billing_entries_live_campaign_unique`:** `(campaign_id)` WHERE
`status IN ('provisional','billable','invoiced')` — **at most one live charge per campaign**, the
structural no-double-billing guarantee.
**CHECKs:** status enum · currency shape · non-negative amounts · the amount product · an invoice
line only on `invoiced`/`credited`.
**Trigger `sms_billing_entries_guard`:** monetary/ownership columns immutable (only `status` and
`billing_invoice_line_id` may transition); `credited`/`cancelled` terminal.

**Servana moves no money here (ADR-012):** no Wallet payment resource, no payment attempt, no
subscription payment event, no provider call.

---

## Retention (Plan §74)

Plan §74: *"SMS recipient/phone data retained per policy then purged."* Phase 21S ships the
minimization half of that contract — a suppressed recipient's number is never stored, and a stored
delivery snapshot is encrypted, hidden and read exactly once. The scheduled **purge** of aged
delivery snapshots is queue/scheduler work owned by **Phase 21N** (§67 scheduler) and is recorded
here as a deferred obligation rather than silently assumed; `personnel_sms_recipients_no_delete`
means that purge must be an explicit, audited migration/job, not an ad-hoc DELETE.
