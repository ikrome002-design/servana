# UI-16 design-reference translation — cross-account convergence

Folder: `C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design Inspiration`. All six
files were re-hashed on **2026-10-12** and **visually inspected in this UI-16 session** (each rendered
as an image, not inferred from the UI-14/UI-15 notes). The hashes equal the UI-15 record, so the
references are unchanged.

| File | Bytes | SHA-256 |
|---|---:|---|
| Dashboard page.webp | 35,302 | `2c88882ff7f7212b5488561b9dfaae6261b4fa1a4ca2612bbc9ef1288a63b236` |
| Billings page.webp | 38,100 | `8132cc4ae2a268eb5927cea4f30f686c700decb4c072e69cea2785c533be261e` |
| Notifications page.webp | 24,344 | `5ef0a60a382b7b32740ace079f1ea742de2551d80176bc02b8ae4a4247e0d4e2` |
| Profile page.webp | 97,806 | `c330f3bf9d8032e43ef0706ec226c5e53956c2996313e9c71b256f6379c42f54` |
| Login page.webp | 40,390 | `bffa0c3c5c6ec03cf39df7b616e973dcf19a778dfa8fa6a36deb775d70e100dc` |
| Landing page.webp | 35,458 | `aeeb688ad065acba4ecf32190de7d6a7ef6c029aa666efaf9896d87930b75fb0` |

Servana Brand Identity governs every colour, type and logo decision. No reference product name,
logo, avatar, card artwork, chart, number, copy or palette was copied. Every new surface uses the
generated `--sv-color-*` tokens; the UI-16 source guards and the compiled-utility check reject raw
hex and utilities that resolve to nothing.

## What I observed in each reference (this session)

| Reference | Observed qualities |
|---|---|
| Dashboard | A neutral page with a raised, rounded app frame; a left menu of icon + label rows with ONE filled active pill; a greeting header; an asymmetric grid — one dominant feature card, stacked figure cards and a narrow right rail; a clean table band with quiet uppercase headers. Hierarchy comes from size and placement, not colour. |
| Billings | A soft tonal wash behind the page title; "overview" panels in two columns (plan, usage) with a strong figure and small label; a bordered history table on its own surface with a tinted header row and status pills; generous spacing rhythm. |
| Notifications | A focused overlay over a dimmed shell; a grouped list with consistent row rhythm, thumbnail + two-line text + right-aligned value; one dominant full-width action. |
| Profile | A deep, layered dark surface; an identity block (large avatar, name, role) beside a facts panel of label/value pairs; sections as large rounded cards; a table of records with icon actions. |
| Login | A split composition: an illustrated brand panel and a quiet form card; one dominant primary action; labelled, rounded inputs. |
| Landing | Oversized editorial headline; large rounded hero card with ornamental circles breaking the frame; one black pill CTA; product cards overlapping the frame edge. |

## How UI-16 translated them — and what it refused

| Reference | Quality borrowed | Where it landed in UI-16 | Refused |
|---|---|---|---|
| Dashboard | Icon + label navigation rows with one filled active state; one dominant command surface; asymmetric 1.35fr / 0.65fr panels; quiet figure tiles | Shared shell: every left-nav entry now carries its canonical contract icon in a chip, the active entry a filled Savannah Orange chip + tinted row + `aria-current`. Platform, Merchant Administrator, Branch, HR and Finance dashboards recomposed onto `SvOperationalHero` with server facts, then a dominant "act next" panel beside a narrower context panel | Fake revenue/expense charts, card artwork, avatars, invented trends, the same four-stat grid on every role |
| Billings | Tonal title wash; a table on its own raised surface with a tinted header row; money set large with tabular figures | `SvPageHeader` now renders the tonal surface for all 47 older-account page headers (and 25 hand-rolled `<h1>` headers were converged onto it); `SvDataTable` sits on a raised card with an uppercase muted header row; Finance "Money position" ledger band; promotion/offer records as labelled fact strips | Payment cards, "Upgrade" CTA, wallet/money movement, plan pricing claims |
| Notifications | Grouped rows with a consistent rhythm; a calm dimmed overlay | Finance Audit moved onto the UI-15 time-led `AuditEventCard`; Branch/HR audit share the Audit severity vocabulary; governance, task and flow queues use one count-chip + label + chevron row rhythm | Any notification runtime, unread count or "mark read" — Notifications stays gated (21N) |
| Profile | Identity block + facts panel; deliberate dark layering | Account and Security for the five older accounts: tonal band, initials avatar, role and context, four server facts (context, sign-in method, two-factor, sessions) | Social links, media collections, self-editable role facts |
| Login | One dominant action, quiet labelled fields | Get Started: orientation hero (one `h1`) + progress panel with a ring and "Up next" step whose action is the only filled button | Password fields — Magic Link only |
| Landing | Controlled ornamental circles and depth; breathing room | Tonal header orb/ring per account tone; hero ring; landing pages kept as approved (UI-06) | New imagery, marketing claims, testimonials |

## Account accents (decoration only, never meaning)

`design-system/pageTone.ts` gives each account one wash for its tonal headers: Super Administrator,
Merchant Administrator and Front Office **warm** (Savannah Orange); Branch, HR and Personnel **green**
(Acacia Green); Finance and Audit **teal** (Service Teal — trusted information). An explicit `tone`
on a page always wins, so every UI-14/UI-15 page keeps its own choice.
