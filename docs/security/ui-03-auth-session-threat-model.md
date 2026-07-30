# Phase UI-03 — Authentication, Session Family and Account-Switching Threat Model

**Phase:** UI-03 · **Branch:** `phase-ui-03-auth-session-account-switching` · **Base:** `fb64ba67c8555ab68aff4f64d97a4d10e4eeab0f`
**Governing sources:** UI/UX plan §5.1–§5.4, §18.1–§18.7; ADR-016, ADR-017, ADR-018, ADR-019;
`Servana Software Development Plan.md` §9 (authentication), §18 (MFA), §79 R6 (revocation), §70 (audit).

This document is written **before** the implementation it protects, and each control names the
automated test that proves it. A control with no test is not a control.

---

## 1. Assets

| Asset | Why it matters |
|---|---|
| A1 — Magic Link raw token | The only authentication credential in Servana. Possession = a session. |
| A2 — Context-handoff raw token | A bearer credential that mints a session on a *different* host. |
| A3 — Browser session cookie | Sanctum SPA stateful session; the live authenticated identity. |
| A4 — Session family | The server-side link that makes revocation global instead of per-host. |
| A5 — Target account context | Membership, role, merchant, branch — the authority a target session carries. |
| A6 — MFA session assurance | `mfa_verified_at` in the Laravel session; gates privileged accounts. |
| A7 — Audit trail | Append-only hash-chained record of every authentication transition. |

## 2. Attackers

| Id | Attacker | Capability |
|---|---|---|
| T1 | Network/mailbox observer | Reads a Magic Link email (compromised inbox, forwarded mail, shared device). |
| T2 | Malicious low-privilege insider | Holds a real Personnel/Front Office/Audit session and wants a broader one. |
| T3 | Unauthenticated remote | Can send arbitrary requests, arbitrary `Host` and `X-Forwarded-*` headers. |
| T4 | Malicious site the victim visits | Can issue cross-site requests and read `Referer` on outbound navigation. |
| T5 | Suspended/removed principal | Held valid credentials one second ago; must lose them now. |
| T6 | Log/monitoring reader | Legitimately reads application logs, audit rows, screenshots and proof files. |

---

## 3. Threats, controls and proofs

### TM-01 — Magic Link replayed from a compromised mailbox

- **Attacker/Precondition:** T1; the link has been emailed and not yet used.
- **Asset:** A1 → A3.
- **Abuse path:** Attacker clicks the link before the owner, or re-clicks it afterwards.
- **Control:** Unchanged Phase 5 substrate — 64 random bytes, SHA-256 at rest, 15-minute expiry,
  atomic conditional `UPDATE` consume that must affect exactly one row. UI-03 adds nothing that
  weakens it, and adds the requirement that the row be *fully bound* to be usable at all.
- **Test:** `MagicLinkReplayConcurrencyTest` (replay, expiry, concurrent double-consume);
  `MagicLinkTokenSecurityTest` (hash at rest).
- **Residual risk:** A first-click attacker with mailbox access still wins. That is inherent to
  passwordless email auth and is out of UI-03 scope (WebAuthn is named as a future enhancement).

### TM-02 — Cross-host Magic Link substitution

- **Attacker/Precondition:** T1/T2; a link issued for one account host.
- **Asset:** A1 → A5.
- **Abuse path:** A link requested on `staff.servana.ke` is presented on `finance.servana.ke`,
  minting a Finance session from a Personnel-intent credential (UI/UX plan §5.1 prohibition).
- **Control (new):** The token row binds `account_key` **and** `intended_host`. At consume the
  *actual* resolved host must equal the bound host, and its account must equal the bound account.
  Any mismatch returns the uniform failure and consumes nothing extra.
- **Test:** `MagicLinkHostBindingTest` — success on the bound host for all eight accounts, denial
  on every other host, denial on account substitution.
- **Residual risk:** None known. The binding is server-side; the browser supplies only the token.

### TM-03 — Environment bleed (production link consumed in staging, or the reverse)

