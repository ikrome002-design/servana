#!/usr/bin/env node
// Phase UI-05 — deterministic role-content compiler (UI/UX plan §8.8, §17.1–§17.4).
//
// Replaces the production-time `import.meta.glob('../../../../docs/**/*.md', '?raw')` discovery
// that Phase 11/24 used with SOURCE-CONTROLLED GENERATED ARTIFACTS. The Markdown in `docs/**`
// remains the single source of truth and is never hand-copied — this script copies it, byte for
// byte, into typed modules whose hashes are checked in CI.
//
// Why that matters (§8.8 rule 1): globbing reaches OUT of the SPA source tree at build time and
// resolves whatever happens to be on disk. A generated artifact instead pins an exact document by
// exact hash, so a changed, missing, duplicated or cross-mapped source fails a check rather than
// silently shipping.
//
// Outputs (all deterministic; a second run produces no diff):
//
//   resources/spa/src/content/generated/contentTypes.generated.ts
//   resources/spa/src/content/generated/contentManifest.generated.ts
//   resources/spa/src/content/generated/index.generated.ts
//   resources/spa/src/content/generated/<account>/<category>.generated.ts     (40 modules)
//   docs/frontend/audits/ui-05/content-source-manifest.json
//   docs/frontend/audits/ui-05/legal-hash-manifest.json
//   docs/frontend/audits/ui-05/faq-manifest.json
//   docs/frontend/audits/ui-05/content-parity.json
//
// Usage: node scripts/generate-role-content.mjs [--check]

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');

const GENERATED_DIR = 'resources/spa/src/content/generated';
const AUDIT_DIR = 'docs/frontend/audits/ui-05';
const HOST_REGISTRY = 'config/account-hosts.json';

/** Parser/renderer contract version. Bump when the parsed SHAPE changes, never for content edits. */
const PARSER_VERSION = '1.0.0';

/**
 * The five role-specific content categories. `directory` is the canonical location proven by
 * Phase UI-00 — `docs/landing_page/` with an UNDERSCORE; a space-named sibling has never existed.
 */
const CATEGORIES = [
  { key: 'landing', directory: 'docs/landing_page', suffix: '_landing_page_content.md', kind: 'landing', module: 'landing' },
  { key: 'data_policy', directory: 'docs/legal/data_policy', suffix: '_data_policy.md', kind: 'legal', module: 'data-policy' },
  { key: 'privacy_policy', directory: 'docs/legal/privacy_policy', suffix: '_privacy_policy.md', kind: 'legal', module: 'privacy-policy' },
  { key: 'terms_of_service', directory: 'docs/legal/terms_of_service', suffix: '_terms_of_service.md', kind: 'legal', module: 'terms-of-service' },
  { key: 'faq', directory: 'docs/support/faq', suffix: '_faq.md', kind: 'faq', module: 'faq' },
];

/** The sixteen semantic landing regions the UI/UX plan §8.3 binds, in plan order. */
const LANDING_REGIONS = [
  'header_navigation', 'hero', 'social_proof', 'problem', 'solution', 'features',
  'how_it_works', 'benefits', 'product_showcase', 'use_cases', 'testimonials',
  'pricing', 'security', 'faq', 'final_cta', 'footer',
];

/**
 * Regions that can carry a curated landing image. This is the OUTER bound only — the landing-image
 * manifest decides which of them actually receive one, and it may never target a region absent from
 * this set. Header, footer, pricing, FAQ, final CTA, social proof and testimonials are excluded:
 * they are text or evidence surfaces, and §8.4 in particular forbids illustrating a customer claim.
 */
const IMAGE_CAPABLE_REGIONS = new Set([
  'hero', 'problem', 'solution', 'features', 'how_it_works',
  'benefits', 'product_showcase', 'use_cases', 'security',
]);

/**
 * EXPLICIT heading → region map. Deliberately exhaustive rather than fuzzy: an unmapped heading is
 * a hard error, because a near-miss guess would silently file one role's section under the wrong
 * region and UI-06 would render it in the wrong place.
 *
 * Keys are the heading text with the leading `N.` stripped, lowercased, `&` → `and`, whitespace
 * collapsed. The VERBATIM source heading is what the generated artifact carries; this map only
 * decides which of the sixteen plan regions the section occupies.
 */
const REGION_BY_HEADING = new Map(Object.entries({
  'header / navigation': 'header_navigation',
  'hero section': 'hero',
  'trust / social proof section': 'social_proof',
  'social proof / trust statement': 'social_proof',
  'social proof / trust section': 'social_proof',
  // Super Administrator's third section. Positionally and semantically the plan's region 3
  // ("social proof or approved trust evidence"): it states who the platform serves and makes no
  // customer claim, which is exactly the §8.4 factual alternative.
  'platform positioning section': 'social_proof',
  'problem section': 'problem',
  'solution / value proposition section': 'solution',
  'solution section': 'solution',
  'features section': 'features',
  'feature section': 'features',
  'how it works section': 'how_it_works',
  'benefits section': 'benefits',
  'product showcase section': 'product_showcase',
  'use cases section': 'use_cases',
  'testimonial section': 'testimonials',
  'testimonials section': 'testimonials',
  // Human Resource occupies plan region 11 with a factual trust statement instead of testimonials —
  // the §8.4 "approved alternative that makes no customer claim" state.
  'trust statement section': 'testimonials',
  'pricing section': 'pricing',
  'pricing / access section': 'pricing',
  'faq section': 'faq',
  'security / trust section': 'security',
  'security / compliance section': 'security',
  'security / control section': 'security',
  'security section': 'security',
  'final cta section': 'final_cta',
  'footer': 'footer',
}));

