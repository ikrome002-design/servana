# Legal text preservation contract

Phase UI-05. Authority: UI/UX plan §17.2 (legal text preservation), §17.3 (source hashes),
§17.4 (safe rendering); CLAUDE.md §9 (never touch legal/policy copy without asking).

## The rule

Twenty-four documents — Data Policy, Privacy Policy and Terms of Service for each of the eight
accounts — are reproduced **byte for byte**. Not "semantically equivalent", not "reflowed", not
"with typos corrected": the same bytes.

Forbidden, without exception: rewriting, shortening, summarising, correcting wording or spelling,
merging two roles' documents, injecting a generic clause, and hiding a section through CSS.

## How preservation actually works

`scripts/generate-role-content.mjs` reads the source file, verifies the bytes round-trip through
UTF-8, and writes them into the generated module as a single string literal produced by
`JSON.stringify`. Decoding that literal yields the original bytes exactly.

The source files are **read-only inputs**. The generator never writes into `docs/legal/**`.

## How it is proven

`tests/Feature/Content/LegalContentVerbatimTest.php` does not take the generator's word for it. It
extracts the `const markdown = "…";` literal from each generated module and decodes it with PHP's
own JSON decoder, then compares against the file on disk. A bug in the emitter therefore cannot mark
its own homework.

Four further checks make a failure name *what* changed rather than only that bytes differ: line
count, heading count, list-item count and link count must all match the source. The test also runs
`git status --porcelain -- docs/legal` and requires an empty result, so a run that modified an
approved source would fail even if its own output still matched.

On the frontend, `resources/spa/src/content/generatedContent.spec.ts` loads all twenty-four
documents through the runtime loader and compares each against the file read from disk.

Every document's `sourceSha256`, `sourceBytes`, generated module path and generated module hash are
recorded in `docs/frontend/audits/ui-05/legal-hash-manifest.json`.

## Rendering

Legal pages render through UI-04's `SvLegalDocument`, which uses the repository's own
`renderMarkdown`. There is exactly one markdown engine; UI-05 did not add a second.

`renderMarkdown` escapes `&`, `<` and `>` before applying inline formatting, so no raw HTML can
survive from content into the DOM. Link targets pass through `safeHref`, which allows only:

```text
https://    http://    mailto:    /root-relative    #fragment
```

Anything else becomes `#` — the link still renders and the document still reads correctly, but it
cannot navigate anywhere dangerous. `javascript:`, `vbscript:`, unapproved `data:`,
protocol-relative targets, event-handler attributes, `<script>`, `<iframe>`, `<object>` and
`<embed>` are all unreachable by construction.

## Unsafe source content

None of the forty approved documents contains raw HTML today, and the only link scheme any of them
uses is `mailto:` (159 links). The compiler scans on every run.

If an unsafe construct is ever introduced, the compiler **stops** and names the exact file, line and
element. It does not strip it, does not render it and does not carry on. Sanitising approved legal
text without approval would change what the product owner published; the decision belongs to them.

## Routes

`/legal/:role/:doc` resolves the document type through an explicit map
(`terms-of-service` → `terms_of_service`, and so on) and then through the generated per-account
loader. An unknown role or type renders the not-found boundary. **It never falls back to another
account's document** — the cross-role leak UI/UX plan §17.1 forbids.

The rendered `<article>` carries `data-content-source` and `data-content-sha256`, so the browser
proof can assert *which* file produced what is on screen rather than only that something rendered.

UI-05 changed no route, added no API, and changed no authorization. The public FAQ route is UI-06.