- **Attacker/Precondition:** T1/T3; access to more than one environment.
- **Asset:** A1 → A3.
- **Control (new):** `environment` is bound at issue and compared at consume against the registry's
  own environment bucket, not against a request header.
- **Test:** `MagicLinkEnvironmentBindingTest`.
- **Residual risk:** Two environments sharing one database would defeat this. That is a deployment
  invariant (UI-17 / backend Phase 25), recorded, not assumed away.

### TM-04 — Redirect manipulation / open redirect after authentication

- **Attacker/Precondition:** T4; user is induced to request a link with a crafted `redirect`.
- **Asset:** A3 → victim lands on an attacker page believing it is Servana.
- **Control (new):** The redirect is validated by `AccountHostUrlGenerator::safeRelativePath()`
  **at issue time** and bound into the row; at consume it is re-validated and additionally checked
  against a bounded length and the target account's route family. Absolute, protocol-relative,
  backslash-smuggled, control-character and userinfo forms are rejected outright, never "cleaned".
  An invalid bound redirect invalidates the link rather than silently falling back.
- **Test:** `MagicLinkSafeRedirectTest`; `ContextHandoffConsumeTest` (unsafe deep link rejected).
- **Residual risk:** None known for external redirection. In-application deep links can still point
  at a route the user cannot use; that resolves to the role-safe denial state, by design.

### TM-05 — Session fixation

- **Attacker/Precondition:** T3/T4; attacker plants a known session id in the victim's browser.
- **Asset:** A3.
- **Control:** `session()->regenerate()` after Magic Link login (existing) **and** after handoff
  consumption (new) and after MFA confirmation (existing). The host-session binding row is written
  against the regenerated id, so a fixed id is never bound.
- **Test:** `MagicLinkHostBindingTest` (id changes), `ContextHandoffConsumeTest` (id changes).

### TM-06 — Wildcard cookie leakage / permission carryover between hosts

- **Attacker/Precondition:** T2; holds a session on one host.
- **Asset:** A3, A5.
- **Abuse path:** With `Domain=.servana.ke`, one cookie is presented on all eight hosts, so one
  permission set applies everywhere and per-host revocation becomes impossible (ADR-018 names this
  as the rejected alternative).
- **Control:** `SESSION_DOMAIN` stays unset → host-only cookies. A regression test asserts the
  configured domain is null/empty and that no `Set-Cookie` carries a parent domain.
- **Test:** `HostScopedSessionTest`.
- **Residual risk:** An operator could set `SESSION_DOMAIN` in a deployment. The test guards the
  repository default; deployment configuration is UI-17 / Phase 25.

### TM-07 — Source-context permission carryover through the handoff

- **Attacker/Precondition:** T2; a Merchant Administrator switching to a Personnel context, or the
  reverse.
- **Asset:** A5.
- **Control:** The handoff token carries **no permission array**. At consume the target session is
  built by re-reading user, merchant, membership, role and branch from the database and
  re-resolving permissions through the existing `PermissionResolver`. The target session is created
  fresh; the source session's context is never copied.
