# Servana UI-17 — production deployment, canary and rollback runbook

Phase UI-17 (UI/UX plan §25: "Deploy the completed UI safely"). Written against the platform **as it
was discovered on 2026-10-13**, not an assumed one. Every command below is either already exercised
in the isolated rehearsal (`staging-rehearsal.json`) or explicitly marked **not yet executable**
with its owner and entry condition.

## 0. Ownership boundary

| Concern | Owner | Status on 2026-10-13 |
|---|---|---|
| Edge config (CSP, CORS, cookies, HSTS staging, log format, gzip), shell preload, budgets, host smoke, browser origin gate, release receipt, this runbook | **UI-17** | implemented, rehearsed locally |
| Production topology (≥2 replicas, load balancer, managed PostgreSQL/Redis/S3), registry, secrets store, migration-before-switch, worker drain, scheduler/Horizon, backup + PITR restore, credential rotation, alert transport and on-call | **Backend Phase 25** (Plan §76–§78, §86) | not started — needs its own authorization |
| DNS zone for `servana.ke`, TLS certificates | **Domain/DNS owner (Citrus Labs) → Phase 25** | **broken** — see §1 |
| GitHub deployment Environments, required reviewers, deploy secrets | **Repository owner** | none exist |
| Partner production re-verification (Wallet, R&E, SMS) | Phase 25 / External Gate W | Gate W closed |

UI-17 never performs a production mutation. Codex Prompt 2 owns the merge; the production rollout
itself becomes executable only when every prerequisite in §2 is green.

## 1. Discovered platform facts (read-only evidence)

| Fact | Evidence |
|---|---|
| CI builds images and runs host/browser gates but **pushes nothing and deploys nothing** (`ci.yml`: "No push, no secrets, no deploy (Phase 25)") | `.github/workflows/ci.yml` |
| **0** GitHub Environments, **0** deployments, **0** Actions secrets | `gh api repos/…/environments`, `/deployments`, `/actions/secrets` |
| `main` ruleset "Protect main": deletion + non-fast-forward + pull request + required checks **Backend, Frontend, Docker, Security** — **E2E is not yet required** | `gh api repos/…/rulesets/<id>` |
| `docker-compose.prod.yml` defines app/nginx/worker/file-worker/scheduler with managed PG/Redis/S3 expected; "Full deployment wiring lands in Phase 25" | repository |
| **`servana.ke` is a lame delegation**: KeNIC delegates to `ns1–4.a2hosting.com`; all four answer **REFUSED** (non-authoritative); public resolvers (1.1.1.1, 8.8.8.8) return **SERVFAIL**; **0 / 8** account hosts resolve; no TLS endpoint exists to test | `dns-tls-matrix.json` (`scripts/ui17-dns-tls-probe.mjs`) |

Consequence: a real production canary, production screenshots, live DNS/TLS validation and a
production rollback **cannot be performed today** by anyone, regardless of this branch. They are
`blocked_external` until §2 is satisfied.

## 2. Go/no-go prerequisites (all must be green before any production traffic)

| # | Prerequisite | Owner | How it is verified |
|---|---|---|---|
| P1 | UI-17 PR merged with the five exact-head jobs green (Backend, Frontend, Docker, Security, E2E) | Codex Prompt 2 | `gh run view` on the final head |
| P2 | Authoritative DNS for `servana.ke` answering (fix the lame delegation: either serve the zone at a2hosting or change the KeNIC delegation to the provider that hosts it), A/AAAA or CNAME for the apex and the seven subdomains | DNS owner | `node scripts/ui17-dns-tls-probe.mjs` → `resolves: true` for 8/8 |
| P3 | TLS: a certificate for `servana.ke` plus `*.servana.ke` (or eight explicit names), trusted chain, automated renewal, no certificate shared with an unrelated domain | DNS owner / Phase 25 | probe → `authorized`, `covers_host`, `days_remaining ≥ 21` for 8/8; HTTP→HTTPS 301/308 |
| P4 | TLS termination point decided: **either** TLS at this nginx (`listen 443 ssl`) **or** at a load balancer that sets `X-Forwarded-Proto: https`; `TRUSTED_PROXIES` set to that load balancer only | Phase 25 | edge smoke `--live` passes the HSTS-over-HTTPS check |
| P4a | Any proxy/load balancer in front of the edge **allowlists the eight account hosts itself** and refuses others (close/421), and may speak HTTP/1.0 or 1.1 to the edge (the edge compresses either way since UI17-PERF-006). Without the allowlist an unknown host reaches the edge, which closes the connection, and the proxy reports **502** — a false 5xx alert (found in the rehearsal) | Phase 25 | edge smoke `--live` unknown-host checks pass (closed or 421, never 502) |
| P5 | Registry + immutable tags: images tagged with the merge commit SHA and recorded by **digest** | Phase 25 | release receipt `images.*.digest` |
| P6 | Runtime secrets from a secrets store, never `.env.example`: `APP_KEY` (shared across replicas), DB/Redis/S3/mail, `SESSION_SECURE_COOKIE` true (image default since UI-17), `APP_DEBUG` false (image default since UI-17) | Phase 25 | container env inspection; `/health/deep` 200 |
| P7 | Database: this release ships **no migration**; any future release follows expand → deploy → contract with migration-before-switch | Phase 25 | `git diff --stat <prev>..<new> -- database/migrations` empty |
| P8 | Previous release image pair retained and runnable (rollback target) | Phase 25 | rollback target digests recorded in the receipt |
| P9 | Monitoring wired: synthetic edge smoke on a schedule, certificate-expiry alert, 5xx and asset-404 alerts routed to a real destination (see `monitoring-alert-readiness.json`) | Phase 25 / product owner | one benign test alert received |
| P10 | GitHub Environment `production` with required reviewer(s); product-owner deployment authorization recorded | Repository owner / product owner | environment settings; receipt `authorization` |

