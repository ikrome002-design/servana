# Role-content compilation contract

Phase UI-05. Governs `scripts/generate-role-content.mjs` and everything it emits.
Authority: UI/UX plan §8.8 (content compilation), §17.1–§17.4 (content integrity).

## The problem this replaces

Phases 11 and 24 loaded approved content with `import.meta.glob('../../../../docs/**/*.md', '?raw')`.
That reaches out of the SPA source tree at build time and resolves whatever is on disk, and it
resolves a document by matching a path **suffix** against the glob result — so a missing file could
be answered with a sibling's, and no build ever noticed a document had changed.

UI/UX plan §8.8 rule 1 forbids reading repository Markdown dynamically from an untrusted path in
production, and requires a deterministic build step instead. This is that build step.

## The one role map

`config/account-hosts.json` is the account-key authority (ADR-016, Phase UI-02). The compiler reads
it and refuses to run if any account's `public_content_key` or `legal_content_key` is not its own
key. There is no second role list anywhere in the pipeline.

**8 accounts × 5 categories = 40 documents**, each claimed exactly once:

| Category | Directory | Suffix |
|---|---|---|
| `landing` | `docs/landing_page` | `_landing_page_content.md` |
| `data_policy` | `docs/legal/data_policy` | `_data_policy.md` |
| `privacy_policy` | `docs/legal/privacy_policy` | `_privacy_policy.md` |
| `terms_of_service` | `docs/legal/terms_of_service` | `_terms_of_service.md` |
| `faq` | `docs/support/faq` | `_faq.md` |

The canonical landing directory is `docs/landing_page/` with an **underscore**. A space-named
sibling has never existed (proven at UI-00); do not recreate it.

## What the compiler refuses to do

It exits non-zero, naming the file and line, when:

- a source file is missing;
- a role/category pair is claimed twice, or a source path is claimed twice;
- an account cross-maps to another account's content key;
- a source is not valid UTF-8 (the generated string could not reproduce its bytes);
- a source path resolves outside its canonical directory;
- **any raw HTML element appears** in an approved document;
- **any link target** is not `https:`, `http:`, `mailto:`, root-relative or a fragment;
- a landing section's heading maps to none of the sixteen plan regions;
- two landing sections map to the same region;
- an FAQ item has an empty question or answer, a duplicate number, or a duplicate id.

Unsafe content is **refused, never stripped**. Silently sanitising approved legal text would change
what the product owner published; refusing forces a recorded decision.

## Determinism

Every generated file is written with LF endings and exactly one trailing newline, and every list is
sorted (account key, then category in canonical order, then source path). Two consecutive runs
produce byte-identical output; `--check` regenerates in memory and exits non-zero if any committed
artifact differs.

### The build timestamp

`sourceTimestamp` is resolved without ever reading the clock, in this order:

1. `SOURCE_DATE_EPOCH`, the reproducible-builds standard;
2. the value already in the manifest, when the content version is unchanged — nothing about the
   sources moved, so the timestamp must not either, and this is what makes `--check` work on the
   shallow clone CI checks out;
3. the committer time of the newest commit touching the forty source files.

There is deliberately no `Date.now()` fallback: a wall clock would make the bytes irreproducible,
which is the one property the pipeline exists to guarantee. The RESOLUTION METHOD is not written
into any artifact, because it legitimately differs between a first generation and every later one.

### The content version

`contentVersion` is `sha256` over the sorted `account \t category \t path \t sourceHash` tuples. It
is a reproducible integrity digest, not a secret: anyone can recompute it from files committed in
this repository, and it authenticates and authorises nothing. If a secret scanner ever flags it,
investigate that finding on its own evidence — do not widen an existing suppression to cover it.

## Generated modules

```text
contentTypes.generated.ts      types only
contentManifest.generated.ts   40 rows of provenance (small; safe to import eagerly)
index.generated.ts             40 STATIC dynamic imports + fail-closed loaders
<account>/<category>.generated.ts
```

Every specifier in `index.generated.ts` is a string literal. A template-built specifier would defeat
Vite's code splitting *and* let a runtime value choose which file loads; both are forbidden and both
are asserted against. An unknown account key or category **throws** — it never falls back to
`merchant_branch`, `merchant_administrator` or a generic object.

Legal modules carry the full document text verbatim (see the legal contract). FAQ and landing
modules carry the compiled pieces only: shipping a second copy of the same bytes beside them would
double each chunk for no consumer, and the source hash in `meta` already pins the file.

## Landing regions

The sixteen semantic regions of UI/UX plan §8.3 are recorded for every account, present or not.

Top-level sections are detected by a rule that works across all three source shapes without a
per-role special case: a heading is a top-level section when it sits at the shallowest level any
numbered heading uses in that document **and** its number continues an unbroken 1, 2, 3 … run. That
second condition matters — Merchant Branch writes its "How It Works" steps as `## 1. Get started` …
`## 5. Review what matters`, at the same heading level as its top-level sections, and a level-only
rule would truncate the section at step 1.

Heading text maps to a region through an **explicit, exhaustive table**. An unknown heading is a
hard error; a fuzzy match would file one role's section under the wrong region and UI-06 would
render it in the wrong place.

Two states are recorded rather than resolved:

- `missing_from_source` — the account's document supplies no such section. Recorded with
  `product_owner_content_decision_required` and owner `UI-06`. **Nothing is invented to fill it**
  (UI/UX plan §8.3).
- `renderPermitted: false` on a present section — the copy exists but may not be published as-is.
  Today this is the testimonials region for four accounts, which carry attributed customer quotes
  with no verified source (and, for Merchant Personnel, a note in the source itself saying they are
  placeholders). UI/UX plan §8.4 forbids publishing fabricated customer evidence, so the section is
  compiled verbatim, flagged, and left for the product owner.

## FAQ

A question is any heading whose text begins with dotted numbering (`N.M`). **Heading level is not
part of the rule.** The previous runtime parser accepted `##` only, and Merchant Administrator
writes sixty of its questions at `###` — those sixty were silently dropped from every FAQ surface
(defect `UI05-FAQ-001`). A `#` heading with a single number is a category divider and carries
forward to the questions beneath it.

Identity is `faq-<number-with-dashes>-<question-slug>`. It derives from the document's own numbering
rather than an array index, so inserting a question elsewhere in the file cannot renumber this one,
and it contains no clock or hash of volatile input.
