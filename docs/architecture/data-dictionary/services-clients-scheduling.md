# Services, Clients & Scheduling — Data Dictionary

Canonical DDL authority for the Phase 15A catalogue/clients tables, the Phase
15B `personnel_availability` scheduling table, and the Phase 16A `appointments`
table (Plan §13.2, §13.7, §35, §36, §39). The remaining scheduling tables
(`walk_ins`, `queue_entries`, `service_sessions`) are listed in §13.7 but are
**owned by later phases (16B–16C)** and are intentionally **not** created here.

Structural rule (Plan §2.1, §13.1): tenant-owned → `merchant_id`; branch-owned →
`merchant_id` + `branch_id` with a DB-level composite-FK consistency constraint
`(branch_id, merchant_id) → merchant_branches(id, merchant_id)`
(`ON DELETE CASCADE ON UPDATE CASCADE`). All five Phase 15A tables are
**branch-owned**. Ownership classifications live in
`app/Domain/Tenancy/TenantOwnership.php` (read by the coverage tests). External
identifiers are ULIDs (`getRouteKeyName() = 'ulid'`); internal keys are bigint
`id`. Money is integer **minor units** via the `Money` value object; currency is
an uppercase ISO-4217 `char(3)` defaulting to `KES`.

---

## Migration order (forward-only; no shipped migration edited)

1. `…_create_service_categories_table` — branch-owned parent of `services`.
2. `…_create_services_table` — FK `category_id → service_categories` RESTRICT.
3. `…_create_service_personnel_eligibility_table` — FKs to `services` +
   `staff_profiles` RESTRICT.
4. `…_create_clients_table` — encrypted contact + HMAC blind index.
5. `…_create_client_consents_table` — FK `client_id → clients` RESTRICT.
6. `…_create_personnel_availability_table` (15B) — branch-owned schedule rows.
7. `…_create_appointments_table` (16A) — FKs to `clients`/`services`/
   `staff_profiles` (RESTRICT) + assigned-personnel `tstzrange` exclusion.

Each migration: `Schema::create` → CHECK constraints + partial/unique indexes +
composite-FK consistency constraint via raw `DB::statement` (mirrors the
established branch-table pattern). Composite-FK targets require `services`,
`service_categories` and `clients` to carry `UNIQUE (id, merchant_id)` so their
own children can reference them; each table adds that pair in its own migration.
Down migrations `dropIfExists` in reverse dependency order.

---

## `service_categories` (15A) — branch-owned

Branch Manager-owned grouping for the catalogue (`service.*`). Soft-archival
only; a referenced category is never hard-deleted (FK RESTRICT from `services`).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key |
| `ulid` | char(26) | no | — | external id; `UNIQUE`; route key |
| `merchant_id` | bigint FK→merchants | no | — | tenant owner; `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | branch owner; `ON DELETE CASCADE` |
| `name` | varchar(120) | no | — | display name |
| `sort_order` | int | no | `0` | catalogue ordering |
| `archived_at` | timestamptz | yes | `null` | archival metadata (soft) |
| `created_by` / `updated_by` | bigint FK→users | yes | `null` | actor; `ON DELETE SET NULL` |
| `created_at` / `updated_at` | timestamptz | no | — | Eloquent timestamps |

- **Constraints/indexes:** `UNIQUE (ulid)`; **partial unique**
  `(branch_id, name) WHERE archived_at IS NULL` (branch-scoped active-name
  uniqueness, §13.7); `UNIQUE (id, merchant_id)` (composite-FK target);
  index `(merchant_id, branch_id)`; composite FK
  `(branch_id, merchant_id) → merchant_branches(id, merchant_id)`.
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`.
- **Archival:** `archived_at` set by the `ArchiveServiceCategory` domain action;
  no destructive delete while referenced.
- **Audit:** `service_category.created` / `.updated` / `.archived`.
- **Factory/seeder:** `ServiceCategoryFactory`; demo seeder creates a couple of
  categories per demo branch (local only).
- **Tests:** branch-scoped uniqueness, archival exclusion, isolation, audit.

## `services` (15A) — branch-owned