// ---------------------------------------------------------------------------------------------
// Primitives
// ---------------------------------------------------------------------------------------------

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const asJson = (value) => `${JSON.stringify(value, null, 2)}\n`;

/** Every generated file ends with exactly one newline and uses LF, on every platform. */
function normaliseOutput(text) {
  return `${text.replace(/\r\n/g, '\n').replace(/\n+$/, '')}\n`;
}

const written = [];

function writeArtifact(relPath, contents) {
  const absolute = join(ROOT, relPath);
  const normalised = normaliseOutput(contents);
  const existing = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;
  const changed = existing !== normalised;

  if (changed && !CHECK_ONLY) {
    mkdirSync(dirname(absolute), { recursive: true });
    writeFileSync(absolute, normalised, 'utf8');
  }
  written.push({ path: relPath, changed, sha256: sha256(Buffer.from(normalised, 'utf8')) });

  return normalised;
}

const problems = [];
const fail = (message) => problems.push(message);

/** A TypeScript string literal that round-trips to the EXACT source bytes. */
const literal = (value) => JSON.stringify(value);

function slugify(text) {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64);
}

const HEADING = /^(#{1,6})\s+(.*)$/;

// ---------------------------------------------------------------------------------------------
// Safety scanners (§8.8 rules 9 + 10, §17.4)
// ---------------------------------------------------------------------------------------------

/**
 * Raw HTML is rejected outright rather than stripped. Stripping would silently change approved
 * legal text; refusing forces a recorded product-owner decision (§15 of the phase contract).
 */
const RAW_HTML = /<\s*\/?\s*([a-zA-Z][a-zA-Z0-9-]*)(\s[^>]*)?>/g;
const MARKDOWN_LINK = /\[([^\]]*)\]\(([^)]+)\)/g;
const SAFE_LINK = /^(?:https?:\/\/|mailto:|\/|#)/i;

function scanSafety(relPath, text) {
  const findings = [];

  for (const match of text.matchAll(RAW_HTML)) {
    const line = text.slice(0, match.index).split('\n').length;
    findings.push({ kind: 'raw_html', path: relPath, line, detail: match[0].slice(0, 120), element: match[1].toLowerCase() });
  }
  for (const match of text.matchAll(MARKDOWN_LINK)) {
    const href = match[2].trim();
    if (SAFE_LINK.test(href)) {
      continue;
    }
    const line = text.slice(0, match.index).split('\n').length;
    findings.push({ kind: 'unsafe_link', path: relPath, line, detail: href.slice(0, 120), element: null });
  }
  // Protocol-relative targets are ambiguous about their scheme, so they are treated as unsafe.
  for (const match of text.matchAll(/\]\(\/\/[^)]+\)/g)) {
    const line = text.slice(0, match.index).split('\n').length;
    findings.push({ kind: 'unsafe_link', path: relPath, line, detail: match[0].slice(0, 120), element: null });
  }

  return findings;
}

// ---------------------------------------------------------------------------------------------
// Landing-section compiler
// ---------------------------------------------------------------------------------------------

/**
 * Split a landing document into its top-level numbered sections.
 *
 * Two source shapes exist and both must work without a per-role special case:
 *   * seven roles number their sections at `##`;
 *   * Super Administrator numbers them at `#`.
 * A THIRD complication is real: Merchant Branch writes the "How It Works" steps as `## 1. Get
 * started` … `## 5. Review what matters` — the SAME heading level as its top-level sections. A
 * level-only rule would truncate that section at step 1.
 *
 * So a heading is a top-level section only when (a) it sits at the shallowest level any numbered
 * heading uses in this document, and (b) its number is the next one in an unbroken 1,2,3… run.
 * Restarting at 1 after section 7 identifies a nested step list, not section 1 again.
 */
function splitLandingSections(text) {
  const lines = text.split('\n');
  const numbered = [];

  for (let i = 0; i < lines.length; i += 1) {
    const heading = HEADING.exec(lines[i].trim());
    if (heading === null) {
      continue;
    }
    const numeric = /^(\d+)[.)]\s+(.*)$/.exec(heading[2].trim());
    if (numeric === null) {
      continue;
    }
    numbered.push({ index: i, level: heading[1].length, number: Number(numeric[1]), title: numeric[2].trim() });
  }

  if (numbered.length === 0) {
    return [];
  }

  const topLevel = Math.min(...numbered.map((h) => h.level));
  const sections = [];
  let expected = 1;

  for (const heading of numbered) {
    if (heading.level !== topLevel || heading.number !== expected) {
      continue;
    }
    sections.push(heading);
    expected += 1;
  }

  return sections.map((heading, i) => {
    const endLine = i + 1 < sections.length ? sections[i + 1].index : lines.length;
    return {
      number: heading.number,
      heading: heading.title,
      heading_line: heading.index + 1,
      body_start_line: heading.index + 2,
      body_end_line: endLine,
      body: lines.slice(heading.index + 1, endLine).join('\n').trim(),
    };
  });
}

/** Normalise a heading for the region lookup. Never used as the rendered text. */
function regionLookupKey(heading) {
  return heading
    .replace(/\*\*/g, '')
    .replace(/&/g, 'and')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase()
    .replace(/[.:]+$/, '');
}

/**
 * Decide whether a compiled section may be rendered as-is by UI-06.
 *
 * §8.4 forbids production ever showing a fabricated quote, name, rating or statistic. Some supplied
 * testimonial sections carry attributed quotes with no verified source, and Merchant Personnel says
 * in the source itself that its quotes are placeholders. UI-05 does not rewrite, delete or approve
 * them — it compiles them verbatim and marks them not renderable until the product owner decides.
 */
