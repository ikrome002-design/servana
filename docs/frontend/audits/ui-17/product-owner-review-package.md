# Servana UI-17 — product-owner review package

Everything the product owner needs to accept (or reject) the corrective UI programme's closeout,
in one place. Nothing here is pre-approved: every decision below is **yours**, and none has been
simulated by an agent.

## 1. What you are being asked to decide

| # | Decision | Recommendation | Evidence |
|---|---|---|---|
| D1 | Accept the 24 UI-16 redesigns and the 122 active visual baselines (currently agent-accepted only) | Review the before/after frames; accept or list pages to revise | `docs/frontend/audits/ui-16/page-visual-acceptance.json`, `visual-baseline-index.json`, `codex-visual-baseline-index.json`, `docs/proof/ui-16/` screenshots |
| D2 | Accept the UI-17 metric-compatible font fallbacks ('Inter Fallback', 'Manrope Fallback') — visible only for the moment before the brand fonts load | Accept: removes the measured layout shift with no change to the loaded typography | `docs/frontend/audits/ui-17/screenshots/local-origin/`, `performance-baseline-and-budget.json` |
| D3 | Logo delivery: the approved 500×500 `Logo.png` (162,570 bytes) is downloaded to draw a 24–48 px mark on every page | Approve a downscaled **derivative** (e.g. 96 px and 192 px, same artwork, generated deterministically) — the original stays the single approved master | `phase-entry-and-deferred-register.json` → `UI17-OBS-LOGO` |
| D4 | DNS/TLS: `servana.ke` is a **lame delegation** today (KeNIC → `ns1–4.a2hosting.com`, all REFUSED; 0 of 8 hosts resolve) | Fix the zone (serve it at a2hosting, or point the KeNIC delegation at the DNS provider you use) and issue `servana.ke` + `*.servana.ke` certificates | `dns-tls-matrix.json` |
| D5 | Production go-live authorization once Phase 25 provisions the platform | Do not authorize until runbook §2 P1–P10 are green | `deployment-canary-rollback-runbook.md` |
| D6 | HSTS `preload` | Defer; decide only after the HSTS ramp has run at `31536000` for a full release cycle | runbook §8 |
| D7 | Make `E2E — Playwright` a required check on `main` (UI/UX plan §24) | Approve; it has run green and stable on PRs #65–#67 | `phase-entry-and-deferred-register.json` → `UI17-OBS-E2E-REQUIRED` |
| D8 | The "agreed p75 test profile" for landing LCP ≤ 2.5 s (UI/UX plan §22.1 — no profile was ever agreed). After UI-17: **Fast 4G** (9 Mbit/s, 60 ms, 4× CPU) p75 2.01–2.21 s on all 8 hosts (was 1/8 passing); **desktop** 0.98–1.24 s; **slow 4G** (1.6 Mbit/s, 150 ms, 4× CPU) 2.99–3.17 s (was 4.70–5.14 s) | Agree Fast 4G mobile + desktop as the release profile and keep slow 4G as a tracked stretch goal; if slow 4G must pass, authorize one of: public routes stop waiting for the session check (≈ −0.2–0.35 s), or a pre-rendered public landing (architecture change) | `performance-baseline-and-budget.json`, `defect-closure.json` → `UI17-PERF-005` |

## 2. What UI-17 changed (user-visible or security-relevant)

- **Faster first load.** The edge now compresses scripts and styles (it sent them raw), the server
  page names the files the browser needs next instead of letting it discover them one round trip
  later, the two hero fonts start downloading immediately, and the session check runs once instead of
  twice. Exact before/after numbers: `performance-baseline-and-budget.json`.
- **No more jumping text.** The fallback faces are shaped like Inter and Manrope, so the moment the
  brand fonts arrive nothing moves.
- **Browser security.** A strict Content-Security-Policy (no inline script except the one hashed theme
  starter), no cross-site API reading (CORS), Secure session cookies by default in production, no debug
  pages from a production image, framing and device-permission locks, staged HSTS.
- **Credentials kept out of logs.** The web server used to write every sign-in link's one-time token
  into its access log. It no longer logs query strings or referrers.
- **Legacy cleanup.** The unused consolidated Personnel earnings screen is removed (the seven
  canonical My Earnings pages remain).

No route, API, permission, page count (160 = 132 live + 28 gated), business rule, price, legal text or
role content changed.

## 3. Screens to look at

`docs/frontend/audits/ui-17/screenshots/local-origin/index.json` lists 32 frames (eight hosts ×
landing desktop light, landing mobile light, landing mobile dark, sign-in mobile light) captured from
the **built production images on an isolated local origin**. They are **not production screenshots** —
production screenshots can only be taken after go-live (runbook §7), with the same script and
`--live`.

## 4. Manual assistive-technology checklist (not performed by the agent)

Automated axe has run on every live page in both themes (UI-16). A screen-reader pass has **not**
been performed — no screen reader is available to the agent, and automated checks are not equivalent.
Please have a reviewer run this with NVDA + Firefox (Windows) and VoiceOver + Safari (iOS):

1. On each of the eight landing pages: the skip link is announced first and moves focus to the main
   region; the page has one h1; landmarks are announced (banner, main, contentinfo).
2. Sign-in: the email field's label and the uniform "check your email" result are announced; no token
   or address is read back.
3. Authenticated shell (one account each): the left navigation (header on Super Administrator)
   announces the current page (`aria-current`); the mobile drawer traps and restores focus; gated
   items are announced as unavailable.
4. One table page and its mobile record-card view; one dialog (confirm action) — focus enters,
   Escape closes, focus returns.
5. Theme toggle announces its state; reduced-motion users see no animated transitions.
6. Record each finding with page, assistive technology, expected and actual behaviour.

## 5. What is still open and who owns it

See `phase-entry-and-deferred-register.json`. In short: DNS/TLS and production deployment (DNS owner,
Phase 25), monitoring transport and on-call (Phase 25), Gate-W pages (External Gate W), REM-PERM-002,
UI07-ENV-001, REM-SMS-002, REM-RE-002 (their existing owners), and decisions D1–D7 above.