Branch Manager owns the catalogue (`service.create/update/archive`, §39).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key |
| `ulid` | char(26) | no | — | `UNIQUE`; route key |
| `merchant_id` | bigint FK→merchants | no | — | `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | `ON DELETE CASCADE` |
| `category_id` | bigint FK→service_categories | no | — | **RESTRICT** (§13.7) |
| `name` | varchar(150) | no | — | service name |
| `description` | text | yes | `null` | optional |
| `price_minor` | bigint | no | — | integer minor units (`Money`); `>= 0` |
| `currency` | char(3) | no | `'KES'` | uppercase ISO-4217 |
| `duration_minutes` | int | no | — | `> 0` |
| `preferred_personnel_fee_minor` | bigint | yes | `null` | **LEGACY** seam (§13.7/§39); internal, non-editable; superseded by `preferred_personnel_fee_rules` (Phase 20A) |
| `status` | varchar(16) | no | `'active'` | CHECK `IN ('active','archived')` |
| `created_by` / `updated_by` | bigint FK→users | yes | `null` | `ON DELETE SET NULL` |
| `created_at` / `updated_at` | timestamptz | no | — | timestamps |

- **Constraints/indexes:** `UNIQUE (ulid)`; CHECK `status IN ('active','archived')`;
  CHECK `price_minor >= 0`; CHECK `duration_minutes > 0`; CHECK `char_length(currency)=3`;
  index `(branch_id, status)` (§13.7); `UNIQUE (id, merchant_id)` (composite-FK target);
  composite FK to `merchant_branches`; FK `category_id` **RESTRICT**; the
  composite FK `(category_id, merchant_id) → service_categories(id, merchant_id)`
  guarantees a service and its category share a tenant.
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`.
- **Status machine:** `active → archived` via `ArchiveService` domain action
  (state-guarded; not arbitrary controller assignment). Archived services are
  excluded from active-selection queries (`scopeActive`).
- **Preferred-personnel fee:** the legacy fixed `preferred_personnel_fee_minor`
  is retained read-only during expand-and-contract; **Branch Manager cannot edit
  it** and there is no API field for it. The platform fee rule
  (`preferred_personnel_fee_rules`) and its configuration belong to Phase 20A —
  **not created here**.
- **Audit:** `service.created` / `.updated` / `.archived` (safe ids + before/after
  price/duration; no secrets).
- **Factory/seeder:** `ServiceFactory`; demo seeder seeds a few services/category.
- **Tests:** create/update/list/archive by Branch Manager; FO/HR/Admin cannot
  mutate (catalogue); money minor-unit + currency validation; archived excluded;
  uniqueness; cross-tenant 404; cross-branch 403; billing read-only blocks
  mutation, allows read; audit emission.

## `service_personnel_eligibility` (15A) — branch-owned

HR-owned (`personnel.eligibility.manage`, §19.3) gate of which personnel may
perform which service, within HR's assigned branch.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key |
| `merchant_id` | bigint FK→merchants | no | — | `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | `ON DELETE CASCADE` |
| `service_id` | bigint FK→services | no | — | **RESTRICT** (§13.7) |
| `staff_profile_id` | bigint FK→staff_profiles | no | — | **RESTRICT** (§13.7) |
| `active` | boolean | no | `true` | revoke = `false` (domain action) |
| `created_by` / `updated_by` | bigint FK→users | yes | `null` | `ON DELETE SET NULL` |
| `created_at` / `updated_at` | timestamptz | no | — | timestamps |

- **No `ulid`** (join/junction table; not directly route-bound by ULID — managed
  through service/personnel-scoped routes, mirroring §13.7 which lists no ulid).
- **Constraints/indexes:** `UNIQUE (service_id, staff_profile_id)` (§13.7 —
  one row per pair; assign/revoke toggles `active`); index `(branch_id)`;
  index `(staff_profile_id)`; composite FK to `merchant_branches`; composite FKs
  `(service_id, merchant_id) → services(id, merchant_id)` and
  `(staff_profile_id, merchant_id) → staff_profiles(id, merchant_id)` — **same
  merchant** guaranteed in DB. Same-**branch** consistency (service.branch_id =
  staff.primary_branch_id) is validated in the `AssignEligibility` action +
  Form Request (no cross-branch eligibility) and asserted by tests; branch_id is
  derived from the service.
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`.
- **Mutation:** restricted to HR with `personnel.eligibility.manage`; `assign`
  (create-or-reactivate) / `revoke` (`active=false`) via domain actions.
- **Audit:** `personnel_eligibility.assigned` / `.revoked`.
- **Factory/seeder:** `ServicePersonnelEligibilityFactory`.
- **Tests:** HR assign/revoke in branch; Branch Manager cannot mutate; HR cannot
  reach another branch's service/personnel; same-tenant/same-branch validation;
  duplicate-active rejected; audit.

## `clients` (15A) — branch-owned

