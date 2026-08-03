# Landing-image and brand-asset pipeline contract

Phase UI-05. Governs `scripts/generate-landing-images.mjs`,
`config/landing-image-selection.json` and `config/brand-asset-quarantine.json`.
Authority: UI/UX plan §8.7 (landing-image selection), §22.2 (image delivery), §17 (content
integrity); ADR-025.

## Selection

Sixty-one images were supplied across the eight accounts. Rendering all of them is explicitly
forbidden (§8.7). UI-05 selects **four per account — thirty-two in total**.

The decision lives in `config/landing-image-selection.json`, which records only what a human chose:
the file, the landing region it belongs to, its loading strategy and its alternative text.
Everything measurable — dimensions, aspect ratio, hashes, byte lengths, derivative paths and
derivative hashes — is read off the files by the generator and can never be typed in by hand.

Every role's set follows the same reading order: the product in use (**hero**), the situation it
addresses (**problem**), the numbered flow its own source content already describes
(**how it works**), and then either the product surface (**product showcase**) or the access-control
story (**security**), whichever that account's artwork actually supplies.

Social-proof and testimonial artwork is deliberately **not** selected. Those regions carry unverified
customer evidence and are marked not renderable until the product owner decides (§8.4); illustrating
a claim that may not be published would be worse than leaving the slot empty.

The generator refuses to run when a role selects fewer than two or more than four images, selects a
file outside its own directory, selects the same file twice, maps two images to one region, or
targets a region its own landing document does not supply.

## Alternative text

Alternative text describes what the illustration shows and why it is there. It never names a person,
never states a customer outcome, adoption figure or rating, never uses the file name, and never
opens with "image of".

A decorative image carries empty alt text and nothing else; a non-decorative image carries at least
twenty characters. Both directions are linted — an empty alt on a meaningful image and a non-empty
alt on a decorative one are equally wrong.

## Derivatives

For each selected image, at 640 / 1024 / 1440 px wide:

- **AVIF** — quality 50, effort 4, chroma 4:4:4, lossless off
- **WebP** — quality 78, effort 4, smart subsample off, alpha quality 100

**192 derivatives** in total (32 images × 3 widths × 2 formats), about 9.8 MB.

Resizing is `fit: inside`, `kernel: lanczos3`, `withoutEnlargement: true`. A candidate width above
the source width is **dropped, not synthesised**: the pipeline never invents pixels the supplied
artwork did not have. Volatile metadata is stripped; the images carry no timestamps or paths.

### Why there is no downscaled PNG

The `<img>` fallback inside a `<picture>` is the **untouched original**. Generating PNG derivatives
at three widths would add roughly seventy megabytes of binary to the repository to serve a path that
AVIF and WebP already cover in every browser that reaches it — and the original is already served,
byte-identical, at the same URL. This is a recorded engineering decision, not an omission.

### Crops

All three breakpoint crops are `preserve_source_frame`: the full frame, at every width. Nothing is
cropped, so no subject can be cropped out of shot — the failure mode §21.3 warns about.

The manifest records a single declared focal point (0.5, 0.45) for UI-06 to use as `object-position`
when it places an image in a container whose aspect ratio differs from the source. A per-image focal
coordinate would record a measurement nobody took.

### Determinism

Encoder options, resize kernel, enlargement policy and metadata handling are all pinned, so
re-encoding an unchanged source on the same toolchain reproduces the same bytes. This was verified
by deleting the entire generated tree and regenerating: 192 files, byte-identical.

`--check` **never re-encodes**. It verifies that every recorded path exists, decodes, and still
hashes to the recorded value — so the check does not depend on the runner's libvips build, and CI
never rewrites a committed binary.

## Originals

The 61 supplied images are read-only. `LandingImageDerivativeContractTest` re-hashes every one of
them against the UI-00 inventory on each run. Nothing was recoloured, redrawn, retouched or
recompressed.

## The approved logo

One logo, `public/assets/brand/Logo.png` (500 × 500 PNG), referenced by public path from all eight
accounts. It is never copied into per-role directories, never recoloured and never regenerated.

`public/assets/brand/Logo.svg` was deleted under product-owner authority (commit `49160cd`). It must
stay absent and unreferenced. The contract asserts both — and it strips comments before scanning, so
a source file may still *explain* the deletion without tripping the guard.

## UI01-ASSET-002 — the brand quarantine

UI-01 proved eleven unapproved brand working files (`PNG.png` and ten `v1/ChatGPT Image …png`) were
shipping inside the public web root of the production image and being served.

They were **moved, not deleted**, with `git mv`, into `docs/brand/quarantine/ui01-asset-002/`.
`docs/` is never copied into the nginx image, so the bytes are preserved and reviewable while being
unreachable over HTTP. Every hash was verified against the UI-00 inventory before the move and
re-verified from the archived bytes afterwards.

`config/brand-asset-quarantine.json` is the reviewed decision record. It is deliberately separate
from the UI-00 source inventory: that inventory is a live statement about what is on disk, so after
the move it correctly reports zero unreferenced files under the brand tree and can no longer be the
authority for what was quarantined.

The eight protected assets — `Logo.png`, the four favicons, the two Android icons and
`site.webmanifest` — were not touched, and their hashes are re-verified on every run.

Restoring any quarantined file to `public/assets/brand/` requires product-owner approval.

## Guards

`node scripts/ui05-negative-controls.mjs` breaks exactly one thing in a disposable copy of the
repository and requires the generator to fail, naming that problem — seventeen controls covering a
missing source, a duplicated mapping, an edited source, a hand-edited generated module, unsafe HTML,
an unsafe link, an unmapped landing section, a missing image, a missing derivative, a stale
derivative hash, a cross-role image, a region the source lacks, missing alt text, too many images,
a quarantined file returning to the public tree, altered archived bytes, and a restored `Logo.svg`.

Each control proves the unmutated sandbox passes **first**, so a control can never pass because the
copy was already broken. Nothing is ever mutated in the working tree.
