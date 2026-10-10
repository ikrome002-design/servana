# UI-14 design-inspiration inspection

Inspection completed once on 2026-08-20 before UI-14 visual implementation. All six files were
readable through the Codex image viewer at the canonical directory
`C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design Inspiration`. The filesystem
stores the extension as lowercase `.webp`; this is the same Windows path named with `.WEBP` in the
phase brief. Reopen a reference only if its SHA-256 or mtime changes, or a specific unresolved visual
ambiguity requires it.

## Dashboard page.webp

- Bytes: `35302`
- SHA-256: `2c88882ff7f7212b5488561b9dfaae6261b4fa1a4ca2612bbc9ef1288a63b236`
- Modified UTC: `2026-08-13T06:09:33.6435662Z`
- Adopted: asymmetric command-centre composition; one broad central work surface supported by
  compact secondary cards; layered white surfaces; clear `now`, `next`, and `recent` hierarchy;
  contextual actions adjacent to the information they act on.
- Rejected: bank-card imagery, balances, investments, transfer controls, speculative charts,
  merchant-wide metrics, and generic equal-weight KPI tiles. They conflict with Servana's role,
  brand, own-scope, or authoritative-data boundaries.
- UI-14 use: Dashboard next-assignment hero, queue-order context, upcoming appointments, current
  session, availability, compact earnings snapshot, preferred-request attention, and client/SMS
  shortcuts.

## Billings page.webp

- Bytes: `38100`
- SHA-256: `8132cc4ae2a268eb5927cea4f30f686c700decb4c072e69cea2785c533be261e`
- Modified UTC: `2026-08-13T06:23:56.4388709Z`
- Adopted: calm summary-to-detail hierarchy; paired high-level cards; compact status-bearing rows;
  generous whitespace around sensitive figures; clear lifecycle badges and period context.
- Rejected: payment-card controls, plan upgrades, wallet language, fund-transfer implications, and
  client-calculated money. Servana records compensation and externally paid payout status; it does
  not move Personnel funds.
- UI-14 use: Earnings overview, Commission, Salary, Payouts, Compensation Terms, Statements, and
  Earnings Queries.

## Notifications page.webp

- Bytes: `24344`
- SHA-256: `5ef0a60a382b7b32740ace079f1ea742de2551d80176bc02b8ae4a4247e0d4e2`
- Modified UTC: `2026-08-13T06:23:09.8946773Z`
- Adopted: compact grouped rows, restrained overlay geometry, readable metadata, and a clear
  hierarchy between the title, controls, and row content.
- Rejected: notification toggle, unread counts, notification records, preferences, and live drawer.
  Phase 21N is blocked behind closed External Gate W, so UI-14 preserves only the disabled gated
  navigation treatment and creates no runtime.
- UI-14 use: no Notifications page. The row rhythm informs Message History and Earnings Queries,
  where real runtime records exist.

## Profile page.webp

- Bytes: `97806`
- SHA-256: `c330f3bf9d8032e43ef0706ec226c5e53956c2996313e9c71b256f6379c42f54`
- Modified UTC: `2026-08-13T06:20:57.4101891Z`
- Adopted: strong identity header; layered profile/security groupings; compact context facts;
  polished dark-theme borders; consistent surface depth across long settings content.
- Rejected: decorative avatar prominence without repository-backed profile media, social accounts,
  public collections, role or experience self-edit, branch reassignment, and compensation editing.
- UI-14 use: Account and Preferences identity, Magic Link/MFA posture, active sessions, theme, and
  strict explanation of fields Personnel cannot self-edit.

## Login page.webp

- Bytes: `40390`
- SHA-256: `bffa0c3c5c6ec03cf39df7b616e973dcf19a778dfa8fa6a36deb775d70e100dc`
- Modified UTC: `2026-08-13T06:16:10.2215843Z`
- Adopted: confident split hierarchy, rounded shell geometry, calm field spacing, and one visually
  dominant action.
- Rejected: username, password, forgot-password, sign-up, and manufacturing imagery. UI-14 does not
  own authentication and Servana uses Magic Links only.
- UI-14 use: transferable geometry and focused-primary-action discipline on the SMS composer and
  earnings-query dialog only; no login implementation changes.

## Landing page.webp

- Bytes: `35458`
- SHA-256: `aeeb688ad065acba4ecf32190de7d6a7ef6c029aa666efaf9896d87930b75fb0`
- Modified UTC: `2026-08-13T06:10:42.9900957Z`
- Adopted: bold controlled composition, shaped tonal background, floating functional surfaces,
  confident typography, controlled depth, and human warmth.
- Rejected: ecommerce navigation, product pricing, marketing copy, unrelated beauty-product
  imagery, and decorative shapes that reduce work-screen clarity. UI-14 does not own a public
  landing page.
- UI-14 use: restrained warm/teal tonal shapes in the personal-workday hero and compact floated
  action surfaces, using Servana tokens and real Personnel content only.

## Binding precedence

These references influence composition quality only. Servana Brand Identity, Inter app typography,
the canonical page map, real API facts, permissions, state machines, strict own-scope, masking,
accessibility, and external-gate truth remain authoritative. No reference licenses a new capability.

## Re-verification — 2026-10-08 (continuation session)

All six files were re-hashed in place and match the SHA-256, byte size and modified-UTC values
recorded above, so the inspection above stands. Following the "reopen only for a specific
ambiguity" rule, the Dashboard and Billings references were viewed once more (via the image
reader) while judging whether the inherited Personnel pages met the bar; they did not, and the
recomposition recorded in `visual-language-continuity.md` and `page-visual-acceptance.json`
followed. The Personnel account header applies the Profile lessons recorded above without reopening
that file. No other reference was reopened.