Front Office-owned client record (`client.*`, §35). Contact **encrypted at rest,
displayed masked**; phone searchable/duplicate-checked via a keyed **HMAC blind
index** (never plaintext, never returned).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key |
| `ulid` | char(26) | no | — | `UNIQUE`; route key |
| `merchant_id` | bigint FK→merchants | no | — | `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | `ON DELETE CASCADE` |
| `full_name` | varchar(160) | no | — | display |
| `phone_encrypted` | text | no | — | AES-256-GCM (`encrypted` cast); never logged |
| `phone_index` | char(64) | no | — | HMAC-SHA256(normalized phone) hex; blind index; **never returned by API**, redacted from logs |
| `phone_last_four` | char(4) | no | — | masked-display only |
| `email_encrypted` | text | yes | `null` | AES-256-GCM (`encrypted` cast) |
| `notes` | text | yes | `null` | free text |
| `created_by` | bigint FK→users | yes | `null` | actor; `ON DELETE SET NULL` |
| `status` | varchar(16) | no | `'active'` | CHECK `IN ('active','archived')` |
| `created_at` / `updated_at` | timestamptz | no | — | timestamps |

- **Constraints/indexes:** `UNIQUE (ulid)`; CHECK `status IN ('active','archived')`;
  CHECK `char_length(phone_last_four)=4`; **partial unique**
  `(branch_id, phone_index) WHERE status='active'` → *one active client per
  branch + normalized phone* (same-branch duplicate prevention, §35) — the same
  phone MAY exist in another branch/merchant; index `(branch_id)` (§13.7);
  index `(branch_id, status)`; `UNIQUE (id, merchant_id)` (composite-FK target);
  composite FK to `merchant_branches`. **No plaintext full-phone index exists.**
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`.
- **Encryption / blind index (Plan §35; guardrail §6.4):**
  - `phone_encrypted` / `email_encrypted` use the Laravel `encrypted` cast
    (AES-256-GCM on `APP_KEY`); decrypted only server-side; `$hidden` so they
    never serialize.
  - Phone is normalized by `PhoneNumberNormalizer` (strip non-digits; Kenyan
    `07…`/`+254…`/`254…` → canonical `+2547########`). The blind index is
    `hash_hmac('sha256', normalized, config('servana.clients.contact_index_key'))`.
    The key is env-backed (`CLIENT_CONTACT_INDEX_KEY`), base64-encoded 32 bytes,
    placeholder-documented in `.env.example`, **never** in code/repo/logs.
  - `phone_index` is `$hidden` and is **never** present in any API Resource;
    log redaction lists `phone_encrypted`, `phone_index`, `email_encrypted`.
  - A deterministic blind index (HMAC) is used **only** for equality
    search/uniqueness, never as a substitute for encryption of the value.
- **Masking:** reads expose `full_name`, `phone_masked` (`••• ••• N{last4}`),
  `phone_last_four`, and `email_masked` (`a••@domain`) — never raw contact.
- **Status / deletion:** `active`/`archived`; **no hard delete**. Phase 15A does
  **not** expose a client-archive mutation route (no authoritative permission /
  workflow yet — the column exists for future phases); the default state is
  `active`.
- **No client self-service portal / account** at launch.
- **Audit:** `client.created` / `client.updated` (safe ulid + masked phone only;
  never full phone/email, never the blind index).
- **Factory/seeder:** `ClientFactory` (sets encrypted + index + last_four
  consistently); demo seeder seeds a few clients/branch (local only).
- **Tests:** create/search/view/update by FO in branch; same-branch active phone
  duplicate → deterministic 409 conflict; same phone allowed in another
  branch/merchant; ciphertext ≠ plaintext in DB; blind index never returned/
  logged; masked-only resources; branch+tenant-isolated search by name/phone; no
  Personnel contact-export route; billing read-only allows read, blocks mutation;
  no full phone/email in logs/audit/browser storage.

## `client_consents` (15A/21S) — branch-owned

SMS-consent state capture (§35). **No SMS delivery** in Phase 15A (Phase 21S).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key |
| `merchant_id` | bigint FK→merchants | no | — | `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | `ON DELETE CASCADE` |
| `client_id` | bigint FK→clients | no | — | **RESTRICT** (§13.7) |
| `channel` | varchar(8) | no | `'sms'` | CHECK `IN ('sms')` |
| `state` | varchar(12) | no | — | CHECK `IN ('opted_in','opted_out')` |
| `source` | varchar(40) | no | — | e.g. `front_office` |
| `changed_at` | timestamptz | no | `now()` | last change time |
| `created_by` | bigint FK→users | yes | `null` | actor; `ON DELETE SET NULL` |
| `created_at` / `updated_at` | timestamptz | no | — | timestamps |

- **Constraints/indexes:** `UNIQUE (client_id, channel)` (§13.7 — **one current
  state per client/channel**; changing consent updates the row + `changed_at`);
  index `(branch_id)`; composite FK to `merchant_branches`; composite FK
  `(client_id, merchant_id) → clients(id, merchant_id)`.
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`.
- **Mutation:** Front Office (`client.update`) records/changes SMS consent for a
  client in its branch via the `ChangeClientConsent` action.
