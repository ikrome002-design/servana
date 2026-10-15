# Servana — local browser UAT runbook (LOCAL-UAT-001)

How to run the **whole currently implemented application** on this Windows machine and explore it in an
ordinary browser on the eight real account hosts. Everything here was executed and verified on
2026-10-15 (see [`docs/proof/local-uat-001.md`](../proof/local-uat-001.md)). This is a **local**
environment: it is not staging, not production, and not product-owner approval of anything.

All commands are PowerShell 7, run from the repository root:

```powershell
Set-Location 'C:\Users\nderu\Documents\Development\Product\Servana'
```

## 1. One-time machine setup

| Step | Command | Expected |
|---|---|---|
| Map the eight hosts to `127.0.0.1` (one Windows UAC prompt — click **Yes**) | `pwsh -File scripts\dev\servana-local.ps1 hosts` | eight `PASS` lines; the original hosts file is backed up beside it as `hosts.servana-backup-<timestamp>-<id>` |
| Check everything the stack needs | `pwsh -File scripts\dev\servana-local.ps1 preflight` | all `PASS` |
| Load the local dataset (safe to repeat) | `pwsh -File scripts\dev\servana-local.ps1 seed` | `LOCAL-UAT dataset created.` (or `already present`) |

The hosts tool edits only its own marked block, preserves every other line (Docker Desktop's included)
and the file's encoding. `pwsh -File scripts\dev\servana-hosts.ps1 -Remove` (elevated) removes the block
again. It never touches public DNS, the firewall or browser security settings.

## 2. Day-to-day commands

| Action | Command | Success looks like |
|---|---|---|
| Start (idempotent) | `pwsh -File scripts\dev\servana-local.ps1 start` | 29 `PASS` lines, then the eight URLs below |
| Status | `pwsh -File scripts\dev\servana-local.ps1 status` | every service, deep-health check and host `PASS` |
| Restart (this project only) | `pwsh -File scripts\dev\servana-local.ps1 restart` | `containers healthy after restart` + all `PASS` |
| Stop (data kept) | `pwsh -File scripts\dev\servana-local.ps1 stop` | `Stopped. Volumes … are kept` |
| Logs (bounded, tokens redacted) | `pwsh -File scripts\dev\servana-local.ps1 logs nginx 100` (services: `nginx`, `app`, `worker`, `scheduler`, `file-worker`, `postgres`, `mailpit`) | recent lines |
| Migrations (non-destructive) | `pwsh -File scripts\dev\servana-local.ps1 migrate` | `Nothing to migrate` or the applied list |
| Rebuild the browser bundle | `pwsh -File scripts\dev\servana-local.ps1 build` | `built in …` |
| Live frontend editing | `pwsh -File scripts\dev\servana-local.ps1 watch` | rebuilds on every save — **reload the page** to see it |
| Run the real-stack browser acceptance | `pwsh -File scripts\dev\servana-local.ps1 uat` | `passed`, no `failed` |

**Frontend editing (why `watch`, not Vite HMR).** The eight hosts are served by nginx → Laravel, which
names the fingerprinted bundle from the built manifest under the strict UI-17 Content-Security-Policy.
The Vite dev server (`npm run dev`, port 5173) is a standalone origin with no API proxy, so it is not the
eight-host path. `watch` rebuilds the real bundle the edge serves; reload the browser after `built in`.
Sessions, cookies and CSRF are unaffected because the origin never changes.

## 3. The eight browser entry points

Always start on the **public landing page `/`** of the account you want to test.

| Account | Public landing (first page) | Sign in | Authenticated home | Also |
|---|---|---|---|---|
| Merchant Administrator | <http://servana.test:8080/> | `/login` | `/dashboard` | `/register` → `/setup` (new business), `/get-started` |
| Super Administrator | <http://citrus.servana.test:8080/> | `/login` | `/dashboard` | `/get-started`; header navigation |
| Branch | <http://branch.servana.test:8080/> | `/login` | `/dashboard` | `/get-started` |
| Finance | <http://finance.servana.test:8080/> | `/login` | `/dashboard` | `/get-started` |
| Human Resource | <http://hr.servana.test:8080/> | `/login` | `/dashboard` | `/get-started` |
| Front Office | <http://office.servana.test:8080/> | `/login` | `/dashboard` | `/get-started` |
| Personnel | <http://staff.servana.test:8080/> | `/login` | `/dashboard` | `/get-started` |
| Audit | <http://audit.servana.test:8080/> | `/login` | `/dashboard` | `/get-started` |

Every host also serves `/faq`, `/legal/data-policy`, `/legal/privacy-policy` and `/legal/terms-of-service`.
**Mail viewer (all sign-in links land here): <http://localhost:8025/>** — nothing is ever sent outside this
machine.

## 4. Signing in (Magic Link only — there are no passwords)

1. Open the account's landing page, choose **Log in**, type one of the addresses below, submit.
2. Open <http://localhost:8025/>, open the newest "Your Servana sign-in link" message, click the link.
   It works **once**, for **15 minutes**, and **only on the host that requested it**.
3. Merchant Administrator, Finance and Super Administrator need two-factor authentication. On first
   sign-in the app shows a setup key: add it to an authenticator app (Google Authenticator, 1Password …),
   enter the 6-digit code, save the recovery codes. Later sign-ins ask for a code.
4. You land on `/dashboard`. `/get-started` is in the navigation of every account.

**Limits you may hit while testing:** 3 sign-in requests per minute per address, 10 per hour from this
machine, 3 business registrations per hour. Everything shares one IP here (the Docker gateway). If you
hit one, wait, or clear the **local** limiter cache:
`docker compose exec app php artisan cache:clear`.

### Your identities (synthetic; no real person or business)

| Account | Address | Merchant · branch |
|---|---|---|
| Super Administrator | `admin@citrus.servana.test` | platform |
| Merchant Administrator | `owner@acacia.servana.test` | Acacia Wellness Studio (all branches) |
| Branch | `branch@acacia.servana.test` | Acacia · Westlands |
| Human Resource | `hr@acacia.servana.test` | Acacia · Westlands |
| Finance | `finance@acacia.servana.test` | Acacia · Westlands |
| Front Office | `frontoffice@acacia.servana.test` | Acacia · Westlands |
| Personnel | `stylist@acacia.servana.test` (also `barber@`, `therapist@`) | Acacia · Westlands |
| Audit | `audit@acacia.servana.test` | Acacia · Westlands |
| Front Office (other branch) | `frontoffice.kilimani@acacia.servana.test` | Acacia · Kilimani |
| Merchant Administrator (other tenant) | `owner@baobab.servana.test` | Baobab Grooming Lounge |
| Front Office (other tenant) | `frontoffice@baobab.servana.test` | Baobab · Nairobi CBD |

Addresses beginning `uat.` belong to the automated acceptance run — please do not use them; their
two-factor secrets live only in the git-ignored `storage/app/local-uat/automation-mfa.json`.

To try **self-registration**, open <http://servana.test:8080/register> with any new address (for example
`you+1@register.servana.test`); the link arrives in Mailpit and leads through two-factor setup to `/setup`.

