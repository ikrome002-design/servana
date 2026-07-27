# Search Catalogue — Phase 22

> **Authority:** Plan **§68** (Search) and **§80 Phase 22**, under the contact-protection
> invariants of **§64**, **§73** (RK-05 personnel contact exfiltration), **§74** (privacy,
> masking, retention) and **ADR-010**.
>
> This file is the **authoritative declaration** of what Servana search indexes, what it may
> return, and under whose authority. `App\Domain\Search\Support\SearchDocumentCatalogue` is the
> executable form of this table, and `SearchCatalogueParityTest` fails if the two disagree.
>
> Nothing may be indexed or returned that does not appear here. The catalogue is **fail-closed**:
> an unknown type is rejected, a type the caller is not authorized for is silently excluded, and a
> type with no safe target route is not indexed at all.

---

## 1. The two rules that govern every row

**Rule 1 — Search is an aggregator, never an authority (D-22-01).**
`GET /api/v1/search` grants access to nothing. A document type may be returned only after the
server has proven the caller already holds the authority governing that type's own list/detail
route. Search therefore cannot widen anyone's reach, by construction.

**Rule 2 — A search result is never more revealing than the safest existing resource for its type.**
Where the two could differ, search exposes **less**. The concrete consequence, verified per type
in §3: **no search result of any type contains any contact field at all** — there is no `phone`,
`phone_masked`, `phone_last_four`, `email`, or `email_masked` key anywhere in the search response
schema, even for the types whose own canonical Resource already returns masked contact.

---

## 2. What the index physically contains

The Meilisearch document for every type is deliberately minimal:

```
{ "id": "<ulid>", "merchant_id": <int>, "branch_id": <int>, <allowlisted searchable text> }
```

- `merchant_id` / `branch_id` are **filter-only**. They are never displayed and never searchable.
- **Every displayed field is read from PostgreSQL** during the mandatory per-record
  re-authorization pass (§4). The engine returns candidate ULIDs and nothing else.
- Consequence: a field that is only *displayed* (status, dates, money) is **not in the index at
  all**, so the search engine holds strictly less data than the API already returns.

Index documents are built by explicit per-type builders that name every key. No builder calls
`toArray()`, spreads model attributes, or iterates `$fillable` — adding a column to a table can
therefore never silently add it to an index.

---

## 3. Document types

### 3.1 Launch catalogue (indexed / searchable)

| # | `document_type` | Owning domain | Source model / table | Indexed (searchable) fields | Filter-only fields | Response fields (all from PostgreSQL) | Forbidden fields |
|---|---|---|---|---|---|---|---|
| 1 | `client` | Clients | `App\Domain\Clients\Models\Client` / `clients` | `full_name` | `merchant_id`, `branch_id` | `title`=`full_name`, `status`, `date`=`created_at`, `route`, `branch` | `phone_encrypted`, `email_encrypted`, `phone_index`, `phone_last_four`, any blind index, `notes` |
| 2 | `staff` | HR | `App\Domain\Hr\Models\StaffProfile` / `staff_profiles` | `display_name`, `first_name`, `last_name`, `role_title` | `merchant_id`, `branch_id` (← `primary_branch_id`) | `title`=`display_name`, `subtitle`=`role_title`, `status`=`employment_status`, `route`, `branch` | `phone` (plaintext column — deliberately neither indexed nor returned), `profile_photo_path` |
| 3 | `service` | Catalogue | `App\Domain\Catalogue\Models\Service` / `services` | `name` | `merchant_id`, `branch_id` | `title`=`name`, `subtitle`=category name, `status`, `amount`=`price_minor`, `route`, `branch` | `description` (free text, not indexed — precision + no incidental content), `preferred_personnel_fee_minor` |
| 4 | `appointment` | Scheduling | `App\Domain\Scheduling\Models\Appointment` / `appointments` | `reference` (ULID), `client_name`, `service_name` | `merchant_id`, `branch_id` | `title`=service name, `subtitle`=client `full_name`, `status`, `date`=`starts_at`, `route`, `branch` | client contact of any kind, `cancellation_reason`, `transfer_reason` |
| 5 | `queue_entry` | Scheduling | `App\Domain\Scheduling\Models\QueueEntry` / `queue_entries` | `reference` (ULID), `client_name`, `service_name` | `merchant_id`, `branch_id` | `title`=service name, `subtitle`=client `full_name`, `status`, `date`=`queued_at`, `route`, `branch` | client contact of any kind, all `*_reason` free text |
| 6 | `service_session` | Scheduling | `App\Domain\Scheduling\Models\ServiceSession` / `service_sessions` | `reference` (ULID), `client_name`, `service_name` | `merchant_id`, `branch_id` | `title`=service name, `subtitle`=client `full_name`, `status`, `date`=`started_at` ?? `created_at`, `route`, `branch` | client contact of any kind, `notes` (operator free text), `cancellation_reason` |
| 7 | `invoice` | Invoicing | `App\Domain\Invoicing\Models\Invoice` / `invoices` | `invoice_number`, `reference` (ULID), `client_name` | `merchant_id`, `branch_id` | `title`=`invoice_number` (or "Draft invoice"), `subtitle`=client `full_name`, `status`, `amount`=`total_minor`, `date`=`created_at`, `route`, `branch` | client contact, `percentage_fee_config_snapshot`, `void_reason`, `adjustment_reason`, every Wallet/provider field (none exist yet and none may be added) |
| 8 | `receipt` | Receipts | `App\Domain\Receipts\Models\Receipt` / `receipts` | `receipt_number`, `invoice_number`, `reference` (ULID) | `merchant_id`, `branch_id` | `title`=`Receipt #<n>`, `subtitle`=invoice number, `amount`=`amount_minor`, `date`=`created_at`, `route`, `branch` | **client name and client contact** (`ReceiptResource` exposes no client, so search must not either — Rule 2), `components`, provider receipt payloads |

