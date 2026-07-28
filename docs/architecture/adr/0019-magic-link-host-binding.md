# ADR-019 — Magic Link Host Binding

- **Status:** Accepted (Phase UI-00 plan-adoption PR; runtime deferred to Phase UI-03).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §5.1, §18.5, §28.5; Servana Software Development Plan §9 rule 9
  (Magic Links hashed at rest, single-use atomic consume, 15-minute expiry, all seven Scope §2.3
  checks at request **and** consume time).
- **Related:** ADR-016, ADR-017, ADR-018.

## Context

Servana authentication is Magic Link only — no passwords exist. The shipped implementation
(Phase 5, hardened in R3/R6) already hashes tokens at rest, consumes them atomically once, expires
them in 15 minutes, and runs the seven eligibility checks at both request and consume time.

Moving to eight hosts adds a dimension the shipped token does not carry: *which account experience
was this link issued for*.

## Problem proven

Today a Magic Link is bound to identity and lifetime but not to a host or account type. Under
ADR-016 that becomes exploitable: a link requested from `staff.servana.ke` would consume just as
well at `finance.servana.ke`. The plan states the requirement as a flat prohibition (§5.1): "A
Finance Magic Link must not establish a Personnel session. A production Magic Link must not work in
staging."

Environment bleed is the same class of defect: without an environment binding, a production link
consumed against staging would mint a real session in the wrong environment.

## Decision

**Every Magic Link is bound at issue time and re-verified at consume time to all of:**

- normalized email;
- user;
- account type;
- intended host;
- environment;
- safe post-auth redirect;
- audience;
- creation event;
- expiry;
- single-use state.

Rules:

1. A modified host, redirect, account type, or environment **invalidates the link**. The response is
   the existing uniform failure — invalidation must not become an oracle that reveals which field
   was wrong.
2. The bound values are covered by the stored hash, so they cannot be edited in transit.
3. The post-auth redirect is validated against the target host's own safe-route allowlist; an
   off-host or unlisted redirect invalidates the link rather than being silently dropped.
4. All existing guarantees are preserved unchanged: hashed at rest, single-use atomic consume,
   15-minute expiry, seven checks at request and consume time, instant revocation on suspension, and
   no token value ever logged.

## Scope

Magic Link issuance, storage, consumption, the request and consume endpoints, and the emailed link.

## Non-goals

Changing the passwordless model, the expiry window, the uniform-response contract, or the seven
eligibility checks. This ADR *adds* binding; it removes nothing.

## Security implications

Closes cross-host credential substitution and cross-environment replay. Host binding here is an
anti-substitution control on the credential — it is not a permission grant, and ADR-017 still
governs: consuming a correctly bound link grants exactly the permissions the database says the user
has, no more.

Invalidation reasons are not disclosed. Suspension continues to revoke unused links instantly.

## Accessibility implications

The failure state is a real page explaining that the link is no longer valid and offering a fresh
request, with a focusable heading and no reliance on colour alone.

## Responsive implications

The request, check-email, and consume screens follow the standard responsive contract on all eight
hosts.

## Operational implications

Requires an expand-only migration adding the binding columns to the existing Magic Link table
(never editing a shipped migration — Plan guardrail 12). Emailed link hosts become environment
configuration.

## Consequences

- A link is usable exactly once, on one host, in one environment, for one account type.
- Users with two roles need two links, or one link plus a context switch (ADR-018). This is
  deliberate.
- Operators must keep per-environment link hosts configured correctly or links will correctly fail.

## Rejected alternatives

- **Bind only the account type, not the host.** Rejected: leaves the staging/production bleed open.
- **Validate the host at consume time from the request only, without binding it into the hash.**
  Rejected: an unbound field is an editable field.
- **Redirect a link consumed on the wrong host to the right one.** Rejected: turns a security
  failure into a convenience feature and leaks the account type of an email address.

## Future implementation owner phase

**UI-03** (authentication, session family, and account switching). UI-00 adopts the decision only;
no Magic Link code, schema, or behaviour changes in this phase.

## Required tests

- A Finance-issued link consumed on the Personnel host is rejected and creates no session.
- A link bound to production is rejected in staging.
- A tampered redirect, account type, or host invalidates the link.
- Uniform-response tests proving the invalidation reason is not disclosed.
- Regression: hashing, single-use atomic consume, 15-minute expiry, and the seven checks still hold.

## Traceability links

`SRV-UI-AUTH-001` in `docs/traceability/servana-requirements.csv`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Extends the Phase 5 Magic Link design. Complements ADR-018. Governed by ADR-017.
