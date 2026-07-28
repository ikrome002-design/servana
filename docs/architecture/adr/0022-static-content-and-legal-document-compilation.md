# ADR-022 — Static Content and Legal-Document Compilation

- **Status:** Accepted (Phase UI-00 plan-adoption PR; compiler deferred to Phase UI-05).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §8.2 (required source directories), §8.8 (content compilation),
  §17.1–§17.4 (legal, FAQ and content integrity), §28.8.
- **Related:** ADR-023 (landing-image manifest); Phase 24 per-role lazy content split.

## Context

Each of the eight accounts has five role-specific source documents: landing-page copy, Data Policy,
Privacy Policy, Terms of Service and FAQ — 40 documents in total. Phase UI-00 inventoried all 40 and
recorded their paths, sizes and SHA-256 hashes in
`docs/frontend/source-inventory/role-content.json`.

Phase 24 already split role content so a visitor loads one role's landing and FAQ content rather than
all eight (`resources/spa/src/content/roleDocuments.ts`, via `import.meta.glob`). That work stands
and is not redone here.

## Problem proven

Two concrete defects were proven in this phase:

1. **A stale canonical path.** `CLAUDE.md` and `AGENTS.md` both named `docs/landing page/` (with a
   space) as the landing-copy directory. That directory has never existed in this repository. The
   real directory is `docs/landing_page/`. The stale path already cost real time — Phase 24's proof
   records the same wrong glob (`docs/proof/phase-24.md`), and `docs/PROGRESS.md` had noted
   "repository wins" without anyone correcting the workflow document. Both files are corrected in
   this phase.
2. **No compilation contract.** Content is currently imported directly from source Markdown. Plan
   §8.8 forbids reading repository Markdown dynamically from an untrusted public path in production
   and requires a deterministic build step with explicit failure modes.

## Decision

**A deterministic content compiler produces typed artifacts from source-controlled Markdown.**

1. Canonical source directories, and only these:

   | Category | Directory |
   |---|---|
   | Landing-page copy | `docs/landing_page/` |
   | Data Policy | `docs/legal/data_policy/` |
   | Privacy Policy | `docs/legal/privacy_policy/` |
   | Terms of Service | `docs/legal/terms_of_service/` |
   | FAQ | `docs/support/faq/` |

   Files are named `{role_key}_{category}.md` using the eight canonical role keys. No aliases.

2. The compiler must: read source-controlled content; validate the expected role files; compute
   source hashes; sanitize permitted Markdown structures; **preserve legal text verbatim**; produce
   typed generated artifacts; and fail on missing files, duplicate role mappings, and unsafe raw
   HTML.

3. Generated artifacts are reproducible and CI fails when they are stale.

4. **Legal text is never paraphrased, summarised, reflowed for style, or regenerated.** The compiler
   transports bytes; it does not author.

5. Every generated document records its source path and source hash, so a rendered page can always be
   traced to the exact bytes it came from.

## Scope

The content compiler, the generated content artifacts, the role-key mapping, and the canonical source
directories.

## Non-goals

Rewriting Phase 24's per-role lazy split; editing any legal or landing copy; choosing landing images
(ADR-023); building the landing pages themselves (UI-06).

## Security implications

Sanitization is the control that keeps Markdown-sourced content from becoming an XSS vector. Raw HTML
is rejected at build time rather than filtered at render time, so an unsafe construct fails CI
instead of reaching a browser. Compiling at build time also removes any runtime filesystem read of a
public path, which is what plan §8.8 is guarding against.

Hashing is integrity evidence, not a freeze: legal text may change through a reviewed PR, and the
hash simply moves with it. The guard proves source-to-generated parity, never that the law is
immutable.

## Accessibility implications

Generated legal and FAQ documents keep real heading hierarchy, real lists and real landmarks, so
screen-reader navigation works. Long documents get a skip target and a table of contents.

## Responsive implications

Legal and FAQ pages use a constrained measure for readability and never require horizontal scrolling
at any breakpoint or under zoom.

## Operational implications

Adds a build step to the frontend pipeline and a staleness check to CI. Because documentation is part
of the frontend build context, a source-path change must be validated by a real production build —
and, since the Linux build context is case-sensitive, by the Docker image builds.

## Consequences

- One canonical directory per category; the space-named directory is closed off for good.
- A missing role document becomes a build failure rather than an empty page in production.
- Legal changes remain a reviewed, traceable act.

## Rejected alternatives

- **Keep importing Markdown at runtime.** Rejected by plan §8.8; also makes sanitization a render-time
  concern on every request.
- **Move legal copy into the database.** Rejected: loses review, diff and provenance, and adds a
  migration to every legal correction.
- **Freeze legal hashes in a test.** Rejected: it would block legitimate reviewed legal changes.
  Parity between source and generated output is checked instead.
- **Normalise the space-named directory into existence for backwards compatibility.** Rejected: it
  never existed; only the stale references were real.

## Future implementation owner phase

**UI-05** (content and asset pipeline) owns the compiler and generated artifacts. **UI-06** owns the
landing pages that consume them. UI-00 owns the inventory, the canonical-path correction, and the
completeness guard.

## Required tests

- All 40 role documents exist at canonical paths, one per role per category — `UiSourceContractTest`.
- No role maps to another role's source; no duplicate active landing directory.
- `docs/landing page/` (with a space) does not exist and is referenced by no active workflow document.
- (UI-05) compiler fails on a missing file, a duplicate mapping, and unsafe raw HTML.
- (UI-05) generated-artifact staleness check in CI.

## Traceability links

`SRV-UI-CONTENT-001`, `SRV-UI-CONTENT-002` in `docs/traceability/servana-requirements.csv`;
`docs/frontend/source-inventory/role-content.json`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Related to ADR-023. Supersedes nothing.
