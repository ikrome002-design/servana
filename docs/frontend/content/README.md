# Servana content and asset pipeline

Phase UI-05. This directory documents how approved role content and curated imagery get from the
repository into the application, and what each guard is for.

Three contracts sit beside this file:

| Document | Covers |
|---|---|
| [content-contract.md](content-contract.md) | The role/category map, the compiler, the manifest, the generated modules, determinism |
| [legal-preservation-contract.md](legal-preservation-contract.md) | Why legal text is verbatim, how that is proven, and what happens when a source is unsafe |
| [image-pipeline-contract.md](image-pipeline-contract.md) | Image selection, alternative text, responsive derivatives, the brand quarantine |

## The shape of it

```text
docs/landing_page/            ┐
docs/legal/data_policy/       │
docs/legal/privacy_policy/    ├─ 40 approved source documents (the single source of truth)
docs/legal/terms_of_service/  │
docs/support/faq/             ┘
             │
             │  node scripts/generate-role-content.mjs
             ▼
resources/spa/src/content/generated/
    contentTypes.generated.ts        types
    contentManifest.generated.ts     40 rows: source path, source hash, generated hash
    index.generated.ts               40 static dynamic imports, one per account × category
    <account>/<category>.generated.ts

public/assets/landing_page_images/<account>/   61 supplied images (never modified)
config/landing-image-selection.json            the curated human decision
             │
             │  node scripts/generate-landing-images.mjs
             ▼
public/assets/landing_page_images/generated/<account>/<stem>-w<width>.{avif,webp}
public/assets/landing_page_images/manifest.json
resources/spa/src/content/generated/landingImages.generated.ts
```

## Commands

```bash
npm run content:generate   # recompile the forty documents
npm run content:check      # fail if the committed artifacts are stale
npm run assets:generate    # re-derive the image manifest and any missing derivatives
npm run assets:check       # fail if a manifest path, hash or derivative is stale or missing
node scripts/ui05-negative-controls.mjs   # prove every generator guard still fires
```

`content:check` and `assets:check` run in the `Frontend — ESLint, vue-tsc, Vitest, build` CI job.
The negative controls run there too. The production asset smoke runs in `Docker — build images`
against the built nginx image. No CI job was added or renamed.

## Rules that are easy to get wrong

1. **Never hand-edit anything under `resources/spa/src/content/generated/` or
   `public/assets/landing_page_images/generated/`.** The next `--check` will fail, and the fix is
   always to change the source and regenerate.
2. **Never edit an approved source document to make a generator pass.** Legal, landing and FAQ text
   is product-owner material. If a source is genuinely unsafe (raw HTML, a `javascript:` link), the
   generator refuses to compile it and the decision goes to the product owner — it is not
   sanitised away.
3. **Never add a second role list.** `config/account-hosts.json` is the account-key authority and
   both generators read it.
4. **Never borrow another account's image or content.** The generators fail closed on both, and an
   unknown account key throws rather than falling back.
5. **Approval vocabulary is not decoration.** `product_owner_supplied` (the artwork exists),
   `selected_for_ui06` (UI-05 chose it), `pending_ui06_visual_review` (nobody has approved how it
   looks on a page). Do not promote a status without the evidence behind it.

## What UI-05 did not do

The eight public landing pages, the public FAQ route, final image placement and any visual approval
are **UI-06**. The 160-page route contract is **UI-07**. Release-wide visual baselines are
**UI-16**. UI-05 supplies typed content and processed assets; it renders no new page.
