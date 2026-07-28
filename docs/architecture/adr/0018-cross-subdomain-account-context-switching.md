# ADR-018 — Cross-Subdomain Account-Context Switching

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime deferred to Phase UI-03).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §5.2, §5.3, §5.4, §28.5; navigation map §2.1 (Cross-Subdomain Session
  Behaviour).
- **Related:** ADR-016, ADR-017, ADR-019; ADR-003 (idempotency and replay protection).

## Context

One human may legitimately hold more than one Servana role — for example a Branch Manager who is
also Front Office cover. ADR-016 puts those roles on different hosts. The user needs a way to move
between them without signing in again, and without carrying the wider role's authority into the
narrower host.

## Problem proven

The binding navigation map (§2.1) requires an explicit **Switch account context** control and states
that context switching "never reuses a broader permission set from the previous domain". It also
requires that session revocation, suspension, deactivation, branch removal, role removal and
permission revocation take effect **immediately across all subdomains**. A naive shared parent-domain
cookie (`.servana.ke`) satisfies convenience and violates both requirements at once: one cookie
carries one permission set everywhere, and revoking it per-host becomes impossible.

## Decision

**Host-scoped sessions, linked by a server-side session family, with a single-use signed handoff.**

1. Each host gets its own browser session cookie scoped to that host — never a shared
   parent-domain cookie.
2. Every host session is linked to a server-side **session family** for the user.
3. Switching contexts follows the plan §5.3 sequence exactly:
   1. request available contexts from the backend;
   2. display only active, authorized contexts;
   3. mint a single-use, short-lived, hashed context-handoff token;
   4. bind it to user, source session family, target account type, target host, environment and
      safe target route;
   5. redirect to the target host;
   6. consume atomically;
   7. re-evaluate membership, role, permission, tenant, branch, MFA and user state from the
      database;
   8. create a target-host session carrying **only** the target context;
   9. write security and audit events;
   10. reject replay, expiry, target substitution and downgraded assurance.
4. Global logout, suspension, role removal, branch removal and permission change act on the session
   family, so they take effect on every host at once.

## Scope

Session driver configuration, cookie scoping, the session-family table and its revocation paths, the
handoff token lifecycle, and the switch-context UI control.

## Non-goals

Silent cross-host single sign-on, a shared parent-domain cookie, long-lived handoff tokens, and any
switch that skips re-evaluation.

## Security implications

The handoff token is a bearer credential and is treated as one: hashed at rest, single-use, consumed
atomically, short-lived, and bound to its exact target. Step 7 is what makes the switch safe — the
target session is built from current database state, so a role revoked one second ago cannot be
carried across. Replay and target substitution are explicit rejection cases, consistent with
ADR-003. Tokens are never logged.

## Accessibility implications

The switch control is keyboard reachable, has an accessible name, announces the destination account,
and the resulting navigation moves focus to the target page heading. It is not a hover-only menu.

## Responsive implications

The control appears in the Super Administrator header shell and in the left navigation / drawer for
every other account, matching the placement rule in ADR-020.

## Operational implications

Adds a session-family record and a short-lived handoff token store (Redis or a table with a pruning
schedule). Security administrators can inspect and revoke active sessions.

## Consequences

- Revocation is genuinely global and provably so.
- A user switching contexts performs one redirect; this is deliberate and visible, not a bug.
- Cookie scope must never be widened later "for convenience" — that single change would undo this
  ADR.

## Rejected alternatives

- **Shared `.servana.ke` cookie.** Rejected: one permission set everywhere, no per-host revocation,
  and accidental permission carryover — the exact failure plan §5.2 names.
- **Re-authenticating with a new Magic Link on every switch.** Rejected: correct but hostile;
  a bound single-use handoff gives the same guarantees without an inbox round trip.
- **Client-side context switch (store the role in local storage).** Rejected: client-asserted role
  is not a context, and it would contradict ADR-017.

## Future implementation owner phase

**UI-03** (authentication, session family, and account switching). UI-00 adopts the decision only.

## Required tests

- Switch happy path across all valid role pairs.
- Replay of a consumed token is rejected.
- Target substitution (token minted for host A, presented at host B) is rejected.
- A role revoked between mint and consume yields no target session.
- Global logout and suspension revoke every host session in the family.
- Cookie-scope test asserting no cookie is issued for the parent domain.

## Traceability links

`SRV-UI-SESSION-001` in `docs/traceability/servana-requirements.csv`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Extends ADR-017. Depends on ADR-016. Complements ADR-019.
