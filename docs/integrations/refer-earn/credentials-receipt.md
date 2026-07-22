# Citrus Refer & Earn — Service-Account Credential Receipt

> **Status at Phase 21R-A: NO CREDENTIALS RECEIVED.**
> This file exists to record that fact truthfully and to hold the receipt when it arrives. It is
> **not** a credential store. No secret value is ever written here, in any environment.

Controlling sources: Plan §80 Phase 21R-A entry criteria; Plan §17.1 (machine identities);
Plan §9 rule 24 (machine-credential custody); Plan §77.1 (rotation runbooks); ADR-015.

---

## Receipt status

| Field | Value |
|---|---|
| Sandbox service-account credentials received | **No** |
| R&E-assigned product code for Servana | **Not assigned** — Plan §81 rule 24 records `SRV` as an *assumption*, not a pin |
| Signing key ID (`X-Citrus-Key-Id`) | **Not issued** |
| Signing algorithm identifier | **Not pinned** by any authoritative R&E contract in this repository |
| R&E base URL (sandbox) | **Not issued** |
| Contract / schema versions | **Not pinned** — see `contract-pins.md` |
| Date recorded | Phase 21R-A, `2026-07-22` |
| Remediation item | `REM-RE-002` (`docs/remediation/register.yaml`) — must close before Phase 25 exit |

The authoritative R&E documents (`Refer_and_Earn_Project_Scope.md`,
`Citrus_Refer_and_Earn_Production_Software_Development_Plan.md`) are **not present in this
repository**, so the header contract and canonical signing string used by Phase 21R-A are taken from
the Servana Plan's own transcription of them (§58A.2 headers; §9 rule 22 canonical string).

---

## What Phase 21R-A did instead (Plan-authorised fallback)

Plan §80 Phase 21R-A entry criteria: *"if the R&E sandbox is unavailable, implement against
`FakeReferEarnClient` + recorded contract fixtures and mark a deferred-verification item that must
close before Phase 25."*

Accordingly:

- `ReferEarnClientInterface` is the only seam the domain depends on.
- `FakeReferEarnClient` is the bound implementation in local/testing and whenever the integration is
  disabled; it is deterministic and performs no network I/O.
- `HttpReferEarnClient` is fully implemented but is only bound when the integration is explicitly
  enabled **and** configured; it is never exercised in CI (Plan §81 rule 21 — never call live partner
  systems from CI or unit tests).
- No credential is defaulted in `config/refer-earn.php`; every secret is `env()` with a `null`
  default, and `.env.example` carries empty placeholders only.
- The signing contract is **algorithm-aware** and **fails closed**: an unknown or missing algorithm
  identifier raises rather than silently falling back to a default (ADR-015).

---

## Custody rules (binding when credentials do arrive)

Per Plan §9 rule 24 and §17.1:

- Secrets live only in the secrets manager under `servana/{env}/refer-earn/signing_key_{key_id}` (and
  `servana/{env}/refer-earn/inbound_secret_{key_id}` for the Phase 21R-B inbound direction).
- Sandbox, staging and production credentials are **disjoint**; a startup guard must refuse to boot
  production with a key ID carrying a `sandbox`/`staging` prefix.
- Credentials are loaded at runtime, never cached to disk, and never logged (Plan §24.5 forbids
  logging R&E signing keys, `X-Citrus-Signature` values, and nonces paired with signatures).
- Each rotation must write an `integration.credential_rotated` critical audit event and follow
  `docs/runbooks/rotate-re-signing-key.md` (Plan §77.1). **Neither exists yet**: the audit case and
  the runbook set both land with the rotation work, and the staging rotation drill for all four
  machine identities is a Phase 25 exit item (Plan §77.1, §80 launch checklist item 22).

---

## Closure checklist for `REM-RE-002` (before Phase 25 exit)

1. Record the credential receipt here: issuing party, date, environment, key ID, product code,
   base URL — **values only for non-secret fields**; the key material itself goes to the secrets
   manager and is never written to the repository.
2. Pin the R&E schema/contract versions and the signing algorithm identifier in `contract-pins.md`
   as its own reviewed commit (Plan §81 rule 23).
3. Run the signed-delivery contract suite against the R&E sandbox as an explicitly-invoked gate job
   (never in the normal CI pipeline — Plan §81 rule 21).
4. Record the sandbox transcript and flip `REM-RE-002` to `verified_complete`.
