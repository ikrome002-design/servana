# ADR-016 — Eight Account-Specific Hosts Served by One Servana Application

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime deferred to Phase UI-02).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §4.1–§4.7, §6.2, §28.1; navigation map §2 (Account Domains and
  Authenticated Home Routes).
- **Related:** ADR-017 (host context vs authorization context), ADR-018 (account switching),
  ADR-019 (Magic Link host binding), ADR-002 (tenancy enforcement).

## Context

Servana serves eight distinct account experiences. The navigation map assigns each one a canonical
production host and an authenticated home of `/dashboard` on that host. The shipped application
serves every role from a single origin with a shared shell and a role resolver.

| Account | Canonical host |
|---|---|
| Super Administrator | `citrus.servana.ke` |
| Merchant Administrator | `servana.ke` |
| Branch | `branch.servana.ke` |
| Finance | `finance.servana.ke` |
| Human Resource | `hr.servana.ke` |
| Front Office | `office.servana.ke` |
| Personnel | `staff.servana.ke` |
| Audit | `audit.servana.ke` |

## Problem proven

`docs/frontend/source-inventory/navigation-map.json` parses 160 authenticated pages across the eight
accounts, and every one of them carries a host in the binding specification. Eight accounts each
need `/dashboard`, `/get-started`, `/account`, `/notifications`, `/reports` and `/audit` at
*different* meanings. Serving them from one origin forces either route-prefix collisions or a single
shared information architecture — the exact outcome UI/UX plan §0 prohibits ("the IDE must not use
one landing page, one dashboard, one navigation registry, or one role shell as the visible final
experience for all account types").

## Decision

Eight canonical hosts resolve to **one** Laravel + Vue application and one database.

1. A typed account-host registry maps host → account type, and is the only place the mapping lives.
2. The reverse proxy accepts only allowlisted hosts, preserves the original host and scheme, and
   rejects unknown hosts with a safe response (plan §4.7).
3. The backend resolves the account host per request and uses it to select the *experience*.
4. Local development uses the `*.servana.test` set; staging uses an environment-configured suffix.
   The staging suffix is configuration, never hard-coded business logic.
5. Partner webhook and machine-integration routes stay independent of browser account-host
   assumptions, so Wallet and R&E traffic is unaffected by browser host rules.

## Scope

Host registry, host resolution, reverse-proxy allowlisting, DNS/TLS requirements, Vite HMR host
configuration, and per-host public entry points.

## Non-goals

Separate deployments, separate databases, separate codebases, per-host feature divergence in
business rules, and any host-derived permission.

## Security implications

The host is attacker-controlled input. It is validated against the allowlist and never trusted as
proof of anything (ADR-017). Unknown hosts get a safe non-enumerating response. Certificate scope is
limited to `servana.ke` and its subdomains.

## Accessibility implications

None directly. Each host must still meet the same accessibility gates; a host does not create an
exemption.

## Responsive implications

None directly. All eight hosts share the same responsive contract (plan §13).

## Operational implications

Requires an apex record plus seven subdomain records, a wildcard or per-subdomain certificate with
automated renewal, HTTPS-only redirects, host-specific health checks, and HSTS after production
validation. One application image continues to serve every host.

## Consequences

- One deployment, one migration set, one test suite.
- Route paths may repeat across hosts (`/dashboard` on all eight) without collision, because the
  host disambiguates the experience.
- Adding an account type becomes a registry entry plus DNS, not a new deployment.
- Cross-host navigation needs an explicit, authenticated handoff (ADR-018).

## Rejected alternatives

- **Single origin with role path prefixes** (`/finance/...`). Rejected: contradicts the binding
  navigation map's routes and re-creates the shared-shell outcome plan §0 prohibits.
- **Eight separate deployments.** Rejected: eight times the operational surface for one product,
  with no tenancy or security benefit — isolation already comes from `BelongsToMerchant`.
- **Query-parameter or header role selection.** Rejected: not a stable, linkable, cacheable identity
  and trivially manipulated.

## Future implementation owner phase

**UI-02** (multi-host foundation: host registry, backend resolver, proxy, local/staging hosts).
UI-00 adopts the decision only; no runtime host resolution is added here.

## Required tests

- Host-resolution unit tests for all eight hosts plus unknown-host rejection.
- Feature tests proving each host serves its own public entry points (plan §4.2).
- A test proving the staging suffix comes from configuration, not a literal.
- Non-regression: partner webhook routes remain reachable independent of account hosts.

## Traceability links

`SRV-UI-HOST-001` in `docs/traceability/servana-requirements.csv`;
`docs/frontend/source-inventory/navigation-map.json`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Supersedes nothing. Extends ADR-002 (tenancy is enforced in the database and query scope, never by
host).
