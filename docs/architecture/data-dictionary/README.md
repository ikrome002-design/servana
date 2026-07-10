# Servana Data Dictionary (canonical DDL authority)

Per Plan §13.2, the version-controlled data dictionary is the single canonical
DDL authority; the inline schema blocks in Plan §13.5–§13.16 are a navigational
inventory/summary only. Each business table must have a complete entry here
**before** its migration is authored.

This folder is seeded incrementally by the owning phase for the tables that
phase creates. It is **not** a retroactive backfill of every as-built table —
existing as-built tables are documented by their owning feature phases (§13.2).

## Index

| File | Domain | Tables covered (this repo state) |
|---|---|---|
| `core-identity-and-tenancy.md` | Core / identity / tenancy | `mfa_credentials` (R3), `mfa_recovery_codes` (R3), `idempotency_keys` (R4) |
| `billing-and-wallet.md` | Platform billing + Wallet integration (**architecture spec only**) | Future 20A–20D-W tables — no migrations in v4 adoption PR |
| `refer-earn-integration.md` | R&E integration (**architecture spec only**) | Future 21R-A/21R-B tables — no migrations in v4 adoption PR |

> As feature/remediation phases land, they extend the matching file with their
> tables. Phase R3 (REM-MFA-001) authored the two MFA tables; Phase R4
> (REM-IDEMP-001) authored `idempotency_keys`.
