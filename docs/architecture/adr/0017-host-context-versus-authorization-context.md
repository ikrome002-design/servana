# ADR-017 — Host Context Versus Authorization Context

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime deferred to Phase UI-02/UI-03).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §4.3, §18.1–§18.5, §28.5; navigation map §1 rule 4; Servana Software
  Development Plan §9 (backend authorization is the security boundary).
- **Related:** ADR-016 (eight hosts), ADR-018 (account switching), ADR-002 (tenancy enforcement).

## Context

ADR-016 introduces eight account hosts. Introducing a host that "means" an account type creates an
obvious and dangerous shortcut: treating arrival on `finance.servana.ke` as evidence that the
request is a Finance request.

## Problem proven

The `Host` header is client-supplied. Any client can send any host to any origin. Meanwhile the
existing backend already enforces authorization correctly through Sanctum, Form Requests, Policies,
`BelongsToMerchant`/`BelongsToBranch`, and the permission matrix in `docs/auth/permission-matrix.yaml`.
Introducing host-derived authorization would create a second, weaker authority for the same
decision — and the weaker one would be reachable first, in the HTTP layer, before any policy runs.

The binding navigation map states the rule directly (§1 rule 4): navigation visibility never
substitutes for authorization; tenant scope, branch scope, own-scope, permissions, entitlements,
billing status, period locks, masking, maker/checker and MFA/step-up are enforced server-side.

## Decision

**The hostname selects the experience. It never grants, implies, or narrows a permission.**

1. The host selects which account experience to render: layout family, navigation registry, landing
   content, and route table.
2. Every protected request re-evaluates identity, membership, role, permission, tenant scope, branch
   scope, own-scope, MFA state and user status from the authenticated session and current database
   state — exactly as today.
3. The host is **never** an input to a Policy, a Gate, a query scope, or a permission check.
4. A host/role mismatch is an authorization failure handled by the existing rules, not a redirect to
   a broader role.
5. Frontend visibility driven by host remains UX only, consistent with the shipped guardrail that
   "frontend checks are UX only".

## Scope

Host middleware, route registration, policies, gates, query scopes, and the frontend account
resolver.

## Non-goals

Removing or weakening any existing server-side check; introducing a host claim into tokens,
sessions, or policies as an authorization factor. (Host *binding* on a Magic Link — ADR-019 — is an
anti-substitution control on the credential, not a grant of permission.)

## Security implications

This is the central security decision of the multi-host programme. It preserves the single
authorization boundary that Phases 5–23 built and tested. A host-derived shortcut would be a
privilege-escalation primitive: send `citrus.servana.ke` as the `Host` header and inherit platform
scope. This ADR forbids that class of bug by construction.

Unauthorized cross-host deep links return a role-safe access-denied state or a non-enumerating
`404` per the resource policy, write a security event, and never silently redirect to a broader role
(plan §5.4).

## Accessibility implications

The access-denied state is a real, focusable, screen-reader-announced page with a clear heading, not
a blank redirect. It offers logout and switching only to genuinely active contexts.

## Responsive implications

None. Denial states follow the same responsive contract as any other page.

## Operational implications

Host allowlisting stays an infrastructure concern. Security events on cross-host denial feed the
existing audit log; no new transport is introduced.

## Consequences

- Adding a host can never widen access.
- A misconfigured proxy causes a *usability* failure (wrong experience), never a security failure.
- Tests can assert the negative directly: the same session on a different host gets the same
  authorization answer.

## Rejected alternatives

- **Host-scoped permission sets resolved in middleware.** Rejected: duplicates the permission
  matrix, and the duplicate would drift.
- **Trusting a proxy-injected `X-Account-Type` header.** Rejected: still client-controllable at the
  origin, and it moves a security decision into infrastructure configuration.
- **Redirecting a mismatched user to "their" host automatically.** Rejected: plan §5.4 forbids a
  silent redirect to a broader role, and it leaks which host the account belongs to.

## Future implementation owner phase

**UI-02** for host resolution; **UI-03** for the session and denial flows. UI-00 adopts the rule
only.

## Required tests

- A test proving a Personnel session on `finance.servana.ke` receives no Finance permission.
- A test proving policies and gates receive no host argument.
- Cross-host deep-link denial tests asserting non-enumeration and a written security event.
- A source guard that the host resolver's output never reaches a permission check.

## Traceability links

`SRV-UI-HOST-002` in `docs/traceability/servana-requirements.csv`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Reinforces ADR-002 and ADR-016. Supersedes nothing.
