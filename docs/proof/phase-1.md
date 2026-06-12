# Phase 1 — Project Initialization · Proof of Resolution

**Branch:** `phase-1-initialization`
**Date:** 2026-06-12
**Plan reference:** §27 Phase 1, §28 execution rules, §26.2 pipeline, §12–§13 design
tokens/breakpoints. **Guardrails:** CLAUDE.md §6.

---

## 1. Prove the Problem

| What must be built | Why (requirement) | Failure if omitted | Verification |
|---|---|---|---|
| Laravel 11 / PHP 8.3 skeleton | Plan AS-1 pinned stack | No backend to build on | `php artisan test` boots app |
| Vue 3 + TS + Vite SPA under `resources/spa` | Plan §6.1, AS-1 | No frontend | `npm run build` succeeds |
| Tailwind with §12.1 tokens + §13 breakpoints | Plan §12–§13 | Brand/responsive drift | tokens in `style.css`, `screens` in `tailwind.config.ts` |
| Pest, Larastan L8 (+ custom rule placeholders), Pint | Plan Phase 1, §25 | No quality gate | `composer pint`, `composer stan` green |
| ESLint + vue-tsc | Plan §25, §6 | No FE quality gate | `npm run lint`, `npm run typecheck` green |
| gitleaks pre-commit hook + config | CLAUDE.md §6.4, Plan §3.5 | Secret leakage | `gitleaks protect --staged` → 0 leaks |
| `.github/workflows/ci.yml` PR pipeline | Plan §26.2 | No automated gate | workflow present, Postgres+Redis services |
| `tests/Feature/SmokeTest` (`/health` 200) | Plan Phase 1 | No boot proof | 2 passed |
| Reproducible README + docs | Plan Phase 1 acceptance | Onboarding > 15 min | README setup section updated |

---

## 2. Verification output (clean run, 2026-06-12)

```text
### composer pint
{"tool":"pint","result":"passed"}

### composer stan        (Larastan level 8)
 [OK] No errors

### php artisan test     (Pest)
 PASS  Tests\Feature\SmokeTest
 Tests:    2 passed (3 assertions)
 Duration: 0.68s

### npm run lint         (ESLint flat config)
clean — 0 errors, 0 warnings

### npm run typecheck    (vue-tsc --noEmit)
clean — 0 errors

### npm run test         (Vitest + jsdom + @vue/test-utils)
 Test Files  1 passed (1)
      Tests  1 passed (1)

### npm run build        (vue-tsc + Vite)
 ✓ built in ~2.3s  → public/spa/ (fonts self-hosted, JS 93.9 kB / 36.7 kB gz)

### composer audit
Found 1 ignored security vulnerability advisory affecting 1 package
(see §4 — documented, no fix on Laravel 11)
```

### Toolchain (this machine)

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.5.6 | local; **CI pins 8.3** (pinned stack AS-1) |
| Laravel | 11.54.0 | |
| Pest | 3.8.6 | |
| Larastan | 3.x | level 8 |
| Node | 24.15.0 | local; CI uses 20 LTS |
| Vue | 3.5.38 | |
| Vite | 5.4.21 | pinned-stack Vite 5 (not skeleton's 6) |
| Tailwind | 3.4.19 | |
| TypeScript | 5.9.3 | |
| gitleaks | 8.30.1 | |

---

## 3. Secret-scanning evidence (CLAUDE.md §6.4)

- `.env` was **never committed** to git history: `git log --all -- .env` → empty.
- Pre-commit / CI gate (git-mode, staged content) — **0 leaks**:

```text
$ gitleaks protect --staged --config .gitleaks.toml
INF scanned ~705.68 KB ... no leaks found  (exit 0)
```

- A filesystem scan (`--no-git`) reports 18 findings, all in **gitignored**
  files never destined for commit: 12 in the developer's local `.env` and 6 in
  `vendor/**` test fixtures. These are out of scope for the repo gate.

---

## 4. Residual risks

1. **CVE-2026-48019 (Laravel CRLF injection in default email rule)** — affects
   all Laravel 11.x; **patched only in 12.60+/13.10+, no Laravel 11 fix exists**.
   Not rated high/critical. Composer's `block-insecure` would otherwise make
   `composer install` impossible on the pinned stack, so it is explicitly
   ignored in `composer.json` `config.audit.ignore` with a documented reason.
   *Mitigation:* Magic Link auth (no password email flows), all emails validated
   via FormRequests, no validated email written to raw mail headers.
   *Action:* re-evaluate at the Laravel 12 upgrade and during Phase 5 (auth).
2. **Local PHP is 8.5.6, pinned stack is 8.3.** Per owner decision, local dev
   runs 8.5 while **CI and Docker (Phase 2) enforce 8.3**. `composer.json`
   requires `php: ^8.3`. Small risk of an 8.3-only resolution difference
   surfacing on the first CI run; lockfile is committed to minimise drift.
3. **CI not yet executed** — the workflow is authored but a PR run is pending;
   "CI green on first PR" is verified once the branch is pushed.

---

## 5. Deviations / cleanups recorded

- **Existing root `.env` was malformed** (2149 lines of M-Pesa/Daraja notes +
  16 keys) and broke every `artisan` command. Per owner decision it was
  preserved verbatim as `.env.local-notes.bak` (gitignored) and a clean
  `.env` + `.env.example` were generated. No secrets were committed; none were
  ever in git history, so no rotation is required.
- **Brand asset path mismatch:** CLAUDE.md §3 references `Favicon.ico` and
  `Logo.svg`; the repo actually has lowercase `favicon.ico` and `Logo.png`
  (no SVG). The scaffold wires the real `favicon.ico` (case-correct for
  Linux/CI) and the PNG logo. **`Logo.svg` does not exist** — flagged for the
  owner; needed before vector-logo use in later UI phases.
- **Larastan scope:** analysis covers `app`, `routes`, `database`. Framework
  `config/*.php` is excluded — it is declarative boilerplate that is not
  level-8 clean as shipped (`env()` returns `bool|string`), and the project
  rule forbids silencing such errors with casts.
- **Migrated to Pest** (removed default PHPUnit example tests) per Plan §25.
