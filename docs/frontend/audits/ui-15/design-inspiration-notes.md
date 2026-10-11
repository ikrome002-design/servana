# UI-15 design-reference translation — Audit account

Folder: `C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design Inspiration`. All six
files were re-hashed on 2026-10-11 and **visually inspected** in this session (rendered as images,
not inferred from UI-14's notes). Hashes are identical to UI-14's record.

| File | Bytes | SHA-256 | Readable |
|---|---:|---|---|
| Dashboard page.webp | 35,302 | `2c88882ff7f7212b5488561b9dfaae6261b4fa1a4ca2612bbc9ef1288a63b236` | yes |
| Billings page.webp | 38,100 | `8132cc4ae2a268eb5927cea4f30f686c700decb4c072e69cea2785c533be261e` | yes |
| Notifications page.webp | 24,344 | `5ef0a60a382b7b32740ace079f1ea742de2551d80176bc02b8ae4a4247e0d4e2` | yes |
| Profile page.webp | 97,806 | `c330f3bf9d8032e43ef0706ec226c5e53956c2996313e9c71b256f6379c42f54` | yes |
| Login page.webp | 40,390 | `bffa0c3c5c6ec03cf39df7b616e973dcf19a778dfa8fa6a36deb775d70e100dc` | yes |
| Landing page.webp | 35,458 | `aeeb688ad065acba4ecf32190de7d6a7ef6c029aa666efaf9896d87930b75fb0` | yes |

Servana Brand Identity governs every colour, type and logo decision. No reference colour, product
name, logo, avatar, card artwork or figure was copied; all surfaces use generated `--sv-color-*`
tokens and Tailwind aliases.

## What each reference contributed — and what was refused

| Reference | Quality borrowed | Audit surfaces | Refused |
|---|---|---|---|
| Dashboard | Asymmetric composition: one dominant "act next" surface, a narrower side queue, compact fact tiles, a dense table-like lower band | Dashboard (`Investigate next` hero + escalated evidence 1.35fr / review queue 0.65fr; four audit slices; activity timeline) | Balances, card art, fake charts, avatars, invented trends |
| Billings | Tiered panels: overview cards, a status-badged history table, a soft tonal header wash | Export History (status filter, desktop table, mobile cards), Audit Exports (form + "Included / Masked / Never included / After you request" panels), Finance view | Payment cards, plan pricing, "Upgrade" CTA, wallet/money movement |
| Notifications | Grouped, readable list rhythm with a focused overlay | Flag dialog; repeated-attempt clusters on Access/Contact-protection views; review queue list rhythm | Any notification runtime, unread counts, push toggle — Notifications stays gated (21N) |
| Profile | Layered identity header over sectioned facts; a deliberate dark surface | Account and Preferences (band, avatar initials, four scope facts, permission pills); dark captures of every page | Social links, media collections, self-editable role facts |
| Login | Focus clarity and primary-action geometry in forms | Flag note, review notes, export reason (labelled fields, single dominant submit) | Password fields, new auth flow — Magic Link unchanged |
| Landing | Controlled tonal shape and depth (soft circle/ring forms) | `SvTonalPageHeader` on every Audit page; Integrity result card ring; evidence hero wash | Marketing layout, foreign imagery, unsupported copy |

## Audit-specific visual decisions

- **Teal-led investigative identity** (Service Teal) with Savannah Orange reserved for the single
  dominant action per surface; Acacia Green for integrity; Golden Sun for "changed" markers.
- **Two shapes for two facts:** event severity is a squared tag with a glyph (critical has its own
  shield glyph and an inner ring); review status is a rounded pill with a flag glyph and a
  "Review:" prefix for assistive technology. They are never the same component.
- **Time-led evidence cards:** Nairobi time and day lead every event; the readable action sits above
  its exact machine token so nothing is hidden by humanisation.
- **Structured diff, not JSON:** a semantic table on wide screens, labelled stacked cards ≤767px.
- **Restraint:** colour marks severity, review and integrity state only; everything else is neutral
  surfaces, so a critical item is unmistakable.
