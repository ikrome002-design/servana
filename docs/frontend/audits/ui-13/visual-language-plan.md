# UI-13 visual-language plan

Front Office is a live branch-service command centre: fast, warm and precise. The shell leads with
the current branch, observed time and today-state. Orange marks the next safe action, teal supports
wayfinding, green signals completed/ready truth, and risk colors always appear with text.

## Shared visual grammar

- An asymmetric dashboard places today's flow and queue posture ahead of secondary summaries.
- Operational pages use a consistent command bar, filter/action row and layered work surface.
- Lists collapse into labelled cards below 768px; no business table requires horizontal scrolling.
- Detail and form pages use a calm two-column composition with a sticky action rail where space permits.
- Status is written in plain language. “Recorded” is never “paid”; receipts are “ready” only after
  server-confirmed Finance validation.
- Empty, loading, stale, offline, error and permission states remain explicit and actionable.
- Light mode is the fresh-browser default; dark mode uses the same semantic hierarchy and persists.
- Motion is restrained and removed under `prefers-reduced-motion`; focus remains visible and all
  action targets are at least 44px.

## Role boundaries expressed in composition

Front Office creates, records, assigns, transfers and hands off. It never validates or rejects
payments, overrides duplicates, manually issues/reissues receipts, manages refunds/disputes/cash-up/
period locks, configures services/eligibility, or administers staff access. Those controls do not
render. Subscription recovery and notifications remain discoverable but inert with their exact
Gate W / Phase 21N dependency statements.