- **Test:** `ContextHandoffAuthorizationFreshnessTest` (target `/me` permissions equal the target
  role's set and contain no source-only key); schema test asserting no permission column exists.

### TM-08 — Stale authority between issue and consume

- **Attacker/Precondition:** T5; membership/role/branch removed, or user/merchant suspended, in the
  seconds between minting and consuming a handoff.
- **Asset:** A5.
- **Control:** Every target authority is re-read at consume, inside the same locked transaction
  that marks the token consumed. A revoked source family or source host session also rejects.
- **Test:** `ContextHandoffAuthorizationFreshnessTest` — one case per stale dimension
  (membership removed, role changed, branch removed, user suspended, merchant suspended, family
  revoked, source session revoked).

### TM-09 — Concurrent double consumption

- **Attacker/Precondition:** T2/T3; two simultaneous requests with the same token.
- **Asset:** A2 → two target sessions from one credential.
- **Control:** `SELECT … FOR UPDATE` on the handoff row inside a transaction, plus a conditional
  update that must affect exactly one row. Combined with the unique `token_hash`, exactly one
  consumer can win.
- **Test:** `ContextHandoffConcurrencyTest` — a real PostgreSQL concurrency proof using two
  connections, asserting one success and one rejection, and exactly one target session.

### TM-10 — Target-host substitution of a handoff token

- **Attacker/Precondition:** T2; mints a handoff for `staff.` and presents it at `citrus.`.
- **Asset:** A5.
- **Control:** `target_host` and `target_account_key` are bound; the consume route compares them to
  the resolved host. Mismatch → uniform rejection, token invalidated.
- **Test:** `ContextHandoffReplayTest`, `ContextHandoffConsumeTest`.

### TM-11 — Client-asserted target role

- **Attacker/Precondition:** T2; edits the switch request body.
- **Asset:** A5.
- **Control:** The browser submits **only an opaque server-issued context id**. The id is looked up
  in the freshly derived context list for the current user; an id that is not in that list is
  rejected. No role, permission, merchant, branch, host or MFA value is accepted from the client.
- **Test:** `ContextHandoffIssueTest` (unknown/foreign context id rejected; body role field ignored).

### TM-12 — Token leakage through logs, referrers, storage or proof artifacts

- **Attacker/Precondition:** T6.
- **Asset:** A1, A2.
- **Control:** Only hashes are stored. Audit context carries masked email, safe ULIDs, host keys and
  a rejection category — never a token. The handoff-consume response sets `Referrer-Policy:
  no-referrer` and immediately redirects to a clean URL with the token stripped. The token is never
  written to `localStorage`/`sessionStorage`.
- **Test:** `ContextHandoffConsumeTest` (no token in the redirect target, `Referrer-Policy` header
  present), `SessionSecretRedactionTest` (no raw token in logs or `audit_logs`), plus the repository
  scan in §30.7 of the phase brief.

### TM-13 — Forwarded-host abuse steering login or handoff URLs

- **Attacker/Precondition:** T3; sends `X-Forwarded-Host: evil.test`.
- **Asset:** A1 → poisoned Magic Link email (classic password-reset poisoning).
- **Control:** Every emitted URL comes from `AccountHostUrlGenerator`, which reads the registry, not
  the request. Forwarded headers are honoured only from configured trusted proxies, and
  `AccountHostResolver` rejects ambiguous/multi-valued forwarded hosts.
- **Test:** `MagicLinkHostBindingTest` (the emailed URL host is the registry host under a poisoned
  forwarded header); existing `AccountHostResolutionTest`.

### TM-14 — Account enumeration through differential failure

- **Attacker/Precondition:** T3.
- **Asset:** the existence of an account, and which account type an email belongs to.
- **Control:** The request endpoint keeps its uniform `202`. Every consume failure — wrong host,
  wrong environment, wrong account, tampered redirect, expiry, replay, suspension, removed
  membership — returns the same `422` shape with the same message. The specific reason is recorded
  only in the audit row.
- **Test:** `MagicLinkHostBindingTest` (all denial bodies byte-identical), existing
  `NoAccountEnumerationTest`.

### TM-15 — Broader-role redirect on an unauthorized deep link (`UI01-ROLE-001`)

- **Attacker/Precondition:** T2; a merchant-side user opens `/platform/...`.
- **Asset:** A5.
- **Abuse path (proven in UI-01):** the `/platform` route tree used only `requiresAuth`, so any
  authenticated user rendered the Super Administrator shell; the backend refused the data, but the
  surface was disclosed.
- **Control:** Route records now declare their owning account (`meta.accountKey`), and a global
  guard requires the route's account to equal **both** the server-resolved host account **and** an
  authorized, server-derived context the user currently holds. A mismatch renders a role-safe
  denial state — never a redirect to a broader account.
- **Test:** `AccountRouteContextAuthorizationTest`, `WrongAccountDeepLinkTest`,
  `accountRouteGuard.spec`, `roleEntryRoutes.spec` regression.

### TM-16 — Unauthenticated browser navigation crashing on the missing `login` route

- **Attacker/Precondition:** T3 (also ordinary users); observed in UI-02.
- **Asset:** availability + information disclosure through a 500.
- **Control:** An explicit unauthenticated-redirect callback resolves the host-correct SPA login
  path from the resolved account host, so no `route('login')` lookup occurs. JSON/API requests keep
  the existing `401` envelope. Machine routes are untouched.
- **Test:** `UnauthenticatedBrowserRedirectTest`.

### TM-17 — Cross-site request forgery against switch/logout endpoints

- **Attacker/Precondition:** T4.
- **Asset:** A3, A4.
- **Control:** All authenticated UI-03 endpoints live on the Sanctum stateful `/api/v1` surface, so
  `ValidateCsrfToken` applies; cookies are `SameSite=Lax`, and the handoff-consume route is a
  top-level `GET` navigation carrying a single-use bound token rather than an ambient-authority
  mutation.
- **Test:** `SessionFamilyLifecycleTest` (CSRF required on logout-all), existing CSRF coverage.

### TM-18 — CORS misconfiguration granting credentialed cross-origin access

- **Attacker/Precondition:** T4.
- **Control:** No `cors` config file exists and no `HandleCors` middleware is registered — the
  application is same-origin only, which is stronger than an allowlist. A regression test asserts
  that no wildcard-with-credentials configuration is introduced.
- **Test:** `HostScopedSessionTest` (CORS posture assertion).

### TM-19 — Privileged assurance laundering across a switch

- **Attacker/Precondition:** T2; holds a non-MFA Personnel session, switches to Finance.
- **Asset:** A6.
- **Control:** `mfa_verified_at` is **never** copied into the target session. The new session starts
  with no privileged assurance; `EnsurePrivilegedMfa` then challenges whenever the target role is
  mandatory. Step-up freshness (`StepUpAction`) is likewise not carried.
- **Test:** `ContextSwitchMfaRefreshTest`.

### TM-20 — Revocation that is not actually global

- **Attacker/Precondition:** T5.
- **Asset:** A3, A4.
- **Control:** `AccessRevocationService` is *extended*, not duplicated: every existing entry point
  (user, membership, merchant) additionally revokes the session families and host-session bindings
  of the affected users, and the new family-level operation deletes every linked row in `sessions`.
  Because Laravel sessions are database-backed, deleting the row logs the browser out on its next
  request on every host.
- **Test:** `CrossHostRevocationTest`, `SessionFamilyLifecycleTest`, `OwnSessionManagementTest`.
- **Residual risk:** Revocation is effective at the *next* request, not mid-request. That matches
  the existing R6 posture and is documented, not silently assumed.

### TM-21 — Audit secret leakage

- **Attacker/Precondition:** T6.
- **Control:** New events reuse `AuthAuditLogger`, whose context is a masked email, a rejection
  category and safe ULIDs. No raw token, cookie, session id, full IP, full user-agent or permission
  array is ever passed.
- **Test:** `SessionSecretRedactionTest`; `MagicLinkTokenSecurityTest` extension.

### TM-22 — Machine routes made dependent on browser account context

- **Attacker/Precondition:** operational, not adversarial — a regression that breaks health probes,
  workers, schedulers, webhooks or signed file routes.
- **Control:** UI-03 adds no host or account requirement to any machine route; the existing
  `MachineRoutesIgnoreBrowserAccountContextTest` continues to prove it.
- **Test:** `MachineRoutesIgnoreBrowserAccountContextTest` (unchanged, re-run).

---

## 4. Explicit non-goals for UI-03

WebAuthn/passkeys, SMS/email OTP, administrator-driven MFA reset, cross-user administrative session
management (no canonical permission authority exists — see §21 of the phase brief), production DNS/
TLS/HSTS, and any UI-04 visual redesign. Each is recorded with its owner in `docs/proof/ui-03.md`.