### 3.2 Launch catalogue (own-scope, **not indexed** — PostgreSQL only)

| # | `document_type` | Source | Search mechanism | Response fields | Forbidden |
|---|---|---|---|---|---|
| 9 | `served_client` | `Client` via `App\Domain\Messaging\Sms\Support\ServedClientSelector` (Phase 21S) | **PostgreSQL only.** Name `ILIKE` with `%`/`_`/`\` escaped, inside the 21S `EXISTS` own-scope sub-query. **Never enters Meilisearch.** | `title`=`full_name`, `status`, `route`, `branch` | phone/email in any form, including masked; no `phone_last_four`; no phone lookup path exists |

`served_client` is deliberately **not** an index. Indexing it would require a
`served_by_staff_profile_ids` array in a client document — derived, high-churn data whose staleness
would be an own-scope leak. The 21S selector is already the authoritative, tested definition of
"personally served", so search reuses it verbatim rather than mirroring it into an index.

### 3.3 Authority, scope and target route per type

| `document_type` | Authority (existing keys only — no new key) | Anchor | Scope | Sync trigger | Target route |
|---|---|---|---|---|---|
| `client` | `ClientPolicy::viewAny` (= `client.view`) **AND** `front_office.search` | `GET /api/v1/clients?q=` requires both today (`ClientController::index`) | merchant + branch | Scout observer (queued) | `clients.show` |
| `staff` | `staff.view` (Phase 23; was `branches.manage_users_lifecycle` **OR** `staff.suspend`) | `StaffProfilePolicy::view`, the authority on `GET /api/v1/staff/{staff}` **and** `GET /api/v1/staff` | merchant + branch (`primary_branch_id`) | Scout observer (queued) | `staff.show` |
| `service` | `ServicePolicy::viewAny` (= `service.view`) | `GET /api/v1/services` | merchant + branch | Scout observer (queued) | `services.show` |
| `appointment` | `AppointmentPolicy::viewAny` (`appointment.view` **OR** `branch.dashboard.view`) | `GET /api/v1/appointments` | merchant + branch | Scout observer (queued) | `appointments.show` |
| `queue_entry` | `QueueEntryPolicy::viewAny` (`queue.view` **OR** `branch.dashboard.view`) | `GET /api/v1/queue-entries` | merchant + branch | Scout observer (queued) | `queue.show` |
| `service_session` | `ServiceSessionPolicy::viewAny` (= `service_session.view`) | `GET /api/v1/service-sessions` | merchant + branch | Scout observer (queued) | `service-sessions.show` |
| `invoice` | `InvoicePolicy::viewAny` (= `invoice.view`) | `GET /api/v1/invoices` | merchant + branch | Scout observer (queued) | `invoices.show` |
| `receipt` | `ReceiptPolicy::viewAny` (= `receipt.view`) | `GET /api/v1/receipts` | merchant + branch | Scout observer (queued) | `receipts.show` |
| `served_client` | `personnel.my_served_clients.view` | `GET /api/v1/personnel/me/served-clients/sms` (Phase 21S) | **own** (acting staff profile) + merchant + branch | n/a (no index) | none — own-scope results are informational; the 21S screen is the workflow surface |

Notes on two rows that are not a plain `viewAny`:

- **`client`** additionally requires `front_office.search` because the live client list treats
  *searching* as a capability distinct from *listing*
  (`ClientController::index` aborts 403 on `q` without it). Search honours that split exactly: a
  role that may list clients but not search them gets no `client` results.
- **`staff`** anchored on the **detail-route** authority (`view`/`manage`) during Phase 22, because
  `StaffProfilePolicy` had no `viewAny` and `GET /api/v1/staff` performed no `authorize()` call at
  all. Phase 22 recorded the unauthorized `staff.index` route and the `staff.view` key still
  carrying `owning_phase: Phase 20F` (while 20F was `verified_complete`) as **pre-existing**
  conditions outside its scope.

  **Phase 23 §14.1 resolved both.** `staff.view` is active and HR-only, and it now authorizes both
  `staff.index` (via the new `StaffProfilePolicy::viewAny`) and `staff.show`. The catalogue anchor
  is therefore `staff.view` — exactly the authority governing this type's own list and detail
  routes, which is what Rule 2 asks for. This **tightens** the type: a Merchant Admin holding only
  the legacy `branches.manage_users_lifecycle` can no longer open `hr.staff-profile` (the
  `routeName` every staff result links to), so it no longer receives staff results either — search
  is never a wider surface than the page it points at. `canSearch()` and `passesRecheck()` are now
  provably the same authority.

### 3.4 Rate limiter, index name and commands (all types)

| Property | Value |
|---|---|
| Rate limiter | `throttle:search` — the pre-existing `RateLimiter::for('search', …)` in `AppServiceProvider`, 60/min keyed per authenticated principal (defined since Phase 10, unused until now) |
| Index name | `config('scout.prefix')` + one of `clients`, `staff_profiles`, `services`, `appointments`, `queue_entries`, `service_sessions`, `invoices`, `receipts`. `scout.prefix` is environment-derived, so environments can never share an index |
| Backfill | `php artisan servana:search-reindex [--type=…] [--chunk=…]` — idempotent, chunked, forward-only |
| Drift check | `php artisan servana:search-verify-counts [--type=…]` — per-index document count vs the authoritative PostgreSQL count |
| Tests | see §5 |

---

## 4. Effective-authority algorithm

```
requested_types  = validate_allowlisted_types(request.types)      // unknown → 422
candidate_types  = requested_types ?: all_catalogue_types