## 5. What to try, by account

"Can navigate" means the page renders with live data. "Real operation" means it saves to the database.

| Account | Real operations that work locally |
|---|---|
| Super Administrator | merchant directory/detail, registrations, subscriptions, plans/prices, promotions, free periods, fee rules, SMS billing, platform access, feature flags, audit. **Billing settings start unconfigured** (a fresh install answers "not found" until you save them once — expected). |
| Merchant Administrator | profile, branches, staff overview, subscription + invoice (collection is **gated**: Wallet / Gate W), compensation summary, payout and period-reopen approvals |
| Branch | open/close the day, open the queue, calendar, service catalogue (create/edit), staff schedule, read-only queue/appointments/invoices/payments/receipts, cash-up **submission** |
| Human Resource | roster, invite, staff detail/lifecycle, eligibility, availability, compensation set-up/history, payout **preparation** (not approval) |
| Finance | validation queue and detail (validate a Front Office cash/M-Pesa record → receipt), invoices, payments, receipts, disputes, refunds, cash-up review, period locks, payouts, liabilities, queries, exports, audit |
| Front Office | clients, walk-ins, appointments, queue (assign/transfer), service sessions, invoices, **offline** payment recording (cash / M-Pesa reference — no money moves), payment status |
| Personnel | own queue/appointments/sessions/history, own served clients, SMS **draft/preview** (no SMS is sent), own earnings/payouts/terms/statements/queries, own availability |
| Audit | branch events, flags, operations/finance/compensation/access views, integrity, exports — read-only |

