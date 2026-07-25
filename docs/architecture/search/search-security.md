# Search Security and Privacy — Phase 22

> **Authority:** Plan **§68**, **§80 Phase 22**; threat model **§73** (cross-tenant/branch access,
> over-privileged staff, personnel contact extraction — RK-05); privacy **§74**; log redaction
> **§24.5**; **ADR-010** (personnel contact protection is non-existent capability, not merely
> unauthorized).

---

## 1. Threat model applied to search

| # | Threat | Control | Proven by |
|---|---|---|---|
| T1 | Cross-tenant result leakage | Three independent layers: engine `merchant_id` filter, `BelongsToMerchant` global scope on re-resolution, per-record `Gate::allows('view')` | `SearchTenantIsolationTest` |
| T2 | Cross-branch result leakage | Engine `branch_id IN […]` from `TenantContext::branchIds()`, `BelongsToBranch` global scope, per-record policy | `SearchTenantIsolationTest` |
| T3 | Own-scope leakage (personnel reading another personnel member's served clients) | `served_client` runs only through the 21S `ServedClientSelector` with the staff profile derived from the authenticated membership; no request field can name a staff profile | `SearchServedClientOwnScopeTest` |
| T4 | Permission-filter bypass (search as a backdoor around type authority) | Search grants nothing (D-22-01); a type is admitted only after its existing authority passes, and every record re-passes its own detail-route policy | `SearchPermissionFilteringTest` |
| T5 | Frontend-held search key | The SPA calls only `/api/v1/search`; `MEILISEARCH_KEY`/`MEILISEARCH_HOST` exist solely in server config and are never published to a Resource, OpenAPI, `api.ts` or the bundle | `SearchScopePurityTest`, `search.spec.ts`, `phase-22-search.spec.ts` |
| T6 | Unscoped cached result later filtered client-side | Nothing is cached. No result set is persisted to `localStorage`/`sessionStorage`, and the store clears on any tenant/branch/user context change | `searchStore.spec.ts`, `phase-22-search.spec.ts` |
| T7 | Full phone / email / encrypted-contact leakage | The search response schema **has no contact key at all**; no index document contains a contact column; the model `$hidden` lists remain in force | `SearchIndexDocumentTest`, `SearchApiTest` |
| T8 | Searching by contact data becoming contact export | Only a *complete* phone triggers exact lookup, and it returns at most the matching client's name — no number, no list. Partial-phone fragments are not searchable anywhere. `served_client` search is name-only | `SearchPhoneLookupTest`, `SearchServedClientOwnScopeTest` |
| T9 | Indexing integration payloads | No `Searchable` model resolves to an integration table; asserted structurally rather than by convention | `SearchIntegrationExclusionTest` |
| T10 | Forged user filters overriding server filters | The Form Request **rejects** (422) every scope/permission/engine field; server filters are built from `TenantContext` only | `SearchInjectionSafetyTest` |
| T11 | Injection into filter/sort syntax | Engine filters are emitted by a typed builder that accepts only integers; `sort` and `types` are allowlisted enums; `q` never reaches a filter expression or a SQL `order by` | `SearchInjectionSafetyTest` |
| T12 | Enumeration by result probing | `throttle:search` (60/min per principal); a zero-result query is indistinguishable from an unauthorized one (both are `200 { data: [] }`) | `SearchRateLimitTest`, `SearchApiTest` |

---

## 2. Request contract — what the browser may and may not send

**Allowed (allowlist; anything else is rejected):**

| Field | Rule |
|---|---|
| `q` | required, string, trimmed, min 2, max 120 |
| `types[]` | optional, each value ∈ the live catalogue types |
| `branch_ulids[]` | optional, each a ULID; **intersected** with `TenantContext::branchIds()` — it can only ever narrow, never widen |
| `sort` | optional, ∈ `relevance` \| `recent` |
| `limit` | optional, integer, 1–20 (per type; default 5) |

**Rejected with 422 (`ProhibitedIf`-style explicit denial, not silent ignoring):**

`merchant_id`, `merchant_ulid`, `branch_id`, `branch_ids`, `staff_profile_id`,
`staff_profile_ulid`, `permission`, `permissions`, `role`, `filter`, `raw_filter`, `filters`,
`index`, `api_key`, `include_sensitive`, `include_phone`, `include_email`, `export`, `download`,
`print`, `copy`.

Strict rejection is chosen over silent ignoring because a 422 is *evidence* that the field has no
effect, whereas silence is indistinguishable from a field that works. `SearchInjectionSafetyTest`
additionally proves that even if one were tolerated it could not change the executed query.

## 3. Response contract

```
data: [ { type, ulid, title, subtitle?, snippet?, status?, date?, amount?, route{name,params}, branch?{ulid,name} } ]
meta: { query, types[], limit, next_cursor }
```

There is **no** `phone`, `phone_masked`, `phone_last_four`, `email`, `email_masked`,
`phone_index`, provider reference, integration payload, audit before/after value, or secret key in
this schema — not conditionally, not per role, not ever. `snippet` is only ever populated from a
field that already appears in §3 of `search-catalogue.md` as searchable, and no such field is a
contact column, so `snippet` cannot carry contact data.

`next_cursor` is always `null`: the aggregator returns a bounded top-N **per type**, and deep
pagination remains the job of each type's own canonical list route, which already paginates
(Plan §23). This is decision **D-22-04**.

## 4. Exact phone lookup (authorized, narrow, non-enumerating)

Implemented **only** in this shape:

1. The browser submits `q` and nothing else.
2. `SearchPhoneCandidate::detect($q)` decides whether `q` is a **complete** phone number. It
   accepts only `+?254[17]\d{8}`, `0[17]\d{8}`, `[17]\d{8}`, and explicit international `+\d{10,15}`.
   Anything shorter or containing letters is **not** phone-like. Partial fragments therefore never
   reach the phone path — and, because no phone digits are indexed anywhere, they cannot match a
   phone through the text path either.
3. A phone-like `q` **never reaches Meilisearch**. The engine is not queried at all on that path,
   for any type.
4. The server normalizes through the existing `PhoneNumberNormalizer` and computes the existing
   keyed HMAC blind index through `ClientContactIndex::for()`.
5. Exact PostgreSQL lookup `where phone_index = :digest`, inside the `BelongsToMerchant` +
   `BelongsToBranch` global scopes, and only for a caller holding both `client.view` and
   `front_office.search`.
6. The result carries the client's **name only** — no phone in any form, masked or otherwise.
7. `served_client` is excluded from the phone path entirely: personnel served-client search is
   name-only (Phase 21S; ADR-010), because a phone lookup there would confirm whether a guessed
   number belongs to a client they served.
8. Rate limiting is the enumeration control (`throttle:search`, 60/min per principal), and a
   miss is indistinguishable from an unauthorized query (both `200 { data: [] }`).

No decrypted-phone scan exists anywhere. If `ClientContactIndex` were unavailable the phone path
fails closed to the empty result rather than falling back to any weaker mechanism.

## 5. Key handling

`MEILISEARCH_HOST` and `MEILISEARCH_KEY` are read only by `config/scout.php`, consumed only by the
Scout engine inside the API container and the queue workers. They appear in `.env.example` (dev
placeholder), `docker-compose.yml` (dev service), and the CI workflow (ephemeral service) — never
in a Vite-exposed variable (`VITE_*`), never in a Resource, never in `docs/api/openapi.json`,
never in `resources/spa/src/types/generated/api.ts`, and never in a log line. `gitleaks` runs over
the tree as a release gate, and `SearchScopePurityTest` asserts the SPA source tree contains no
Meilisearch host, key, or index identifier.

## 6. Logging

Search logs the *shape* of a query, never its content: type set, effective type count, result
count, duration. The raw `q` is **not** logged, because a phone-like `q` would be a phone number
in a log line (Plan §24.5). The blind-index digest is likewise never logged.

## 7. Accessibility and UI safety gates

Keyboard-operable open/focus/submit/dismiss; visible focus rings; 44px targets; results announced
to assistive technology; axe serious/critical **0** in light and dark; usable at 360 / 768 / 1280
and at 200% zoom; no horizontal page scroll. No export, download, print or clipboard control
exists on the search surface, and none may be added (ADR-010; Plan §19.4 non-overridable).