function contentRestrictionFor(region, body) {
  if (region !== 'testimonials') {
    return { restriction: 'none', render_permitted: true, reason: null };
  }
  const placeholderNote = /(replaced with real testimonials|once real customer quotes are available|do not publish them as actual customer quotes)/i.test(body);
  const attributedQuote = /^>\s*\S/m.test(body) || /[“"][^“”"]{20,}[”"]/.test(body);

  if (placeholderNote) {
    return {
      restriction: 'placeholder_testimonial_marked_in_source',
      render_permitted: false,
      reason: 'The source document states these quotes are suggestions to be replaced before launch. UI/UX plan §8.4 forbids publishing them as customer evidence.',
    };
  }
  if (attributedQuote) {
    return {
      restriction: 'unverified_customer_evidence',
      render_permitted: false,
      reason: 'The section presents an attributed customer quote with no verified source. UI/UX plan §8.4 permits publication only after verified evidence is supplied or a factual alternative is approved.',
    };
  }

  return {
    restriction: 'none',
    render_permitted: true,
    reason: 'Factual trust statement making no customer claim — the §8.4 approved-alternative state.',
  };
}

function compileLanding(accountKey, relPath, text) {
  const raw = splitLandingSections(text);
  const sections = [];
  const seen = new Set();

  for (const section of raw) {
    const key = regionLookupKey(section.heading);
    const region = REGION_BY_HEADING.get(key);

    if (region === undefined) {
      fail(`${relPath}: landing section ${section.number} "${section.heading}" maps to no plan region. Add an explicit entry to REGION_BY_HEADING — guessing would file it under the wrong region.`);
      continue;
    }
    if (seen.has(region)) {
      fail(`${relPath}: two sections map to the region "${region}"; the mapping must be one-to-one.`);
      continue;
    }
    seen.add(region);

    const restriction = contentRestrictionFor(region, section.body);
    sections.push({
      region,
      source_number: section.number,
      source_heading: section.heading,
      source_heading_line: section.heading_line,
      source_body_lines: [section.body_start_line, section.body_end_line],
      markdown: section.body,
      markdown_sha256: sha256(Buffer.from(section.body, 'utf8')),
      presence: 'present_in_source',
      content_restriction: restriction.restriction,
      render_permitted: restriction.render_permitted,
      restriction_reason: restriction.reason,
      image_capable: IMAGE_CAPABLE_REGIONS.has(region),
    });
  }

  // Absent regions are RECORDED, never invented (§8.3: "The IDE must not invent missing commercial
  // or customer evidence"). UI-06 reads these rows and asks the product owner.
  for (const region of LANDING_REGIONS) {
    if (seen.has(region)) {
      continue;
    }
    sections.push({
      region,
      source_number: null,
      source_heading: null,
      source_heading_line: null,
      source_body_lines: null,
      markdown: '',
      markdown_sha256: sha256(Buffer.from('', 'utf8')),
      presence: 'missing_from_source',
      content_restriction: 'product_owner_content_decision_required',
      render_permitted: false,
      restriction_reason: `The ${accountKey} landing document supplies no ${region} section. UI-06 must obtain approved copy; nothing may be fabricated to fill it.`,
      image_capable: IMAGE_CAPABLE_REGIONS.has(region),
    });
  }

  sections.sort((a, b) => LANDING_REGIONS.indexOf(a.region) - LANDING_REGIONS.indexOf(b.region));

  return sections;
}

// ---------------------------------------------------------------------------------------------
// FAQ compiler
// ---------------------------------------------------------------------------------------------

/**
 * Parse an FAQ document into its questions.
 *
 * A question is a heading whose text starts with dotted numbering (`1.2 …`). The runtime parser
 * this replaces accepted `##` ONLY, which silently dropped sixty Merchant Administrator questions
 * written at `###` (defect UI05-FAQ-001). Level is therefore not part of the rule; the dotted
 * number is. A `#` heading with a single number (`# 3. Access and Login`) is a category divider and
 * carries the category forward for every question beneath it.
 */
function compileFaq(accountKey, relPath, text) {
  const lines = text.split('\n');
  const items = [];
  let category = null;
  let current = null;

  const push = () => {
    if (current === null) {
      return;
    }
    const answer = current.answer.join('\n').replace(/^-{3,}$/gm, '').trim();
    items.push({ ...current, answer, end_line: current.end_line });
    current = null;
  };

  for (let i = 0; i < lines.length; i += 1) {
    const heading = HEADING.exec(lines[i].trim());

    if (heading !== null) {
      const question = /^(\d+\.\d+)\s+(.*)$/.exec(heading[2].trim());
      if (question !== null) {
        push();
        current = {
          number: question[1],
          question: question[2].replace(/\*\*/g, '').trim(),
          category,
          heading_level: heading[1].length,
          start_line: i + 1,
          end_line: i + 1,
          answer: [],
        };
        continue;
      }
      const divider = /^(\d+)[.)]\s+(.*)$/.exec(heading[2].trim());
      if (divider !== null && heading[1].length === 1) {
        category = divider[2].trim();
      }
      push();
      continue;
    }
    if (current !== null) {
      if (/^-{3,}$/.test(lines[i].trim())) {
        continue;
      }
      current.answer.push(lines[i]);
      current.end_line = i + 1;
    }
  }
  push();

  const compiled = [];
  const seenIds = new Set();
  const seenNumbers = new Set();

  for (let order = 0; order < items.length; order += 1) {
    const item = items[order];

    if (item.question === '') {
      fail(`${relPath}:${item.start_line}: FAQ item ${item.number} has an empty question.`);
      continue;
    }
    if (item.answer === '') {
      fail(`${relPath}:${item.start_line}: FAQ item ${item.number} "${item.question}" has an empty answer.`);
      continue;
    }
    if (seenNumbers.has(item.number)) {
      fail(`${relPath}: FAQ number ${item.number} appears more than once; numbering is the stable identity and must be unique.`);
      continue;
    }
    seenNumbers.add(item.number);

    // Identity comes from the document's OWN numbering plus its question slug — stable across
    // insertions anywhere else in the file, unlike an array index, and clock-free.
    const id = `faq-${item.number.replace('.', '-')}-${slugify(item.question)}`;
    if (seenIds.has(id)) {
      fail(`${relPath}: duplicate FAQ id ${id}.`);
      continue;
    }
    seenIds.add(id);

    compiled.push({
      id,
      number: item.number,
      question: item.question,
      answer: item.answer,
      category: item.category,
      account_key: accountKey,
      source_path: relPath,
      source_lines: [item.start_line, item.end_line],
      order: compiled.length,
    });
  }

  return compiled;
}