**Gated (shown as unavailable on purpose — 28 pages):** notifications on every account, reports, and the
Wallet-dependent subscription payment screens are blocked by External Gate W / Phase 21N; HR "Edit staff
profile" and "Role and branch assignment" await their mutation contracts. They must stay unavailable.

## 6. Owner acceptance checklist (to be completed by the product owner — not by an agent)

| Area | What to judge | Decision (accept / reject / notes) | Date | Reviewer |
|---|---|---|---|---|
| Layout and brand | Logo, colours, typography match the brand on all eight hosts | | | |
| Readability | Text size, contrast, density on desktop and phone | | | |
| Mobile | Drawer navigation, tables become cards, nothing clipped at phone width | | | |
| Motion and theme | Light by default; dark mode is a deliberate choice and is remembered | | | |
| Business terminology | Every label and status reads the way the business speaks | | | |
| Forms | Labels, validation messages, success and error notices | | | |
| Navigation | Each account finds its pages; gated items explain why they are unavailable | | | |
| Copy | Landing, FAQ and legal copy (legal text is verbatim — report, never edit) | | | |
| Errors and empty states | What a user sees with no data, no permission, or a failed save | | | |
| Screen reader | NVDA / VoiceOver pass (manual — not covered by automation) | | | |

## 7. Troubleshooting

| Symptom | Check | Fix |
|---|---|---|
| Browser says the site can't be reached | `pwsh -File scripts\dev\servana-hosts.ps1` | `scripts\dev\servana-local.ps1 hosts` (UAC), then reopen the browser |
| Connection reset / empty response | You used a host that is not one of the eight — the edge closes unknown hosts on purpose | use a URL from section 3 |
| Page loads but nothing works / blank | `status` → `SPA bundle built` | `scripts\dev\servana-local.ps1 build` |
| Docker errors | `docker info` | start Docker Desktop, then `start` |
| Port 8080 or 8025 in use | `Get-NetTCPConnection -LocalPort 8080` | stop the other program, then `start` |
| "invalid or expired" sign-in link | the link was used, superseded by a newer one, older than 15 minutes, or opened on another host | request a new link on the same host |
| No email in Mailpit | `status` → `service: worker`; `logs worker 50` | `restart` (queued mail is kept and delivered) |
| 419 (session expired) | the session idled out (60 minutes) | sign in again |
| 401 / 403 / 404 | 401 = signed out; 403 = this account may not do that; 404 = not in your merchant/branch | expected protections — use the right account |
| 429 too many requests | sign-in/registration limits (section 4) | wait, or `docker compose exec app php artisan cache:clear` |
| Old content after a code change | the bundle was not rebuilt | `build` (or keep `watch` running) and reload |
| Copy-to-clipboard says "failed" | browsers allow clipboard writes only on HTTPS or localhost | expected on plain-HTTP local hosts |

## 8. Reset (documentation only — never automated)

A full reset **destroys every local record** (synthetic data, anything you created, uploaded files). Do it
only on purpose:

```powershell
docker compose exec postgres pg_dump -U servana servana > "$env:USERPROFILE\servana-local-backup.sql"   # optional backup
docker compose exec app php artisan migrate:fresh                                                       # IRREVERSIBLE
pwsh -File scripts\dev\servana-local.ps1 seed
```

`stop`, `restart` and `start` never remove data. The backend test suite runs against its own
`servana_test` databases and never touches the local database (LUAT-D03).