- **Audit:** `client_consent.opted_in` / `.opted_out` (client ulid + state +
  source; no contact data).
- **Factory/seeder:** `ClientConsentFactory`.
- **Tests:** opt-in/opt-out persists single current state; audited; branch/tenant
  isolation; billing read-only blocks mutation.

---

## `personnel_availability` (15B) — branch-owned

HR-owned (`personnel.availability.manage`, §19.3) canonical schedule source for a
staff member's working time, breaks, days off, and temporary/emergency
unavailability, within HR's assigned branch. It is the shared availability
substrate consumed by later scheduling domains (appointments 16A, queues 16B,
sessions 16C). One staff member has many rows; the effective availability for any
moment is computed by the deterministic `AvailabilityResolver` (no derived state
is persisted). Phase 15B creates this table per the §80 roadmap entry (which
controls over the §13.7 schema-summary's `(16A)` label); `appointments` remain
Phase 16A.

> **Phase ownership decision (recorded 2026-06-29).** Plan §13.7 schema summary
> tags `personnel_availability (16A)`, but the §80 roadmap entry for **Phase 15B**
> explicitly assigns *"DB: `personnel_availability`"* and Phase 16A's entry
> assigns *"DB: `appointments`"*. The specific §80 sequencing entry is the
> controlling instruction → **15B owns `personnel_availability`; 16A owns
> `appointments`.** No appointment table is created in 15B.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key |
| `merchant_id` | bigint FK→merchants | no | — | tenant owner; `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | branch owner; `ON DELETE CASCADE`; always = `staff.primary_branch_id` |
| `staff_profile_id` | bigint FK→staff_profiles | no | — | **RESTRICT** (historical schedule never cascade-deleted) |
| `weekday` | smallint | yes | `null` | recurring rows only; `0=Sunday … 6=Saturday` (same convention as `branch_operating_hours`); `null` for exception rows |
| `date` | date | yes | `null` | exception rows only (one exact business date, `Africa/Nairobi`); `null` for recurring rows |
| `start_time` | time | no | — | inclusive interval start (branch business time) |
| `end_time` | time | no | — | **exclusive** interval end; half-open `[start_time, end_time)` |
| `type` | varchar(12) | no | — | CHECK `IN ('recurring','exception')` (`AvailabilityType` enum) |
| `available` | boolean | no | — | `true` = working/available interval; `false` = break / unavailable interval (subtracts time) |
| `created_at` / `updated_at` | timestamptz | no | — | Eloquent timestamps |

- **No `ulid`** (schedule rows are not individually route-bound — availability is
  read and atomically replaced per staff member through staff-scoped routes,
  mirroring §13.7 which defines no ulid). **No `created_by`/`updated_by`/business-
  reason column** — the canonical §13.7 column set is exactly the list above; the
  human-readable `change_reason` is a *command + audit* field (sanitised into the
  append-only audit event), never a stored column. **No operational-mode enum**
  (`appointment`/`walk_in`/`queue`) — queue active-inactive participation is a
  later operational state owned by 16B, not a schedule property.
- **Interval semantics (half-open `[start_time, end_time)`):** the start is
  included and the end excluded, so back-to-back intervals (`09:00–13:00` and
  `13:00–17:00`) do **not** overlap. A cross-midnight working span must be
  normalized into two rows on the two affected weekdays/dates; an ambiguous row
  whose `end_time ≤ start_time` is rejected by CHECK.
- **Recurring vs exception:**
  - `recurring` → `weekday IS NOT NULL` and `date IS NULL` (weekly pattern).
  - `exception` → `date IS NOT NULL` and `weekday IS NULL` (one exact date).
  - Exact-date exceptions take precedence over recurring rows for the same
    interval; within equal specificity, **unavailable wins over available**
    (resolver rule; see below).
- **CHECK constraints:**
  1. `type IN ('recurring','exception')`.
  2. polarity: `(type='recurring' AND weekday IS NOT NULL AND date IS NULL)
     OR (type='exception' AND date IS NOT NULL AND weekday IS NULL)`.
  3. `weekday IS NULL OR (weekday BETWEEN 0 AND 6)`.
  4. `start_time < end_time` (also forbids zero-length and cross-midnight rows).
- **Exclusion constraints (PostgreSQL GiST + `btree_gist`):** same-polarity
  interval overlaps for one staff member are rejected in the DB, not just the
  app. Time-of-day is mapped to an immutable half-open
  `numrange(extract(epoch from start_time), extract(epoch from end_time), '[)')`;
  `available` is included with `=` so only **same-polarity** rows can conflict
  (an unavailable break may legitimately overlap an available working interval —
  it subtracts time):
  - recurring: `EXCLUDE USING gist (staff_profile_id =, weekday =, available =,
    numrange(...) &&) WHERE (type='recurring')` — covers reqs 7 (available) & 8
    (unavailable).
  - exception: `EXCLUDE USING gist (staff_profile_id =, date =, available =,
    numrange(...) &&) WHERE (type='exception')` — covers reqs 9 & 10.
  - Opposite-polarity overlap is permitted (req 11) and resolved deterministically
    (req 12) by the resolver.
- **Indexes:** `(merchant_id, branch_id)` (merchant-first coverage + branch
  personnel listing); `(staff_profile_id, weekday)` (recurring weekday
  resolution); `(staff_profile_id, date)` (exact-date exception resolution +
  current-availability calculation). The two GiST exclusion constraints also index
  the overlap lookups.
- **Composite consistency FKs (same-merchant guaranteed in DB):**
  `(branch_id, merchant_id) → merchant_branches(id, merchant_id)` CASCADE;
  `(staff_profile_id, merchant_id) → staff_profiles(id, merchant_id)` RESTRICT.
  Same-**branch** consistency (`branch_id = staff.primary_branch_id`) is set by
  the domain action (branch derived from the staff profile) and asserted by tests
  — there is no cross-branch availability.
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`; registered in
  `TenantOwnership` (BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS=`branch`).
- **Concurrency / API mutation model:** availability is **atomically replaced**
  per staff member — `ReplaceAvailability` locks the staff member's rows
  (`SELECT … FOR UPDATE` on the staff anchor), validates the full normalized
  payload, deletes the canonical recurring + exception rows and re-inserts the new
  set inside one transaction. Either the complete new schedule commits or the
  complete old schedule is preserved; concurrent replacements cannot interleave
  rows from two schedules. `EmergencyUnavailable` inserts a date-specific
  unavailable exception transactionally. Re-submitting the same normalized payload
  yields the same final schedule (idempotent set-state).
- **Timezone:** weekday/date are resolved in branch business time
  (`Africa/Nairobi`); `start_time`/`end_time` are wall-clock business times. The
  resolver computes the current weekday/date in `Africa/Nairobi`.
- **Derived current state (not persisted):** `AvailabilityResolver` exposes
  `suspended | available | on_break | unavailable | offline`
  (`PersonnelAvailabilityState` enum). `suspended` derives from staff lifecycle
  (`is_active=false`), never from availability rows. `busy` is **not** in 15B (it
  depends on queue/session aggregates — Phases 16B/16C).
- **Mutation:** restricted to HR with `personnel.availability.manage` within HR's
  branch scope; Branch Manager has **read-only** visibility via
  `branch.dashboard.view` and may never mutate.
- **Audit:** `personnel_availability.updated` (atomic replace — one event per
  action, not per row) / `personnel_availability.emergency_unavailable`. Safe
  context only (merchant, branch, staff ulid, actor, recurring/exception counts,
  effective interval/date, **sanitised** change reason); never tokens/sessions/
  phones/emails/internal ids/full bodies.
- **Scheduling validator codes (defined before use):**
  `invalid_schedule_window`, `personnel_inactive`, `personnel_wrong_branch`,
  `personnel_not_eligible`, `personnel_unavailable`, `service_inactive` — emitted
  by `PersonnelSchedulingValidator` (see below). Codes never leak internal ids or
  cross-tenant existence.
- **Factory:** `PersonnelAvailabilityFactory` (recurring + exception states).
- **Tests:** schema/CHECK/exclusion enforcement (incl. raw-SQL bypass of
  Eloquent); recurring/split-shift/break/day-off/exception/emergency resolution;
  `Africa/Nairobi` weekday/date determinism; HR same-branch mutation only; Branch
  Manager read-only; role-boundary denials; atomic replace + concurrency; audit +
  redaction; isolation.

### Phase 16A consumption contract (binding handoff)

`personnel_availability` is the HR-controlled schedule source. **Every Phase 16A
appointment creation, assignment, transfer, and rescheduling action MUST invoke
the Phase 15B `PersonnelSchedulingValidator`** (tenant + branch + staff lifecycle
+ active branch assignment + service status + `service_personnel_eligibility` +
effective availability + interval). Appointment controllers/actions must **not**
duplicate eligibility or availability logic. Phase 16A then adds branch-open,
branch-calendar, and appointment-conflict validation **around** that shared gate.
Phase 15B does not create appointments, so it does not (and must not) claim a
production appointment workflow invokes the validator — only the direct
domain-service tests exercise it in 15B.

---

## `appointments` (16A) — branch-owned

**Purpose.** Front-Office-owned booked appointment for a known client to receive a
service from (optionally) an assigned personnel member at a scheduled time within
a branch. It is the first scheduling aggregate built on the shared
`personnel_availability` substrate. **Phase owner / table owner:** Phase 16A
(Plan §36, §80). It does **not** model walk-ins, queue entries, service sessions,
invoices, payments, or preferred-personnel fees — those are later aggregates.

> **Schema/state reconciliation decision (recorded 2026-06-29).** The Plan §13.7
> schema summary writes `appointments` with a single `staff_profile_id nullable`,
> `scheduled_start/scheduled_end`, and a status CHECK that lists `queued`/
> `in_service` and omits `cancelled_with_reason`. The active v3 **Appointment
> state machine (§25.2)** and the **Phase 16A roadmap (§80)** are more specific
> and control: (1) the Phase-16A-owned state set is exactly `scheduled, confirmed,
> checked_in, rescheduled, cancelled, cancelled_with_reason, no_show` — `queued`
> (16B) and `in_service` (16C) are **deferred** and are added by expand-and-
> contract in their owning phases, so the 16A CHECK constrains to the seven 16A
> states; (2) Scope assigns Front Office both **preferred-personnel selection** and
> assignment/transfer of the **assigned** personnel, so the single summary column
> is realized as two authoritative-equivalent columns
> `preferred_personnel_staff_profile_id` and `assigned_personnel_staff_profile_id`
> (no preferred-personnel **fee** is computed in 16A — Phase 17/20A); (3)
> `scheduled_start/scheduled_end` are realized as `starts_at`/`ends_at`
> (`timestamptz`). These are the §13.7 "authoritative equivalents".

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint PK identity | no | — | internal key; **never** exposed by the API |
| `ulid` | char(26) UNIQUE | no | — | public identifier + searchable reference (`getRouteKeyName()='ulid'`); no separate human-readable numbering scheme exists |
| `merchant_id` | bigint FK→merchants | no | — | tenant owner; `ON DELETE CASCADE` |
| `branch_id` | bigint FK→merchant_branches | no | — | branch owner; `ON DELETE CASCADE` |
| `client_id` | bigint FK→clients | no | — | **RESTRICT** (history preserved); same merchant+branch as the appointment |
| `service_id` | bigint FK→services | no | — | **RESTRICT**; same merchant+branch; supplies the duration snapshot |
| `preferred_personnel_staff_profile_id` | bigint FK→staff_profiles | yes | `null` | **RESTRICT**; optional client preference; same merchant+branch; no fee in 16A |
| `assigned_personnel_staff_profile_id` | bigint FK→staff_profiles | yes | `null` | **RESTRICT**; the personnel member reserved for the appointment; same merchant+branch; participates in the conflict exclusion when set |
| `starts_at` | timestamptz | no | — | appointment start; business-time interpreted in `Africa/Nairobi` |
| `ends_at` | timestamptz | no | — | appointment end; CHECK `starts_at < ends_at`; computed from the service-duration snapshot |
| `status` | varchar(24) | no | `'scheduled'` | CHECK in the seven 16A states (`AppointmentStatus` enum) |
| `cancellation_reason` | text | yes | `null` | required for `checked_in → cancelled_with_reason`; sanitised; never contact data |
| `transfer_reason` | text | yes | `null` | optional reason recorded on a transfer; sanitised |
| `checked_in_at` | timestamptz | yes | `null` | set on `confirmed → checked_in`; CHECK coherence with status |
| `cancelled_at` | timestamptz | yes | `null` | set on any cancel transition (`cancelled`/`cancelled_with_reason`) |
| `no_show_at` | timestamptz | yes | `null` | set on `confirmed → no_show` |
| `created_by` | bigint FK→users | yes | `null` | recording Front Office actor; `ON DELETE SET NULL` |
| `created_at` / `updated_at` | timestamptz | no | — | Eloquent timestamps |

- **Public identifier / reference.** The appointment **ULID** is the public id and
  the searchable reference (filter `q`/binding). The internal bigint `id` is never
  serialized. No sequential or human-readable appointment number is invented (no
  authoritative rule defines one).
- **Schedule interval (`[starts_at, ends_at)`).** Half-open: back-to-back
  appointments (`10:00–10:30` then `10:30–11:00`) do **not** overlap. CHECK
  `starts_at < ends_at` forbids zero-length/inverted intervals. An appointment must
  not cross an unsupported business-date boundary — `CreateAppointment`/
  `RescheduleAppointment` reject an interval whose `Africa/Nairobi` start and end
  fall on different business dates (the shared `PersonnelSchedulingValidator`
  already enforces single-business-date when personnel is assigned; the branch
  schedule validator enforces it for unassigned appointments too).
- **Service-duration snapshot.** `ends_at = starts_at + service.duration_minutes`
  at scheduling/rescheduling time, computed by the backend from the **selected
  service's current `duration_minutes`**. A later change to `services.duration_minutes`
  does **not** mutate an existing appointment (the interval is already persisted).
  The client never supplies `ends_at`.
- **Status / state machine (§25.2; Phase 16A subset).** States: `scheduled` (on
  create), `confirmed`, `checked_in`, `rescheduled`, `cancelled`,
  `cancelled_with_reason`, `no_show`. Legal transitions:
  `scheduled→confirmed|cancelled`; `confirmed→checked_in|rescheduled|cancelled|
  no_show`; `checked_in→cancelled_with_reason`; `rescheduled→scheduled|confirmed`.
  Status is **never** assigned directly — every change runs through a named action
  (`AppointmentStateMachine` guard). An unlisted transition returns
  `422 invalid_state_transition`. Terminal states (`cancelled`,
  `cancelled_with_reason`, `no_show`) cannot be reopened or edited. `queued`
  (16B) and `in_service` (16C) are **not** in 16A.
- **State-transition ownership.** `scheduled→confirmed` and assignment changes are
  established by `AssignAppointment` (and `RescheduleAppointment` while assigned).
  `confirmed→checked_in` by `CheckInAppointment`; `…→no_show` by
  `MarkAppointmentNoShow`; cancels by `CancelAppointment`; reschedule by
  `RescheduleAppointment`. A standalone confirmation action is **not** introduced —
  confirmation is the documented effect of assigning eligible personnel to a
  `scheduled` appointment.
- **Timestamp ↔ status coherence (CHECK).** `checked_in_at` non-null iff the
  appointment has passed through `checked_in`; `no_show_at` non-null iff status =
  `no_show`; `cancelled_at` non-null iff status ∈ {`cancelled`,
  `cancelled_with_reason`}; `cancellation_reason` non-null when status =
  `cancelled_with_reason`.
- **Conflict-participating statuses + exclusion constraint.** Active personnel
  reservations are `scheduled`, `confirmed`, `checked_in`. Terminal/non-reserving
  states (`cancelled`, `cancelled_with_reason`, `no_show`, `rescheduled`) do **not**
  reserve time. PostgreSQL `btree_gist` EXCLUDE prevents two overlapping active
  appointments for the **same assigned personnel member**:
  `EXCLUDE USING gist (assigned_personnel_staff_profile_id WITH =,
  tstzrange(starts_at, ends_at, '[)') WITH &&)
  WHERE (assigned_personnel_staff_profile_id IS NOT NULL
  AND status IN ('scheduled','confirmed','checked_in'))`. It applies only when
  personnel is assigned, uses half-open semantics (back-to-back allowed), rejects
  overlaps for the same personnel, and allows the same interval for **different**
  personnel. Unassigned appointments never trigger a personnel conflict. A
  violation maps to a deterministic **`409 appointment_schedule_conflict`** — no
  SQLSTATE, internal id, or other appointment's hidden data is exposed.
- **CHECK constraints (summary):** (1) status in the seven 16A states; (2)
  `starts_at < ends_at`; (3) `checked_in_at`/`no_show_at`/`cancelled_at` coherence
  with status; (4) `cancellation_reason` present for `cancelled_with_reason`.
- **Indexes:** `(merchant_id, branch_id)` (merchant-first coverage); `(branch_id,
  starts_at, status)` (branch date/calendar listing + upcoming + status filter);
  `(client_id, starts_at)` (client appointment history); `(assigned_personnel_staff_profile_id,
  starts_at)` (assigned-personnel date lookup); `(preferred_personnel_staff_profile_id,
  starts_at)` (preferred-personnel date lookup). The exclusion constraint also
  indexes the overlap lookups.
- **Composite consistency FKs (same-merchant guaranteed in DB):**
  `(branch_id, merchant_id) → merchant_branches(id, merchant_id)` CASCADE;
  `(client_id, merchant_id) → clients(id, merchant_id)` RESTRICT;
  `(service_id, merchant_id) → services(id, merchant_id)` RESTRICT;
  `(assigned_personnel_staff_profile_id, merchant_id) → staff_profiles(id, merchant_id)`
  RESTRICT; `(preferred_personnel_staff_profile_id, merchant_id) →
  staff_profiles(id, merchant_id)` RESTRICT. Same-**branch** consistency
  (client/service/personnel branch == appointment branch) is enforced by the
  `PersonnelSchedulingValidator` (personnel) and the create/reschedule actions
  (client/service) and asserted by tests; the appointment branch is derived from
  context, never from the request body.
- **Ownership:** `BelongsToMerchant` + `BelongsToBranch`; registered in
  `TenantOwnership` (BRANCH_OWNED + COMPOSITE_CONSISTENCY + MODELS=`branch`).
- **Route binding.** `{appointment}` resolves the ULID **inside** tenant + branch
  scope; a foreign-tenant ULID 404s; a same-tenant out-of-branch row follows the
  established BranchScope posture. The internal id is never route-bound.
- **Concurrency / locking.** Every mutation locks the appointment row
  (`SELECT … FOR UPDATE`) before asserting the current state and writing the
  transition, inside one DB transaction; the DB exclusion constraint is the final
  concurrency authority for double-booking (two concurrent overlapping creates →
  one success, one deterministic 409).
- **Branch schedule / calendar.** `AppointmentBranchScheduleValidator` validates
  (for the appointment's `Africa/Nairobi` business date): branch lifecycle active;
  the **full** interval inside operating hours; calendar exceptions
  (`public_holiday`/`special_closure`/`emergency_closure`/`modified_hours`); the
  interval does not cross a closed period. Future-dated appointments validate the
  operating calendar for the **appointment** date (not the branch's current-day
  open state). Same-day **check-in** additionally requires the Branch Day to be
  operationally open (Branch Day machine).
- **Scheduling validator integration (mandatory, no duplication).** Every action
  that schedules or changes assigned personnel — create-with-assignment, assign,
  transfer, assigned reschedule, and the confirmation established by assignment —
  invokes the Phase 15B `PersonnelSchedulingValidator` (merchant/branch/lifecycle/
  active-assignment/service-status/eligibility/availability/interval). No
  appointment controller/request/policy/resource/store reimplements eligibility or
  availability.
- **Branch closure.** `BranchClosureGuard` blocks **branch archival** while any
  active appointment (`scheduled`/`confirmed`/`checked_in`) exists; `CloseBranchDay`
  blocks **day close** while a same-day active appointment exists (Plan §25.2 —
  the appointment day-close guard is flipped on in 16A). Terminal `cancelled`/
  `cancelled_with_reason`/`no_show` appointments never block.
- **Audit.** `appointment.created/assigned/transferred/rescheduled/cancelled/
  checked_in/no_show` (one coherent typed event per action). Safe context:
  merchant, branch, appointment ULID, client ULID, service ULID, actor, previous
  and new state, previous/new assigned-personnel ULIDs (assign/transfer),
  previous/new interval (reschedule), sanitised reason (cancel/transfer), event
  timestamp. Never full phone/email, blind index, tokens, session ids, headers,
  full bodies, or sequential ids.
- **Masking.** API Resources expose the client ULID + name + already-approved
  masked contact summary only (`ClientResource` masking rules). Personnel
  own-scope resources expose the **minimum** client info needed to perform the
  appointment and preserve the existing contact protection; **no contact export
  exists anywhere** (guardrail §6.8).
- **Factory:** `AppointmentFactory` (states: scheduled/confirmed/checked_in,
  cancelled/cancelled_with_reason/no_show/rescheduled; assigned + unassigned).
- **Tests:** schema/CHECK/exclusion enforcement (incl. raw-SQL bypass);
  cross-tenant/cross-branch client/service/personnel rejection; conflict +
  back-to-back + different-personnel + concurrency; full state-machine provider
  (valid + invalid pairs); scheduling-validator integration (each action invokes
  it, none duplicates); API/authorization/role-boundary (Front Office owns
  mutation; Branch Manager read-only; Personnel own-scope; HR/Admin/Finance/Audit/
  Super-Admin denied); branch-closure guard; audit + redaction; isolation.
- **Phase 16B/16C handoff.** `checked_in → queued` (16B) and `checked_in →
  in_service` (16C) extend this aggregate by expand-and-contract (add the states
  to the CHECK, add transition actions, add `queue_entries.appointment_id` /
  `service_sessions` links). 16A creates **no** queue/session row, no
  appointment-to-queue conversion, and no `queued`/`in_service` button.

---

## Deferred (later phases — NOT created in 16A)

`walk_ins`/`queue_entries` (16B), `service_sessions` (16C). Listed in §13.7; each
carries its own data-dictionary entry in its owning phase. Phase 16A must not
create them, nor any walk-in/queue/session workflow, `queued`/`in_service`
appointment state, queue/session/invoice/payment/receipt branch-closure blocker,
preferred-personnel **fee** calculation, or outbound appointment notification.