// ---------------------------------------------------------------------------------------------
// Deterministic source timestamp (§14.2 — never a wall clock)
// ---------------------------------------------------------------------------------------------

/**
 * Resolve the content build timestamp WITHOUT reading the clock, in this order:
 *
 *   1. `SOURCE_DATE_EPOCH`, the reproducible-builds standard.
 *   2. The value already recorded in the manifest, when the content version is unchanged. Nothing
 *      about the sources moved, so the timestamp must not move either — and this makes `--check`
 *      work on the shallow clone CI uses, where `git log` cannot see the source history at all.
 *   3. The committer time of the newest commit touching the authoritative source set.
 *
 * There is deliberately no fallback to `Date.now()`: a wall clock would make the generated bytes
 * irreproducible, which is the property the whole pipeline exists to guarantee.
 */
function resolveSourceTimestamp(sourcePaths, contentVersion, previousManifest) {
  const epoch = process.env.SOURCE_DATE_EPOCH;
  if (epoch !== undefined && epoch !== '' && Number.isFinite(Number(epoch))) {
    return { value: new Date(Number(epoch) * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z'), method: 'SOURCE_DATE_EPOCH' };
  }
  if (previousManifest !== null
    && previousManifest.content_version === contentVersion
    && typeof previousManifest.source_timestamp === 'string') {
    return { value: previousManifest.source_timestamp, method: 'unchanged_content_version_carried_forward' };
  }
  try {
    const out = execFileSync('git', ['log', '-1', '--format=%cI', '--', ...sourcePaths], { cwd: ROOT, encoding: 'utf8' }).trim();
    if (out !== '') {
      return { value: new Date(out).toISOString().replace(/\.\d{3}Z$/, 'Z'), method: 'git_newest_commit_touching_sources' };
    }
  } catch {
    // Falls through to the hard error below.
  }
  throw new Error('Cannot resolve a deterministic content timestamp: SOURCE_DATE_EPOCH is unset, the manifest has no reusable value, and git history is unavailable. Refusing to embed a wall clock.');
}

// ---------------------------------------------------------------------------------------------
// Emitters
// ---------------------------------------------------------------------------------------------

const BANNER = (source, extra = []) => [
  '// GENERATED FILE — do not edit by hand.',
  '//',
  `// Generated by: node scripts/generate-role-content.mjs (parser ${PARSER_VERSION})`,
  ...(source === null ? [] : [`// Source:       ${source}`]),
  ...extra.map((line) => `// ${line}`),
  '//',
  '// Regenerate with `npm run content:generate`; `npm run content:check` fails when this file is',
  '// stale. Editing it by hand is a defect: the next check restores the generated bytes.',
  '',
].join('\n');

function emitTypes() {
  return `${BANNER(null)}
/** The eight canonical account keys, derived from ${HOST_REGISTRY}. */
export type ContentAccountKey =
${'  | ' + accounts.map((a) => literal(a.key)).join('\n  | ')};

/** The five role-specific content categories every account supplies. */
export type ContentCategory =
${'  | ' + CATEGORIES.map((c) => literal(c.key)).join('\n  | ')};

/** The sixteen semantic landing regions bound by UI/UX plan §8.3, in plan order. */
export type LandingRegion =
${'  | ' + LANDING_REGIONS.map((r) => literal(r)).join('\n  | ')};

/** Provenance carried by every generated content module. */
export interface ContentSourceMeta {
  readonly accountKey: ContentAccountKey;
  readonly category: ContentCategory;
  /** Repository-relative path. Never an absolute workstation path. */
  readonly sourcePath: string;
  readonly sourceSha256: string;
  readonly sourceBytes: number;
  readonly parserVersion: string;
}

/** A verbatim legal document. \`markdown\` reproduces the source file's exact bytes. */
export interface GeneratedLegalDocument {
  readonly meta: ContentSourceMeta;
  readonly markdown: string;
}

/** One compiled FAQ entry. Wording is never rewritten. */
export interface GeneratedFaqItem {
  /** Stable id built from the document's own numbering plus the question slug. */
  readonly id: string;
  /** The source's dotted number, e.g. "1.2". */
  readonly number: string;
  readonly question: string;
  /** Answer as source Markdown; render through the audited \`renderMarkdown\`. */
  readonly answer: string;
  /** The category divider the question sits under, when the source declares one. */
  readonly category: string | null;
  readonly sourceLines: readonly [number, number];
}

export interface GeneratedFaqDocument {
  readonly meta: ContentSourceMeta;
  readonly items: readonly GeneratedFaqItem[];
}

/**
 * One compiled landing region.
 *
 * \`presence\` is \`missing_from_source\` when the role's document supplies no such section — the
 * gap is recorded, never filled. \`renderPermitted\` is false when the section may not be published
 * as-is (UI/UX plan §8.4 unverified customer evidence, or a missing section).
 */
export interface GeneratedLandingSection {
  readonly region: LandingRegion;
  readonly sourceNumber: number | null;
  /** The heading exactly as written in the source. Never paraphrased. */
  readonly sourceHeading: string | null;
  readonly sourceLines: readonly [number, number] | null;
  readonly markdown: string;
  readonly presence: 'present_in_source' | 'missing_from_source';
  readonly contentRestriction: 'none' | 'unverified_customer_evidence' | 'placeholder_testimonial_marked_in_source' | 'product_owner_content_decision_required';
  readonly renderPermitted: boolean;
  readonly restrictionReason: string | null;
  readonly imageCapable: boolean;
}

export interface GeneratedLandingDocument {
  readonly meta: ContentSourceMeta;
  readonly sections: readonly GeneratedLandingSection[];
}

/** One row of the content manifest. */
export interface ContentManifestEntry {
  readonly accountKey: ContentAccountKey;
  readonly category: ContentCategory;
  readonly sourcePath: string;
  readonly sourceSha256: string;
  readonly sourceBytes: number;
  readonly generatedModule: string;
  readonly generatedSha256: string;
  readonly parserVersion: string;
}

export interface ContentManifest {
  /** Digest over every (account, category, path, source hash) tuple. Not a secret. */
  readonly contentVersion: string;
  /** Reproducible source timestamp. Never the generation wall clock. */
  readonly sourceTimestamp: string;
  readonly parserVersion: string;
  readonly entries: readonly ContentManifestEntry[];
}
`;
}

function emitManifestModule(manifest) {
  const rows = manifest.entries.map((entry) => `  {
    accountKey: ${literal(entry.account_key)},
    category: ${literal(entry.category)},
    sourcePath: ${literal(entry.source_path)},
    sourceSha256: ${literal(entry.source_sha256)},
    sourceBytes: ${entry.source_bytes},
    generatedModule: ${literal(entry.generated_module)},
    generatedSha256: ${literal(entry.generated_sha256)},
    parserVersion: ${literal(PARSER_VERSION)},
  },`).join('\n');

  return `${BANNER(`${CATEGORIES.length} categories × ${accounts.length} accounts = ${manifest.entries.length} documents`)}
import type { ContentManifest } from './contentTypes.generated';

export const CONTENT_MANIFEST: ContentManifest = {
  contentVersion: ${literal(manifest.content_version)},
  sourceTimestamp: ${literal(manifest.source_timestamp)},
  parserVersion: ${literal(PARSER_VERSION)},
  entries: [
${rows}
  ],
};

/** The combined content version. Reproducible from the sources; authenticates nothing. */
export const CONTENT_VERSION = ${literal(manifest.content_version)};
`;
}

function emitLegalModule(entry, text) {
  return `${BANNER(entry.source_path, [`sha256:${entry.source_sha256}`, `${entry.source_bytes} bytes, reproduced verbatim`])}
import type { ContentSourceMeta, GeneratedLegalDocument } from '../contentTypes.generated';

const meta: ContentSourceMeta = {
  accountKey: ${literal(entry.account_key)},
  category: ${literal(entry.category)},
  sourcePath: ${literal(entry.source_path)},
  sourceSha256: ${literal(entry.source_sha256)},
  sourceBytes: ${entry.source_bytes},
  parserVersion: ${literal(PARSER_VERSION)},
};

/** Verbatim. Byte-for-byte identical to the approved source document. */
const markdown = ${literal(text)};

const document: GeneratedLegalDocument = { meta, markdown };

export default document;
`;
}

function emitFaqModule(entry, items) {
  const rows = items.map((item) => `  {
    id: ${literal(item.id)},
    number: ${literal(item.number)},
    question: ${literal(item.question)},
    answer: ${literal(item.answer)},
    category: ${item.category === null ? 'null' : literal(item.category)},
    sourceLines: [${item.source_lines[0]}, ${item.source_lines[1]}],
  },`).join('\n');

  return `${BANNER(entry.source_path, [`sha256:${entry.source_sha256}`, `${items.length} questions compiled verbatim`])}
import type { ContentSourceMeta, GeneratedFaqDocument, GeneratedFaqItem } from '../contentTypes.generated';

const meta: ContentSourceMeta = {
  accountKey: ${literal(entry.account_key)},
  category: ${literal(entry.category)},
  sourcePath: ${literal(entry.source_path)},
  sourceSha256: ${literal(entry.source_sha256)},
  sourceBytes: ${entry.source_bytes},
  parserVersion: ${literal(PARSER_VERSION)},
};

/**
 * Every question and answer is copied verbatim from the source document — no rewording, no
 * reordering, no summarising. The full document text is deliberately NOT duplicated here as well:
 * the source file is the authority, its hash is pinned in \`meta\`, and shipping a second copy of
 * the same bytes would double this chunk for no consumer.
 */
const items: readonly GeneratedFaqItem[] = [
${rows}
];

const document: GeneratedFaqDocument = { meta, items };

export default document;
`;
}

function emitLandingModule(entry, sections) {
  const rows = sections.map((section) => `  {
    region: ${literal(section.region)},
    sourceNumber: ${section.source_number === null ? 'null' : section.source_number},
    sourceHeading: ${section.source_heading === null ? 'null' : literal(section.source_heading)},
    sourceLines: ${section.source_body_lines === null ? 'null' : `[${section.source_body_lines[0]}, ${section.source_body_lines[1]}]`},
    markdown: ${literal(section.markdown)},
    presence: ${literal(section.presence)},
    contentRestriction: ${literal(section.content_restriction)},
    renderPermitted: ${section.render_permitted},
    restrictionReason: ${section.restriction_reason === null ? 'null' : literal(section.restriction_reason)},
    imageCapable: ${section.image_capable},
  },`).join('\n');

  return `${BANNER(entry.source_path, [`sha256:${entry.source_sha256}`, `${sections.filter((s) => s.presence === 'present_in_source').length}/${LANDING_REGIONS.length} plan regions present in source`])}
import type { ContentSourceMeta, GeneratedLandingDocument, GeneratedLandingSection } from '../contentTypes.generated';

const meta: ContentSourceMeta = {
  accountKey: ${literal(entry.account_key)},
  category: ${literal(entry.category)},
  sourcePath: ${literal(entry.source_path)},
  sourceSha256: ${literal(entry.source_sha256)},
  sourceBytes: ${entry.source_bytes},
  parserVersion: ${literal(PARSER_VERSION)},
};

/**
 * Every present section carries its source body verbatim. The full document text is deliberately
 * NOT duplicated alongside them: concatenating the section bodies reproduces the document's content
 * and the source hash in \`meta\` pins the file itself.
 */
const sections: readonly GeneratedLandingSection[] = [
${rows}
];

const document: GeneratedLandingDocument = { meta, sections };

export default document;
`;
}

function emitIndex() {
  const loaderRows = accounts.map((account) => {
    const inner = CATEGORIES.map((category) => {
      const module = `./${account.key}/${category.module}.generated`;
      const type = category.kind === 'legal' ? 'GeneratedLegalDocument'
        : category.kind === 'faq' ? 'GeneratedFaqDocument' : 'GeneratedLandingDocument';
      return `    ${category.key}: (): Promise<{ default: ${type} }> => import(${literal(module)}),`;
    }).join('\n');
    return `  ${account.key}: {\n${inner}\n  },`;
  }).join('\n');

  return `${BANNER(`${accounts.length} accounts × ${CATEGORIES.length} categories`)}
import type {
  ContentAccountKey,
  ContentCategory,
  GeneratedFaqDocument,
  GeneratedLandingDocument,
  GeneratedLegalDocument,
} from './contentTypes.generated';

/**
 * One STATIC dynamic import per (account, category).
 *
 * Static specifiers are what let Vite emit forty separate chunks and let vue-tsc type them. A
 * template-built specifier would defeat both, and would also mean a browser-supplied value could
 * influence which file is loaded — the opposite of the fail-closed contract below.
 */
const LOADERS = {
${loaderRows}
} as const;

export type ContentLoaderTable = typeof LOADERS;

/** The eight account keys, in registry order. */
export const CONTENT_ACCOUNT_KEYS = Object.keys(LOADERS) as ContentAccountKey[];

/** The five content categories, in canonical order. */
export const CONTENT_CATEGORIES: readonly ContentCategory[] = [
${CATEGORIES.map((c) => `  ${literal(c.key)},`).join('\n')}
];

function loaderFor(accountKey: ContentAccountKey, category: ContentCategory): () => Promise<unknown> {
  const account = (LOADERS as Record<string, Record<string, () => Promise<unknown>> | undefined>)[accountKey];
  if (account === undefined) {
    throw new Error(\`Content not found — unknown account key: \${String(accountKey)}\`);
  }
  const loader = account[category];
  if (loader === undefined) {
    throw new Error(\`Content not found — unknown category for \${String(accountKey)}: \${String(category)}\`);
  }
  return loader;
}

/**
 * Load one account's legal document. Fails closed on an unknown key or category — it NEVER falls
 * back to another account's content, which is the cross-role leak §17.1 forbids.
 */
export async function loadGeneratedLegal(
  accountKey: ContentAccountKey,
  category: 'data_policy' | 'privacy_policy' | 'terms_of_service',
): Promise<GeneratedLegalDocument> {
  const module = await loaderFor(accountKey, category)();
  return (module as { default: GeneratedLegalDocument }).default;
}

/** Load one account's compiled FAQ. Fails closed on an unknown key. */
export async function loadGeneratedFaq(accountKey: ContentAccountKey): Promise<GeneratedFaqDocument> {
  const module = await loaderFor(accountKey, 'faq')();
  return (module as { default: GeneratedFaqDocument }).default;
}

/** Load one account's compiled landing content. Fails closed on an unknown key. */
export async function loadGeneratedLanding(accountKey: ContentAccountKey): Promise<GeneratedLandingDocument> {
  const module = await loaderFor(accountKey, 'landing')();
  return (module as { default: GeneratedLandingDocument }).default;
}
`;
}

// ---------------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------------

/** The canonical role-key authority. There is no second role list in this pipeline. */
const registry = JSON.parse(readFileSync(join(ROOT, HOST_REGISTRY), 'utf8'));
const accounts = registry.accounts
  .map((account) => ({
    key: account.account_key,
    displayName: account.display_name,
    publicContentKey: account.public_content_key,
    legalContentKey: account.legal_content_key,
  }))
  .sort((a, b) => a.key.localeCompare(b.key));

function main() {
  for (const account of accounts) {
    if (account.publicContentKey !== account.key || account.legalContentKey !== account.key) {
      fail(`${HOST_REGISTRY}: ${account.key} maps to public/legal content keys ${account.publicContentKey}/${account.legalContentKey}. UI-05 compiles one content set per account and will not cross-map.`);
    }
  }

  const entries = [];
  const sourceTexts = new Map();
  const compiled = new Map();
  const safetyFindings = [];
  const seenSourcePaths = new Set();

  for (const account of accounts) {
    for (const category of CATEGORIES) {
      const relPath = `${category.directory}/${account.key}${category.suffix}`;
      const absolute = join(ROOT, relPath);

      if (!existsSync(absolute)) {
        fail(`Missing ${category.key} source for ${account.key}: ${relPath}`);
        continue;
      }
      if (seenSourcePaths.has(relPath)) {
        fail(`Duplicate source mapping: ${relPath} is claimed by more than one account/category pair.`);
        continue;
      }
      seenSourcePaths.add(relPath);

      const bytes = readFileSync(absolute);
      const text = bytes.toString('utf8');

      if (Buffer.from(text, 'utf8').compare(bytes) !== 0) {
        fail(`${relPath} is not valid UTF-8; the generated string could not reproduce its bytes.`);
        continue;
      }
      if (!relPath.startsWith(`${category.directory}/`)) {
        fail(`${relPath} resolves outside the canonical directory ${category.directory}.`);
        continue;
      }

      safetyFindings.push(...scanSafety(relPath, text));

      const entry = {
        account_key: account.key,
        account: account.displayName,
        category: category.key,
        kind: category.kind,
        source_path: relPath,
        source_sha256: sha256(bytes),
        source_bytes: bytes.length,
        generated_module: `${GENERATED_DIR}/${account.key}/${category.module}.generated.ts`,
        generated_sha256: null,
        parser_version: PARSER_VERSION,
      };
      entries.push(entry);
      sourceTexts.set(`${account.key}:${category.key}`, text);

      if (category.kind === 'faq') {
        compiled.set(`${account.key}:faq`, compileFaq(account.key, relPath, text));
      } else if (category.kind === 'landing') {
        compiled.set(`${account.key}:landing`, compileLanding(account.key, relPath, text));
      }
    }
  }

  for (const finding of safetyFindings) {
    fail(`${finding.path}:${finding.line}: ${finding.kind === 'raw_html' ? `raw HTML <${finding.element}>` : `unsafe link target`} — ${finding.detail}. UI-05 refuses to strip or rewrite approved content; this needs a recorded product-owner decision.`);
  }

  if (entries.length !== accounts.length * CATEGORIES.length) {
    fail(`Compiled ${entries.length} documents; the contract binds ${accounts.length * CATEGORIES.length}.`);
  }

  if (problems.length > 0) {
    report();
    process.exit(1);
  }

  entries.sort((a, b) => a.account_key.localeCompare(b.account_key)
    || CATEGORIES.findIndex((c) => c.key === a.category) - CATEGORIES.findIndex((c) => c.key === b.category)
    || a.source_path.localeCompare(b.source_path));

  const contentVersion = sha256(Buffer.from(
    entries.map((e) => `${e.account_key}\t${e.category}\t${e.source_path}\t${e.source_sha256}`).join('\n'),
    'utf8',
  ));

  const manifestPath = join(ROOT, AUDIT_DIR, 'content-source-manifest.json');
  const previous = existsSync(manifestPath) ? JSON.parse(readFileSync(manifestPath, 'utf8')) : null;
  const timestamp = resolveSourceTimestamp(entries.map((e) => e.source_path), contentVersion, previous);

  // ---- generated modules --------------------------------------------------
  writeArtifact(`${GENERATED_DIR}/contentTypes.generated.ts`, emitTypes());

  for (const entry of entries) {
    const text = sourceTexts.get(`${entry.account_key}:${entry.category}`);
    const body = entry.kind === 'legal' ? emitLegalModule(entry, text)
      : entry.kind === 'faq' ? emitFaqModule(entry, compiled.get(`${entry.account_key}:faq`))
        : emitLandingModule(entry, compiled.get(`${entry.account_key}:landing`));

    entry.generated_sha256 = sha256(Buffer.from(normaliseOutput(body), 'utf8'));
    writeArtifact(entry.generated_module, body);
  }

  const manifest = {
    content_version: contentVersion,
    source_timestamp: timestamp.value,
    entries,
  };

  writeArtifact(`${GENERATED_DIR}/contentManifest.generated.ts`, emitManifestModule(manifest));
  writeArtifact(`${GENERATED_DIR}/index.generated.ts`, emitIndex());

  // Any generated module not in this run's expected set is stale output from an earlier shape and
  // is removed, so the directory can never accumulate an orphan a test would still import. The
  // landing-image module shares this directory but belongs to `generate-landing-images.mjs`; each
  // generator sweeps only what it owns, or the two would delete each other's output.
  const expectedModules = new Set([
    `${GENERATED_DIR}/contentTypes.generated.ts`,
    `${GENERATED_DIR}/contentManifest.generated.ts`,
    `${GENERATED_DIR}/index.generated.ts`,
    `${GENERATED_DIR}/landingImages.generated.ts`,
    ...entries.map((e) => e.generated_module),
  ]);
  const orphans = [];
  const generatedRoot = join(ROOT, GENERATED_DIR);
  if (existsSync(generatedRoot)) {
    const walk = (dir, prefix) => {
      for (const item of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
        const rel = `${prefix}/${item.name}`;
        if (item.isDirectory()) {
          walk(join(dir, item.name), rel);
        } else if (!expectedModules.has(rel)) {
          orphans.push(rel);
        }
      }
    };
    walk(generatedRoot, GENERATED_DIR);
  }
  for (const orphan of orphans) {
    if (!CHECK_ONLY) {
      rmSync(join(ROOT, orphan));
    }
    written.push({ path: orphan, changed: true, sha256: null, removed: true });
  }

  // ---- audit artifacts ----------------------------------------------------
  const legalEntries = entries.filter((e) => e.kind === 'legal');
  const faqItems = accounts.flatMap((a) => compiled.get(`${a.key}:faq`));
  const landingSections = accounts.flatMap((a) => compiled.get(`${a.key}:landing`)
    .map((s) => ({ account_key: a.key, ...s })));

  writeArtifact(`${AUDIT_DIR}/content-source-manifest.json`, asJson({
    generated_by: 'scripts/generate-role-content.mjs',
    role_key_authority: HOST_REGISTRY,
    canonical_directories: Object.fromEntries(CATEGORIES.map((c) => [c.key, c.directory])),
    parser_version: PARSER_VERSION,
    content_version: contentVersion,
    source_timestamp: timestamp.value,
    // The RESOLUTION METHOD is deliberately not recorded here. It legitimately differs between a
    // first generation (git history) and every later one (carried forward), so writing it would
    // make the artifact's bytes depend on how the working copy was obtained rather than on the
    // sources. The method is reported on stdout and in docs/proof/ui-05.md instead.
    accounts: accounts.length,
    categories: CATEGORIES.length,
    total_documents: entries.length,
    duplicate_source_mappings: 0,
    cross_role_mappings: 0,
    unsafe_raw_html_findings: 0,
    unsafe_link_findings: 0,
    entries,
  }));

  writeArtifact(`${AUDIT_DIR}/legal-hash-manifest.json`, asJson({
    generated_by: 'scripts/generate-role-content.mjs',
    contract: 'Legal source bytes are reproduced verbatim in the generated module. Decoding the generated string yields the exact source bytes; no rewrite, shortening, summary, spelling correction, clause injection or role merge occurs.',
    total_legal_documents: legalEntries.length,
    documents: legalEntries.map((e) => ({
      account_key: e.account_key,
      category: e.category,
      source_path: e.source_path,
      source_sha256: e.source_sha256,
      source_bytes: e.source_bytes,
      generated_module: e.generated_module,
      generated_sha256: e.generated_sha256,
      verbatim: true,
    })),
  }));

  writeArtifact(`${AUDIT_DIR}/faq-manifest.json`, asJson({
    generated_by: 'scripts/generate-role-content.mjs',
    parser_version: PARSER_VERSION,
    parser_rule: 'A question is any heading whose text begins with dotted numbering (N.M). Heading LEVEL is not part of the rule: Merchant Administrator writes sixty of its questions at ### and the previous ##-only runtime parser dropped them (UI05-FAQ-001).',
    total_items: faqItems.length,
    counts_by_account: Object.fromEntries(accounts.map((a) => [a.key, compiled.get(`${a.key}:faq`).length])),
    items: faqItems.map((item) => ({
      account_key: item.account_key,
      id: item.id,
      number: item.number,
      question: item.question,
      category: item.category,
      source_path: item.source_path,
      source_lines: item.source_lines,
      answer_bytes: Buffer.byteLength(item.answer, 'utf8'),
      order: item.order,
    })),
  }));

  writeArtifact(`${AUDIT_DIR}/content-parity.json`, asJson({
    generated_by: 'scripts/generate-role-content.mjs',
    content_version: contentVersion,
    source_timestamp: timestamp.value,
    landing_regions: LANDING_REGIONS,
    landing_region_presence: Object.fromEntries(accounts.map((a) => [
      a.key,
      Object.fromEntries(compiled.get(`${a.key}:landing`).map((s) => [s.region, s.presence])),
    ])),
    sections_missing_from_source: landingSections
      .filter((s) => s.presence === 'missing_from_source')
      .map((s) => ({ account_key: s.account_key, region: s.region, decision: 'product_owner_content_decision_required', owner_phase: 'UI-06' })),
    sections_not_renderable: landingSections
      .filter((s) => s.presence === 'present_in_source' && !s.render_permitted)
      .map((s) => ({
        account_key: s.account_key,
        region: s.region,
        source_heading: s.source_heading,
        content_restriction: s.content_restriction,
        reason: s.restriction_reason,
        owner_phase: 'UI-06 / product owner',
      })),
    generated_artifacts: written
      .filter((a) => a.sha256 !== null)
      .map((a) => ({ path: a.path, sha256: a.sha256 }))
      .sort((a, b) => a.path.localeCompare(b.path)),
  }));

  const changed = written.filter((a) => a.changed);

  if (CHECK_ONLY && changed.length > 0) {
    console.error('Generated role content is STALE. Re-run: npm run content:generate\n');
    for (const artifact of changed) {
      console.error(`  - ${artifact.path}${artifact.removed === true ? ' (orphaned; would be removed)' : ''}`);
    }
    process.exit(1);
  }

  console.log(`Role content OK — ${entries.length} documents across ${accounts.length} accounts;`
    + ` ${legalEntries.length} legal, ${faqItems.length} FAQ items,`
    + ` ${landingSections.filter((s) => s.presence === 'present_in_source').length} landing sections.`);
  console.log(`  content version   sha256:${contentVersion}`);
  console.log(`  source timestamp  ${timestamp.value} (${timestamp.method})`);
  console.log(`  raw HTML findings 0; unsafe link findings 0`);
  console.log(CHECK_ONLY
    ? 'All artifacts up to date.'
    : `${changed.length} artifact(s) written, ${written.length - changed.length} unchanged.`);
}

function report() {
  console.error('Role-content compilation FAILED:\n');
  for (const problem of problems) {
    console.error(`  - ${problem}`);
  }
}

try {
  main();
} catch (error) {
  console.error(`Role-content compilation FAILED: ${error.message}`);
  process.exit(1);
}