**No-go** if any prerequisite is red. Do not "partially" deploy.

## 3. Release fingerprint (build once, promote the same bytes)

```bash
git rev-parse HEAD                      # must equal the merge commit on main
docker build -f docker/nginx.Dockerfile --target prod -t servana-nginx:<sha> .
docker build -f docker/php.Dockerfile   --target prod -t servana-app:<sha> .
docker image inspect servana-nginx:<sha> --format '{{.Id}}'   # record; push and record RepoDigests
docker image inspect servana-app:<sha>   --format '{{.Id}}'
```

The nginx image carries the SPA build; the app image carries only its Vite manifest. **Both must come
from the same commit**, because the shell names fingerprinted chunks that only that build emitted.

## 4. Pre-traffic validation (on the new slot, before any user traffic)

```bash
node scripts/ui17-edge-smoke.mjs --origin http://<new-slot-edge>:8080 --out smoke-new-slot.json
SERVANA_ORIGIN=<new-slot-edge>:8080 npx playwright test -c playwright.origin.config.ts
```

Both must pass in full (the smoke is ~280 checks across the eight hosts; the browser gate covers the
landing shell, sign-in, protected deep link, zero CSP violations, one session bootstrap, resolving
preload hints, compressed chunks and the hero image contract).

## 5. Canary — image-slot model

The rehearsed mechanism (`scripts/ui17-release-rehearsal.mjs`): two complete slots (previous = blue,
candidate = green), each `app + edge` with its own runtime, behind one load balancer.

1. **Asset-skew rule (mandatory).** Hashed chunks differ between releases. During canary, promotion
   and rollback, `/spa-assets/*` must resolve from **either** slot (LB: try one slot, on 404 try the
   other — `proxy_next_upstream http_404` in nginx terms, or a shared immutable asset bucket/CDN).
   The rehearsal's negative control proves the defect without it (asset 404s under a split).
2. **Steps.** 5 % for 15 min → 25 % for 30 min → 100 %. Prefer **client-sticky** weighting (cookie or
   client-IP hash) so one browser stays on one release; the rehearsal deliberately used per-request
   weighting as the worst case.
3. **Go/no-go at each step** (any one fails → rollback, §6):
   - edge smoke `--live` exit 0 (eight hosts, headers, CSP, cookies, unknown-host refusal);
   - 5xx rate on the canary ≤ 1 % over 5 min (critical financial endpoints ≤ 0.5 %) and not above the
     baseline slot;
   - zero `/spa-assets/` 404s (asset skew) and zero image 404s;
   - zero CSP violations in the browser gate; no new frontend exception class;
   - Magic Link request → generic 202 on every host; context-handoff with a synthetic token → 302 with
     exactly `Referrer-Policy: no-referrer`;
   - lab LCP on the eight landing pages within budget (`ui17-web-vitals.mjs`, mobile profile).
4. **Promote**: 100 % to the candidate; keep the previous slot running for the drain window
   (≥ 30 min — the HTML shell is `no-cache`, assets are immutable for a year, so old HTML still names old
   chunks until a reload).

## 6. Rollback

Rollback = **route all HTML traffic back to the previous slot**; it never touches data.

```text
1. LB: weight 100 % → previous slot (the rehearsal measured the switch-to-all-eight-hosts-serving-previous)
2. keep the candidate slot up (assets only) for the drain window, then stop it
3. run the edge smoke and the origin browser gate against the restored slot
4. record the rollback in the release receipt (start/end, reason, smoke result)
```

What rollback is **not**: a database restore, a PITR exercise, a financial reversal, a secret rotation
or a partner replay. Those are Phase 25 runbooks. Because this release has no migration, application
rollback is schema-safe; a future release with a migration may only roll back within schema
compatibility (Plan §76).

## 7. Post-deploy acceptance (the UI/UX plan §25 UI-17 acceptance list)

| Acceptance | Command | Evidence slot |
|---|---|---|
| All eight hosts healthy | `node scripts/ui17-edge-smoke.mjs --live` and `node scripts/ui17-dns-tls-probe.mjs` | receipt `hosts`, `dns_tls` |
| All required CI green | `gh run view <run>` | receipt `ci` |
| Production screenshots captured | `node scripts/ui17-host-screenshots.mjs --live --out-dir docs/frontend/audits/ui-17/screenshots/production --label production` | receipt `screenshots` (+ sha256 per frame) |
| Rollback proven | §6 executed against production slots (or a production-identical staging) | receipt `rollback` |
| No open critical defect | `defect-closure.json` | receipt `defects` |
| Product-owner review package complete | `product-owner-review-package.md` | receipt `sign_offs` |

Fill `release-receipt-template.json` with **observed** values only. Never pre-fill a success.

## 8. HSTS ramp (after P2–P4 are green for all eight hosts)

`docker/nginx/default.conf` stages HSTS at `max-age=0`, emitted only over HTTPS. Raise it by reviewed
change and new image, one step per release: `max-age=300` → `86400` → `31536000; includeSubDomains`.
`preload` is a separate, explicit product-owner decision (it is effectively irreversible).
