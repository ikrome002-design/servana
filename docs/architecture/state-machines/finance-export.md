# Finance Export — State Machine (Plan §65, §67; Phase 18B)

> Authoritative lifecycle for `finance_exports`. Async, tenant-aware, scoped, masked
> CSV export written through the Phase 10F file domain (purpose `finance_export`).
> Status changes go through the request action + the `GenerateFinanceExport`
> tenant-aware job + `ExpireFinanceExport` + the revoke action. No generic status
> endpoint. Mirrors the DB CHECK. See the data dictionary Gate I.

## States

| State | Meaning |
|---|---|
| `queued` | A Finance user requested an export (`finance_export.create`, fresh step-up) with a validated scope + mandatory reason. `GenerateFinanceExport` is dispatched on `reports-exports`. |
| `processing` | The job is generating the masked CSV. |
| `ready` | The file is generated, scanned/clean, and downloadable via an authorized signed link (`finance_export.download`). `expires_at` set. |
| `failed` | Generation failed; only a redacted `failure_code` / `failure_message_redacted` is stored. |
| `expired` | Past `expires_at`; no longer downloadable. Terminal. |
| `revoked` | Explicitly revoked; no longer downloadable. Terminal. |

## Transitions

```text
queued      -> processing   [GenerateFinanceExport picks up]
processing  -> ready         [generation succeeded; file_id set, expires_at set]
processing  -> failed        [generation failed; redacted failure only]
ready       -> expired       [ExpireFinanceExport / file-domain expiry]
ready       -> revoked       [RevokeFinanceExport]
failed      -> queued        [explicit retry action only, if authorized]
```

Any other transition is invalid → `422 invalid_state_transition`.

## Invariants

- Current requestable types only (Gate I): `invoices`, `payments`, `receipts`,
  `cash_up`, `refunds`, `disputes`. `compensation`, `payouts`, `billing` are
  schema-enumerated but rejected by the request policy → `422
  unsupported_export_type`.
- Merchant + optional branch scope applied **in the query** (no unscoped export then
  client-side filtering). Filters validated.
- Masked rows only (no full/normalized payment reference, no full client contact).
- Africa/Nairobi date boundaries with UTC storage.
- Private storage via Phase 10F; authorized signed download; `download_count`
  incremented atomically; `first_downloaded_at` set once; `last_downloaded_at` per
  successful download.
- `finance_export.create` requires fresh step-up (§19.3). **No** period-lock
  requirement (`finance_export.*` is `PL n/a`).

## Audit / failure codes

Events: `finance_export.requested`, `.generated`, `.failed`, `.downloaded`,
`.expired`, `.revoked`. Codes: `422 invalid_state_transition`,
`422 unsupported_export_type`, `403` (permission / stale step-up),
`404` (foreign tenant / expired-or-revoked download), `410` (gone, expired file).
