# ADR-015 — Cross-Platform Machine Identity and Webhook Signing

- **Status:** Accepted (v4 plan-adoption PR; verification runtime deferred to 20D-W / 21R-A).
- **Date:** 2026-07-07
- **Required by:** Plan §8.1 ADR-015, §9 rules 21–24, §17.1, §24.5, §77.1;
  supersedes ADR-006 for current architecture.
- **Related:** ADR-012, ADR-013; REM-WALLET-001, REM-RE-001.

## Context

Four machine-identity channels exist: Servana→Wallet, Wallet→Servana, Servana→R&E, R&E→Servana.
v3 ADR-006 assumed Daraja callbacks without signatures. Wallet and R&E contracts require verified
partner traffic. Servana must verify signatures without hardcoding a single algorithm before the
authoritative contract is pinned.

## Authoritative references

| Source | Role |
|---|---|
| Plan §9 rule 21 | Partner webhook verification sequence |
| Wallet scope §35 (missing from repo) | Algorithm may be HMAC or asymmetric |
| R&E dev plan §11.7 (missing from repo) | `X-Citrus-*` canonical string |

## Proven problem

Hardcoding HMAC-SHA-256 for Wallet before Gate W would violate Wallet scope §35 algorithm agility
and break rotation. Not verifying available signatures would be a security defect (SUP-03).

## Decision

**Verification architecture is algorithm-aware:**

1. **Algorithm identifier** selects the verification routine (HMAC secret-keyed vs asymmetric).
2. **Key ID** selects the active secret/public key material (supports dual-key rotation window).
3. **Contract version** selects header names, canonical string construction, and replay window.
4. **Per-environment credentials** — disjoint sandbox/staging/production; secrets-manager custody.
5. **Dual-key rotation window** — old and new keys accepted during overlap; key ID disambiguates.
6. **Constant-time comparison** where the algorithm is secret-keyed.
7. **No logging of credentials or raw signatures.**

**Servana→Wallet:** machine credentials from Wallet application registry; TLS with certificate
verification; `Idempotency-Key` on money-adjacent creates.

**Wallet→Servana webhooks:** verification sequence (Plan §14.7 / §9 rule 21): HTTPS →
content-type → body-size → required-header syntax → timestamp window → content hash →
constant-time signature → JSON parse → schema validation → canonical first-seen
`wallet_event_id` insert → fast ack → async processing. Unverified requests must **not**
occupy the canonical verified `wallet_event_id` uniqueness constraint.

**Servana→R&E / R&E→Servana:** `X-Citrus-*` construction verbatim from R&E authoritative plan
when present; inbound reconciliation uses a distinct inbound secret.

**Do not pin HMAC-SHA-256 for Wallet** unless Gate W contract explicitly pins it.

## Ownership boundary

Secrets live in environment/secrets manager only — never in repo, logs, or audit payloads.

## Data stored in Servana

Key ID metadata, contract version pins, verification outcome, minimal non-sensitive failure
metadata for security audits on verification failure.

## Data forbidden in Servana

Raw shared secrets in code; full webhook bodies in logs; signature values in audit context.

## Security implications

Failed verification may emit high-severity security audit + metrics/alerts without storing
unverified events in the canonical inbox uniqueness slot (event-ID squatting prevention).

## Tenant / isolation implications

Webhook processing resolves tenant after verification from payment→invoice mapping; verification
failures are platform-scoped signals, not tenant mutations.

## Migration implications

Adoption PR adds ADR + plan wording only. Runtime verifiers ship with integration phases.

## Rollout sequence

Adoption → Gate W contract pin → `WalletSignatureVerifier` in 20D-W → R&E verifiers in 21R-A.

## Rejected alternatives

- **Single global HMAC-SHA-256 for all partners:** rejected — not contract-authoritative for Wallet.
- **Skip verification in sandbox:** rejected — same code path in all environments.

## Consequences

Verifier implementations must be table-driven from pinned contract config, not compile-time constants.

## Test requirements

- `NoDirectProviderIntegrationTest` (no invented provider OAuth).
- Phase 20D-W: signature fixture tests from recorded Gate W contract; replay/timestamp rejection;
  constant-time behavior where applicable (future).

## Review requirements

No real or fake production credentials in repository. Rotation runbooks referenced in Plan §77.1.

## Superseded decisions

- **ADR-006 (Daraja callback security):** superseded — Daraja-specific unsigned callback model
  no longer applies.

## Deferred external-contract pins

Exact Wallet algorithm identifiers, header names, canonical string, replay window seconds, and
R&E `X-Citrus-*` field order — pinned at **Gate W** (Wallet) and **Phase 21R-A entry** (R&E).
