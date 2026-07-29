# ADR-023 — Role-Specific Landing-Image Manifest

- **Status:** Accepted (Phase UI-00 plan-adoption PR; manifest and pipeline deferred to Phase
  UI-05/UI-06).
- **Date:** 2026-07-28
- **Required by:** UI/UX plan §8.7 (landing-image selection), §22.2 (image delivery), §28.2;
  brand identity (logo and imagery usage).
- **Related:** ADR-022 (content compilation), ADR-021 (theme), ADR-025 (visual regression).

## Context

The product owner supplied 61 landing images across the eight role directories under
`public/assets/landing_page_images/`. Phase UI-00 inventoried every one — dimensions, aspect ratio,
byte size, SHA-256, and cross-role duplicate detection — in
`docs/frontend/source-inventory/landing-images.json`.

| Role key | Supplied images |
|---|---:|
| `super_administrator` | 10 |
| `merchant_administrator` | 8 |
| `merchant_branch` | 9 |
| `merchant_finance` | 5 |
| `merchant_human_resource` | 8 |
| `merchant_front_office` | 6 |
| `merchant_personnel` | 7 |
| `merchant_audit` | 8 |
| **Total** | **61** |

All 61 are valid PNGs. No duplicate content was found across roles. The supplied set matches the
product-owner baseline exactly.

## Problem proven

The supplied set is a *source pool*, not a layout. Rendering all of a role's images would produce
pages of 5–10 large screenshots with no editorial intent — one of the causes of the "visually
jumbled" experience this programme exists to correct. Several supplied images are near two megabytes
each; shipping ten of them unprocessed would break the plan §22.1 frontend budgets on the very pages
a first-time visitor sees.

There is also a real risk of a role showing another role's product screenshots, which would
misrepresent the product to that audience.

## Decision

**A typed, reviewed manifest selects and describes the images each landing page actually renders.**

1. The manifest lives at `public/assets/landing_page_images/manifest.json` (or an equivalent typed
   artifact) and records, per **selected** image: account key, source file, landing section,
   alternative text, intrinsic dimensions, aspect ratio, focal position, mobile crop, tablet crop,
   desktop crop, loading strategy, generated derivative paths, and approval status.
2. **Approximately two to four primary supplied images per landing page**, unless the authoritative
   content genuinely requires another number. Never render every image merely because it exists.
3. A role's landing page uses only that role's directory. Using another role's image requires
   explicit product-owner approval recorded in the manifest.
4. The supplied art is not materially altered. Optimisation derivatives (resize, modern formats,
   responsive `srcset`) are allowed and expected.
5. Every image carries meaningful alternative text, or is marked decorative — never an empty
   `alt` on a meaningful image, never a filename as `alt`.
6. Both themes are considered: an image must remain legible against the light and dark surface it
   sits on (ADR-021).

## Scope

The selection manifest, the derivative pipeline, alt-text authoring, loading strategy, and
landing-page image rendering.

## Non-goals

Selecting, cropping, optimising, altering or rendering any image in UI-00. Adding new source images.
Building the landing pages (UI-06).

## Security implications

Low. Images are static assets served with fingerprinted, immutable caching. The manifest is
build-time data, not user input. No image is user-uploaded, so no upload-scanning path is involved —
this is unrelated to the Phase 10F scanned-upload pipeline.

## Accessibility implications

Alternative text is the substance of this decision, not a checkbox. Decorative images are hidden from
assistive technology; meaningful ones describe what they show. Text is never baked into an image as
the only way to read it. Focal-point cropping ensures the subject survives at mobile sizes rather
than being cropped to an unreadable fragment.

## Responsive implications

Per-breakpoint crops and `srcset`/`sizes` deliver an appropriately sized image at each of the shipped
ranges. Intrinsic dimensions are declared so layout does not shift while images load.

## Operational implications

The derivative pipeline runs at build time and its output is reproducible. Source images stay in the
repository as the authority; derivatives are generated.

## Consequences

- Landing pages become edited compositions rather than image dumps.
- The image budget is explicit and reviewable per page.
- Adding an image is a manifest change with alt text and approval, not a drag-and-drop.

## Rejected alternatives

- **Render every image in the role directory.** Rejected by plan §8.7; produces the jumbled result
  and blows the performance budget.
- **Pick images at runtime from a directory listing.** Rejected: non-deterministic, unreviewable, and
  gives no place to record alt text or crops.
- **Ship the supplied PNGs unprocessed.** Rejected: ~2 MB per image against a page budget measured in
  hundreds of kilobytes.
- **Generate or substitute stock imagery.** Rejected: the supplied art is the approved art, and
  inventing product imagery would misrepresent the product.

## Future implementation owner phase

**UI-05** (content and asset pipeline) owns the derivative pipeline. **UI-06** (eight public landing
pages) owns the curated selection, section mapping and alt text. UI-00 owns the source inventory
only and makes no selection claim.

## Required tests

- All eight role directories exist and the inventory matches the filesystem — `UiSourceContractTest`.
- Per-role counts match the approved baseline (10/8/9/5/8/6/7/8 = 61).
- Every inventoried file is a valid image with readable dimensions.
- No unresolved cross-role duplicate.
- (UI-06) every rendered image is in the manifest, belongs to its own role, and has alt text.
- (UI-06) landing-page weight stays within the plan §22.1 budget.

## Traceability links

`SRV-UI-ASSET-002` in `docs/traceability/servana-requirements.csv`;
`docs/frontend/source-inventory/landing-images.json`; `docs/proof/ui-00.md`.

## Superseded or related ADRs

Related to ADR-022 (content) and ADR-021 (theme). Supersedes nothing.
