# Local and staging account hosts

Phase UI-02 · ADR-016, ADR-017 · UI/UX plan §4.4, §4.5

Servana serves **eight account experiences from one application**, one per hostname. Locally
they all resolve to the same Docker edge; the hostname selects which account experience you
get. This document is the exact setup, validation and removal procedure.

> **The host is never authorization.** A hostname selects the *experience*. Identity,
> membership, tenant, branch, role, permission and MFA state are re-evaluated server-side on
> every protected request. Adding a hosts-file entry gives you nothing you did not already
> have.

---

## 1. The eight local hosts

| Account | Local host | Production host |
|---|---|---|
| Merchant Administrator | `servana.test` | `servana.ke` |
| Super Administrator | `citrus.servana.test` | `citrus.servana.ke` |
| Branch | `branch.servana.test` | `branch.servana.ke` |
| Human Resource | `hr.servana.test` | `hr.servana.ke` |
| Finance | `finance.servana.test` | `finance.servana.ke` |
| Front Office | `office.servana.test` | `office.servana.ke` |
| Personnel | `staff.servana.test` | `staff.servana.ke` |
| Audit | `audit.servana.test` | `audit.servana.ke` |

These are generated from `config/account-hosts.json`. Never hand-maintain a second copy.

**Local hostnames are not DNS.** `*.servana.test` exists only in your own hosts file. The
production hostnames in the right-hand column are real DNS names owned by Citrus Labs; `.test`
is a reserved TLD (RFC 6761) that can never resolve publicly, which is exactly why it is used.

---

## 2. Docker port

The dev edge publishes **`8080`** (`docker-compose.yml`, service `nginx`). Every local account
URL therefore carries the port:

```text
http://servana.test:8080
http://citrus.servana.test:8080
http://branch.servana.test:8080
http://finance.servana.test:8080
http://hr.servana.test:8080
http://office.servana.test:8080
http://staff.servana.test:8080
http://audit.servana.test:8080
```

The port comes from `ACCOUNT_HOST_LOCAL_PORT`; change both together if you re-map it.

---

## 3. Windows hosts-file entries

The file is at:

```text
C:\Windows\System32\drivers\etc\hosts
```

Add these lines:

```text
# --- Servana local account hosts (Phase UI-02) ---
127.0.0.1 servana.test
127.0.0.1 citrus.servana.test
127.0.0.1 branch.servana.test
127.0.0.1 finance.servana.test
127.0.0.1 hr.servana.test
127.0.0.1 office.servana.test
127.0.0.1 staff.servana.test
127.0.0.1 audit.servana.test
# --- end Servana local account hosts ---
```

Editing that file **requires administrator rights and is a manual step**. Servana never edits
it, never asks for elevation, and nothing in the application depends on it having been done —
the automated host proofs send the `Host` header explicitly instead (see §6).

Open Notepad as administrator:

```powershell
Start-Process notepad -Verb RunAs -ArgumentList "C:\Windows\System32\drivers\etc\hosts"
```

---

## 4. Validating name resolution

```powershell
'servana.test','citrus.servana.test','branch.servana.test','finance.servana.test','hr.servana.test','office.servana.test','staff.servana.test','audit.servana.test' | ForEach-Object { "{0,-24} {1}" -f $_, (Resolve-DnsName $_ -ErrorAction SilentlyContinue).IPAddress }
```

Every line should report `127.0.0.1`. If a name is blank, the hosts entry is missing or the
DNS cache is stale — flush it with `ipconfig /flushdns`.

---

## 5. Validating each account host end to end

With the stack up (`make up`) and the SPA built (`npm run build`):

```powershell
'servana.test','citrus.servana.test','branch.servana.test','finance.servana.test','hr.servana.test','office.servana.test','staff.servana.test','audit.servana.test' | ForEach-Object { $r = Invoke-WebRequest "http://${_}:8080/" -UseBasicParsing; "{0,-24} {1} {2}" -f $_, $r.StatusCode, ([regex]::Match($r.Content,'data-account-key="([^"]+)"')).Groups[1].Value }
```

Each line should report `200` and the account key for that host.

Confirm an unapproved host is refused (the edge closes the connection — an error here is the
expected result):

```powershell
Invoke-WebRequest "http://127.0.0.1:8080/" -Headers @{Host='attacker.test'} -UseBasicParsing
```

---

## 6. Validation without touching the hosts file

Both automated proofs send the `Host` header themselves, so neither needs a hosts entry and
neither requires elevation:

```bash
node scripts/ui02-host-smoke.mjs
```

```bash
node scripts/ui02-host-screenshots.mjs
```

The smoke script uses `node:http` (fetch silently drops a custom `Host`); the screenshot
script launches Chromium with `--host-resolver-rules`.

---

## 7. Removing the entries

Delete the block between the two `--- Servana local account hosts ---` markers, save as
administrator, then `ipconfig /flushdns`. Nothing else needs undoing — no Servana state
depends on the entries existing.

---

## 8. Vite dev server

The dev server allows exactly the eight local hosts plus `localhost`/`127.0.0.1`, derived from
`config/account-hosts.json`. `allowedHosts: true` is deliberately **not** used: it disables
Vite's DNS-rebinding protection, and a multi-host setup is precisely the situation people
reach for that switch in.

When the HMR socket must reach the browser on an account hostname rather than `localhost`,
set `VITE_HMR_HOST` (and `VITE_HMR_PORT` / `VITE_HMR_PROTOCOL` if the topology needs them).

---

## 9. Staging

Staging hosts are **derived**, never enumerated by hand:

```text
{subdomain.}servana.{ACCOUNT_HOST_STAGING_SUFFIX}
```

With the default suffix `staging.citruslabs.co.ke` that yields
`servana.staging.citruslabs.co.ke`, `citrus.servana.staging.citruslabs.co.ke`, and so on for
all eight accounts. Change the suffix in one place and every derived host follows.

**Not done, and not claimed, by Phase UI-02:** no staging DNS record exists, no certificate has
been issued, HSTS is not enabled, and nothing has been deployed. The application-side
configuration is ready; the operational work is owned by **UI-17** and, where it concerns
backend production infrastructure, **backend Phase 25** — neither of which has started.
