# COR-UI08-UI12-VIS-001 — design reference translation

Six binding reference images (`C:\Users\nderu\Documents\Development\Product\Template\UI-UX Design
Inspiration`), byte-identical to the hashes UI-16 recorded and re-inspected visually in this
session. Hashes, sizes and dimensions are in `reference-inspection.json`. The references set the
**craft level**; Servana's brand, data, routes and permissions set the content.

## Adopted versus rejected

| Reference | Adopted (concrete) | Rejected |
|---|---|---|
| Dashboard | Slim floating navigation panel with a filled active pill; asymmetric first fold (large feature surface + narrower queue); small tracked-caps labels over large figures; ledger band under the fold | Card balances, spend charts, transaction feeds, bank branding; any chart without a real time series |
| Billings | Warm airy plan emphasis (current plan as the feature card); history as a hairline ledger with a column band; statement document anatomy; per-currency figures | Card entry, payment-method vault, "pay now" provider action (Gate W closed) |
| Notifications | Layered sheet over a blurred, dimmed parent; rich list-item anatomy (eyebrow, title, quoted body, chips, single action) | Activating the gated Notifications pages; unread counts; persistent alert feeds |
| Profile | Commanding dark identity card with a luminous ring, name and role, sign-in chip; grouped regions with crisp dividers | Avatars/media galleries, invented personal data, dark-only design, neon accents |
| Login | Split panel: expressive dark stepper beside a calm form (first-time setup); numbered form sections with a preview rail | Passwords, third-party identity, any change to the Magic Link auth pages |
| Landing | Editorial header: eyebrow with a dot, large Manrope title, warm radial wash; principle cards on Get Started | Product photography, ecommerce, public landing changes |

## Account translation matrix

| Account | Reference zone → principle | Target pages | Before deficiency | New composition | Token mapping | Truth preserved |
|---|---|---|---|---|---|---|
| Super Administrator | Dashboard first fold → asymmetric command centre; Billings → rule workspace | dashboard, 8 billing pages, merchants, audit, flags | Header strip and uniform cards; current vs next config indistinguishable | Deep Earth header capsule with pill groups; lifecycle feature panel; "In force now" strip + next-version form; rule rails | Deep Earth gradient, teal eyebrows, orange primary | Header-only nav; effective dating; no Wallet health; gated integrations stated |
| Merchant Administrator | Landing editorial + Billings statement | dashboard, get started, subscription (4), compensation, approvals | Symmetric tiles, list-style invoices | Editorial owner dashboard; statement document; before→after plan change; approval chain rails | Feature brown, sun highlights | Owner approvals only; no operator actions; no browser money math |
| Branch | Dashboard queue + timeline | dashboard, day, calendar, services, queue, appointments, cash-up | Buttons and lists without operational state | Day-state feature with stage rail; date tiles; category-grouped catalogue; read-only lanes; timeline/list toggle | Green for open/positive, sun for blockers | Read-only finance/queue; maker-only cash-up |
| Human Resource | Profile identity + Dashboard readiness | dashboard, staff (4), eligibility, availability, compensation (4), payouts | Long forms and plain lists | Readiness bars; directory ledger; dark identity card; skills board; contract clauses; stepped setup | Teal operational, brown identity | Configuration only; no Finance approval or mark-paid |
| Finance | Dashboard risk + Billings ledgers + Notifications case files | all 20 | Hero + uniform tiles; button rows | Risk dials; ranked inbox; verdict desk; case files; ledgers; lifecycle board; four-eyes pipeline; sealed-period timeline; statement cards | Clay red only for critical lanes; teal for checker context | Maker/checker, step-up, masking, per-currency, no receipt before validation |

Per-page before issue, composition and reference are in `page-redesign-acceptance.json`; the
before/after/populated frames are indexed in `visual-baseline-index.json`.