effective_types  = candidate_types.filter(type =>
       catalogue.isLive(type)
    && catalogue.hasSafeTargetRoute(type)
    && authority.canSearchType(context, type)                     // existing keys only
)

if effective_types is empty  →  200 { data: [], meta: { types: [] } }      // never 403
```

For each effective type the server builds the filters itself:

```
merchant_id == context.merchantId()                                     always
branch_id   in context.branchIds()                                      when branch-owned
staff_profile_id == context.actingStaffProfile().id                     when own-scope
+ the status/visibility predicates of the type's own canonical route
```

None of these is accepted from the browser in any form (§`search-security.md` §2).

**Three independent layers must all agree before a row is returned:**

1. **Engine filter** — the Meilisearch query itself carries
   `merchant_id = … AND branch_id IN [ … ]`, built from server state through a typed builder that
   emits only integers.
2. **SQL filter** — the candidate ULIDs are re-resolved through the model's own tenant-scoped
   Eloquent query (`BelongsToMerchant` + `BelongsToBranch` global scopes still applied), so an
   engine result outside scope simply does not resolve.
3. **Per-record policy re-check** — every surviving record is passed through
   `Gate::allows('view', $record)`, the *same* policy call its own detail route makes.

Security therefore never depends on Meilisearch being correctly filtered. The engine is an
accelerator; PostgreSQL and the policies are the authority. Layer 1 is nevertheless mandatory —
the defensive layers are *in addition to* engine filters, never instead of them.

### 4.1 Two consequences of the two-stage design, stated plainly

**`sort=recent` means "most recent among the best matches", not "most recent overall."** When the
engine supplies candidates it returns a bounded, relevance-ranked set (`limit × 5`, capped at 100);
the PostgreSQL `order by created_at desc` then applies *within* that set. Ordering the whole corpus by
date would require an engine-side sort, and `sortableAttributes` is deliberately empty on every index
so that no caller-supplied token can ever reach a sort expression. For a jump-to aggregator this is the
right trade; a caller who needs a true date ordering uses the type's own canonical list route, which
paginates and sorts in SQL over the full set (Plan §23).

**A page can under-fill rather than over-fill.** The per-record policy pass runs *after* the fetch, so
a type over-fetches a bounded multiple (5×, capped at 100 rows) and stops once `limit` results have
passed. If an unusually large share of candidates fails its policy check, the page returns fewer than
`limit` rows. That is the correct direction to fail: the cap is on how much is read, never on how much
is proven, so a crafted query can never widen the read.

---

## 5. Exclusions

### 5.1 Integration payload tables — never indexed

`referral_snapshots`, `re_outbound_events`, `re_event_deliveries`, `sms_delivery_attempts`,
`personnel_sms_recipients`, every future Wallet table (`wallet_*`, payment attempts, webhook
inbox, reconciliation exceptions), and every provider callback/inbox table. They contain no
searchable business content, and Plan §80 Phase 22 records the exclusion explicitly, consistent
with the R&E rule never to index integration payloads. `SearchIntegrationExclusionTest` asserts
that no `Searchable` model resolves to any of these tables.

### 5.2 Contact-export material — never indexed, never returned

`phone_encrypted`, `email_encrypted`, `phone_index`, any blind-index or HMAC value,
`phone_last_four`, `StaffProfile::phone`, SMS provider destinations, and SMS message bodies
(encrypted or not). No snippet rule admits them: search has **no** `snippet` source that reads a
contact column, and the response schema has no contact key to put them in.

### 5.3 Types deferred out of the launch catalogue (with reason)

| Candidate | Decision | Reason |
|---|---|---|
| Payment records / validated references | **deferred** | The reference itself is the sensitive artefact (Plan §24.5 redaction; `PaymentReferenceCheck` exists precisely to avoid oracles). A search box over payment references is an enumeration surface with no proven operator need. |
| Refunds, cash-ups, finance disputes, finance exports, period locks | **deferred** | Each is reached from a Finance work queue, not by free-text search; several have no free-text field at all. |
| Compensation plans, commission rules/ledgers, payout runs, earnings queries | **deferred** | Money-movement adjacent and role-partitioned (20F–20H). Their own screens are the correct entry point; a cross-type aggregator adds risk without demand. |
| Audit logs / flagged events | **deferred** | Plan §70/§19 require field-level masking *per permission* on audit reads. Reproducing that masking inside a search result is a distinct piece of work and Audit already has its own filtered read API. |
| Personnel SMS campaigns | **deferred** | §5.2. A campaign is identified by its recipients; making it searchable trends toward the contact surface ADR-010 forbids. |
| Merchants / merchant users / branches (platform side) | **deferred** | Platform-side search is a Super-Admin governance surface, not merchant search; Plan §68 scopes Phase 22 to tenant/branch search. |
| Client consents | **deferred** | Not independently addressable — consent is a field of the client detail screen, so it has no safe target route of its own. |

Each deferred type has **no index, no catalogue row, and no code path**. Adding one later requires
a new catalogue row, its own authority anchor, and its own isolation/masking tests.

### 5.4 Capabilities Phase 22 does not build

No search-result export, download, print, clipboard-copy or CSV/XLSX/PDF of results in any form;
no reporting, analytics, notification or scheduling behaviour; no global unscoped search; no
frontend Meilisearch client and no frontend-held search key; no Wallet / Daraja / STK / PayBill /
Till / C2B search; no R&E reward, referrer, campaign or qualification search.
