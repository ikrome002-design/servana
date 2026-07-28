#!/usr/bin/env node
// Phase UI-00 — deterministic UI source inventory generator.
//
// Materialises, from sources that already exist in the repository, the four artifacts the
// corrective UI programme needs before any frontend phase may implement a page:
//
//   docs/frontend/navigation/servana-user-account-navigation-maps.md  (the binding spec)
//   docs/frontend/source-inventory/navigation-map.json                (parsed 160-page contract)
//   docs/frontend/source-inventory/role-content.json                  (40 role source documents)
//   docs/frontend/source-inventory/brand-assets.json                  (approved logo + favicons)
//   docs/frontend/source-inventory/landing-images.json                (61 supplied landing images)
//
// It reads only the filesystem, never the network, and every output is sorted so a second run
// produces no diff. It records what the sources SAY; it never marks a page implemented — the
// machine-readable runtime navigation contract and its router parity belong to Phase UI-07.
//
// Usage: node scripts/generate-ui-source-inventory.mjs [--check]

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');

const PLAN = 'Servana_Role_Specific_UI_UX_Subdomain_Software_Development_Plan.md';
const NAV_MAP = 'docs/frontend/navigation/servana-user-account-navigation-maps.md';
const INVENTORY_DIR = 'docs/frontend/source-inventory';

const BEGIN_MARKER = '<!-- BEGIN VERBATIM NAVIGATION MAP -->';
const END_MARKER = '<!-- END VERBATIM NAVIGATION MAP -->';

/**
 * The eight canonical role keys. This ordering is the canonical ordering used by every
 * generated artifact, so the account sequence never depends on directory-listing order.
 */
const ACCOUNTS = [
  { key: 'super_administrator', account: 'Super Administrator', section: '5', host: 'citrus.servana.ke', pages: 22 },
  { key: 'merchant_administrator', account: 'Merchant Administrator', section: '6', host: 'servana.ke', pages: 23 },
  { key: 'merchant_branch', account: 'Branch', section: '7', host: 'branch.servana.ke', pages: 18 },
  { key: 'merchant_human_resource', account: 'Human Resource', section: '8', host: 'hr.servana.ke', pages: 19 },
  { key: 'merchant_finance', account: 'Finance', section: '9', host: 'finance.servana.ke', pages: 24 },
  { key: 'merchant_front_office', account: 'Front Office', section: '10', host: 'office.servana.ke', pages: 19 },
  { key: 'merchant_personnel', account: 'Personnel', section: '11', host: 'staff.servana.ke', pages: 20 },
  { key: 'merchant_audit', account: 'Audit', section: '12', host: 'audit.servana.ke', pages: 15 },
];

/** The five role-specific content categories every account must supply. */
const CONTENT_CATEGORIES = [
  { category: 'landing_page', directory: 'docs/landing_page', suffix: '_landing_page_content.md' },
  { category: 'data_policy', directory: 'docs/legal/data_policy', suffix: '_data_policy.md' },
  { category: 'privacy_policy', directory: 'docs/legal/privacy_policy', suffix: '_privacy_policy.md' },
  { category: 'terms_of_service', directory: 'docs/legal/terms_of_service', suffix: '_terms_of_service.md' },
  { category: 'faq', directory: 'docs/support/faq', suffix: '_faq.md' },
];

/**
 * Brand assets this programme depends on. `approval` records product-owner standing:
 * `approved` is supplied and in use; `present_unreferenced` exists but nothing requires it;
 * `deleted_by_authority` must stay absent — UI plan §1.3 forbids restoring a deliberately deleted
 * asset without product-owner authorization.
 */
const BRAND_ASSETS = [
  { purpose: 'primary_logo', path: 'public/assets/brand/Logo.png', approval: 'approved', required: true },
  { purpose: 'favicon_ico', path: 'public/assets/brand/favicon.ico', approval: 'approved', required: true },
  { purpose: 'favicon_16', path: 'public/assets/brand/favicon-16x16.png', approval: 'approved', required: true },
  { purpose: 'favicon_32', path: 'public/assets/brand/favicon-32x32.png', approval: 'approved', required: true },
  { purpose: 'apple_touch_icon', path: 'public/assets/brand/apple-touch-icon.png', approval: 'approved', required: true },
  { purpose: 'android_chrome_192', path: 'public/assets/brand/android-chrome-192x192.png', approval: 'approved', required: true },
  { purpose: 'android_chrome_512', path: 'public/assets/brand/android-chrome-512x512.png', approval: 'approved', required: true },
  { purpose: 'legacy_logo_vector', path: 'public/assets/brand/Logo.svg', approval: 'deleted_by_authority', required: false },
];

const read = (relPath) => readFileSync(join(ROOT, relPath), 'utf8');
const sha256 = (buffer) => createHash('sha256').update(buffer).digest('hex');
const posix = (p) => p.split('\\').join('/');

function writeArtifact(relPath, contents) {
  const absolute = join(ROOT, relPath);
  const existing = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;

  if (existing === contents) {
    return { path: relPath, changed: false };
  }
  if (CHECK_ONLY) {
    return { path: relPath, changed: true };
  }
  mkdirSync(dirname(absolute), { recursive: true });
  writeFileSync(absolute, contents, 'utf8');

  return { path: relPath, changed: true };
}

/** Stable JSON: two-space indent, trailing newline, keys written in insertion order. */
const asJson = (value) => `${JSON.stringify(value, null, 2)}\n`;

// ---------------------------------------------------------------------------------------------
// 1. Appendix A — the binding navigation map
// ---------------------------------------------------------------------------------------------

/** Extract Appendix A verbatim. Semantic alteration is prohibited; only the markers are dropped. */
function extractNavigationMap(planText) {
  const begin = planText.indexOf(BEGIN_MARKER);
  const end = planText.indexOf(END_MARKER);

  if (begin === -1 || end === -1 || end <= begin) {
    throw new Error(`Appendix A markers not found in ${PLAN}; refusing to guess the boundaries.`);
  }

  return `${planText.slice(begin + BEGIN_MARKER.length, end).trim()}\n`;
}

/**
 * Parse the detailed page specifications. Every page is an `### {section} — {title}` heading
 * followed by the three binding bullets. A page missing any of them is a hard error: a silently
 * dropped row would understate the contract, which is exactly the drift UI-00 exists to prevent.
 */
function parsePageSpecifications(navigationMapText) {
  const sectionToAccount = new Map(ACCOUNTS.map((a) => [a.section, a]));
  const lines = navigationMapText.split(/\r?\n/);
  const pages = [];

  // `### 5.4.1 — Dashboard` (em dash, as written in the source).
  const headingPattern = /^###\s+(\d+)\.4\.(\d+)\s+—\s+(.+?)\s*$/;
  const routePattern = /^-\s+\*\*Required frontend route:\*\*\s+`([^`]+)`\s*$/;
  const placementPattern = /^-\s+\*\*Navigation placement:\*\*\s+(.+?)\s*$/;
  const purposePattern = /^-\s+\*\*Purpose:\*\*\s+(.+?)\s*$/;

  for (let i = 0; i < lines.length; i += 1) {
    const heading = headingPattern.exec(lines[i]);
    if (heading === null) {
      continue;
    }

    const [, sectionNumber, pageNumber, title] = heading;
    const account = sectionToAccount.get(sectionNumber);
    if (account === undefined) {
      throw new Error(`Page heading "${lines[i]}" names section ${sectionNumber}, which is not an account section.`);
    }

    // The three bullets sit in the block before the next `###` heading.
    let route = null;
    let placement = null;
    let purpose = null;
    for (let j = i + 1; j < lines.length && !lines[j].startsWith('### '); j += 1) {
      route ??= routePattern.exec(lines[j])?.[1] ?? null;
      placement ??= placementPattern.exec(lines[j])?.[1] ?? null;
      purpose ??= purposePattern.exec(lines[j])?.[1] ?? null;
    }

    const section = `${sectionNumber}.4.${pageNumber}`;
    for (const [field, value] of [['route', route], ['placement', placement], ['purpose', purpose]]) {
      if (value === null) {
        throw new Error(`Page ${section} (${title}) has no "${field}" bullet; the specification is incomplete.`);
      }
    }

    pages.push({
      section,
      account_key: account.key,
      account: account.account,
      host: account.host,
      page: title,
      route,
      navigation_placement: placement,
      purpose,
      // UI-00 registers the requirement only. UI-07 owns the runtime contract that decides
      // whether a page is implemented, planned, gate-disabled, or removed by authority.
      owner_phase: 'UI-07',
      implementation_status: 'planned',
    });
  }

  return pages;
}

/** Parse the §30 register table, which the plan states is generated from the same page specs. */
function parseSection30Register(planText) {
  const heading = '# 30. Complete Authenticated Route Implementation Register';
  const start = planText.indexOf(heading);
  if (start === -1) {
    throw new Error('Section 30 register not found in the UI/UX plan.');
  }
  const block = planText.slice(start, planText.indexOf('# Appendix A', start));
  const rows = [];

  for (const line of block.split(/\r?\n/)) {
    // A data row starts with `| 5.4.1 |`; the header and separator rows never do.
    if (!/^\|\s*\d+\.4\.\d+\s*\|/.test(line)) {
      continue;
    }
    const cells = line.split('|').slice(1, -1).map((cell) => cell.trim());
    if (cells.length !== 7) {
      throw new Error(`Section 30 row has ${cells.length} cells, expected 7: ${line}`);
    }
    const [section, account, host, page, route, placement, purpose] = cells;
    rows.push({
      section,
      account,
      host: host.replace(/`/g, ''),
      page,
      route: route.replace(/`/g, ''),
      navigation_placement: placement,
      purpose,
    });
  }

  return rows;
}

/**
 * Prove Appendix A and the §30 register describe the same 160 pages. The plan declares §30 is
 * generated from the page specifications, so any disagreement means one of them drifted and the
 * later UI phases would implement the wrong information architecture.
 */
function reconcileRegisters(pages, registerRows) {
  const problems = [];

  if (pages.length !== registerRows.length) {
    problems.push(`Appendix A parses ${pages.length} pages; §30 lists ${registerRows.length}.`);
  }

  const registerBySection = new Map(registerRows.map((row) => [row.section, row]));
  for (const page of pages) {
    const row = registerBySection.get(page.section);
    if (row === undefined) {
      problems.push(`${page.section} (${page.page}) is in Appendix A but not in the §30 register.`);
      continue;
    }
    for (const field of ['account', 'host', 'page', 'route', 'navigation_placement', 'purpose']) {
      if (page[field] !== row[field]) {
        problems.push(`${page.section} ${field}: Appendix A "${page[field]}" vs §30 "${row[field]}".`);
      }
    }
    registerBySection.delete(page.section);
  }
  for (const section of registerBySection.keys()) {
    problems.push(`${section} is in the §30 register but has no Appendix A page specification.`);
  }

  return problems;
}

/** Per-account counts, duplicate section ids, and duplicate account/route pairs. */
function validatePages(pages) {
  const problems = [];
  const seenSections = new Set();
  const seenAccountRoutes = new Set();

  for (const page of pages) {
    if (seenSections.has(page.section)) {
      problems.push(`Duplicate section identifier: ${page.section}.`);
    }
    seenSections.add(page.section);

    const pair = `${page.account_key} ${page.route}`;
    if (seenAccountRoutes.has(pair)) {
      problems.push(`Duplicate account/route pair: ${page.account} ${page.route}.`);
    }
    seenAccountRoutes.add(pair);
  }

  for (const account of ACCOUNTS) {
    const actual = pages.filter((page) => page.account_key === account.key).length;
    if (actual !== account.pages) {
      problems.push(`${account.account}: parsed ${actual} pages, the plan binds ${account.pages}.`);
    }
  }

  const total = ACCOUNTS.reduce((sum, account) => sum + account.pages, 0);
  if (pages.length !== total) {
    problems.push(`Parsed ${pages.length} pages in total; the plan binds ${total}.`);
  }

  return problems;
}

// ---------------------------------------------------------------------------------------------
// 2. Content, brand, and landing-image inventories
// ---------------------------------------------------------------------------------------------

function fileRecord(relPath) {
  const absolute = join(ROOT, relPath);
  if (!existsSync(absolute)) {
    return null;
  }
  const bytes = readFileSync(absolute);

  return { bytes: statSync(absolute).size, sha256: sha256(bytes), buffer: bytes };
}

function buildRoleContentInventory() {
  const documents = [];
  const problems = [];

  for (const account of ACCOUNTS) {
    for (const category of CONTENT_CATEGORIES) {
      const relPath = `${category.directory}/${account.key}${category.suffix}`;
      const record = fileRecord(relPath);
      if (record === null) {
        problems.push(`Missing ${category.category} source for ${account.key}: ${relPath}`);
        continue;
      }
      documents.push({
        role_key: account.key,
        account: account.account,
        category: category.category,
        path: relPath,
        bytes: record.bytes,
        sha256: record.sha256,
      });
    }
  }

  documents.sort((a, b) => a.path.localeCompare(b.path));

  return { documents, problems };
}

/** Minimal PNG/ICO header reader — enough to prove the file really is the image it claims to be. */
function imageDimensions(buffer) {
  const PNG_SIGNATURE = '89504e470d0a1a0a';
  if (buffer.subarray(0, 8).toString('hex') === PNG_SIGNATURE && buffer.subarray(12, 16).toString('ascii') === 'IHDR') {
    return { type: 'image/png', width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20) };
  }
  // ICO: reserved=0, type=1, then per-image entries whose 0 byte means 256.
  if (buffer.length >= 6 && buffer.readUInt16LE(0) === 0 && buffer.readUInt16LE(2) === 1) {
    return { type: 'image/x-icon', width: buffer[6] === 0 ? 256 : buffer[6], height: buffer[7] === 0 ? 256 : buffer[7] };
  }

  return null;
}

/** Every tracked-or-present file under a directory, relative to the repository root, sorted. */
function filesUnder(relDirectory) {
  const absolute = join(ROOT, relDirectory);
  if (!existsSync(absolute)) {
    return [];
  }
  const found = [];
  for (const entry of readdirSync(absolute, { withFileTypes: true })) {
    const child = join(absolute, entry.name);
    if (entry.isDirectory()) {
      found.push(...filesUnder(posix(relative(ROOT, child))));
    } else {
      found.push(posix(relative(ROOT, child)));
    }
  }

  return found.sort();
}

function buildBrandInventory() {
  const assets = [];
  const problems = [];
  const classified = new Set();

  for (const asset of BRAND_ASSETS) {
    classified.add(asset.path);
    const record = fileRecord(asset.path);

    if (asset.approval === 'deleted_by_authority') {
      // The absence IS the contract. Restoring it needs product-owner authority (UI plan §1.3).
      if (record !== null) {
        problems.push(`${asset.path} was deleted by product-owner authority but is present again.`);
      }
      assets.push({
        purpose: asset.purpose,
        path: asset.path,
        filename: asset.path.split('/').pop(),
        present: record !== null,
        approval: asset.approval,
        notes: 'Deleted under product-owner authority; must not be restored, referenced, or required.',
      });
      continue;
    }

    if (record === null) {
      problems.push(`Missing required brand asset: ${asset.path}`);
      continue;
    }

    const dimensions = imageDimensions(record.buffer);
    if (dimensions === null) {
      problems.push(`${asset.path} is not a readable PNG or ICO.`);
      continue;
    }

    assets.push({
      purpose: asset.purpose,
      path: asset.path,
      filename: asset.path.split('/').pop(),
      extension: asset.path.split('.').pop(),
      type: dimensions.type,
      width: dimensions.width,
      height: dimensions.height,
      bytes: record.bytes,
      sha256: record.sha256,
      present: true,
      approval: asset.approval,
    });
  }

  // Everything else that physically sits under public/assets/brand/. Recording these keeps the
  // inventory a true statement about the filesystem, so a later phase can never mistake an
  // unreferenced working file for an approved brand asset.
  for (const relPath of filesUnder('public/assets/brand')) {
    if (classified.has(relPath)) {
      continue;
    }
    const record = fileRecord(relPath);
    const dimensions = record === null ? null : imageDimensions(record.buffer);

    assets.push({
      purpose: 'unreferenced_working_file',
      path: relPath,
      filename: relPath.split('/').pop(),
      extension: relPath.split('.').pop(),
      type: dimensions?.type ?? null,
      width: dimensions?.width ?? null,
      height: dimensions?.height ?? null,
      bytes: record?.bytes ?? null,
      sha256: record?.sha256 ?? null,
      present: true,
      approval: 'present_unreferenced',
      notes: 'Present in the repository but required by nothing. Not an approved brand asset; do not introduce a reference without product-owner approval.',
    });
  }

  return { assets, problems };
}

function buildLandingImageInventory() {
  const images = [];
  const problems = [];
  const byHash = new Map();

  for (const account of ACCOUNTS) {
    const directory = `public/assets/landing_page_images/${account.key}`;
    const absolute = join(ROOT, directory);

    if (!existsSync(absolute)) {
      problems.push(`Missing landing-image directory: ${directory}`);
      continue;
    }

    const files = readdirSync(absolute).filter((name) => statSync(join(absolute, name)).isFile()).sort();
    for (const filename of files) {
      const relPath = `${directory}/${filename}`;
      const record = fileRecord(relPath);
      const dimensions = record === null ? null : imageDimensions(record.buffer);

      if (record === null || dimensions === null) {
        problems.push(`${relPath} is not a readable image.`);
        continue;
      }

      const duplicateOf = byHash.get(record.sha256);
      if (duplicateOf === undefined) {
        byHash.set(record.sha256, relPath);
      }

      images.push({
        role_key: account.key,
        account: account.account,
        path: relPath,
        filename,
        type: dimensions.type,
        width: dimensions.width,
        height: dimensions.height,
        aspect_ratio: Number((dimensions.width / dimensions.height).toFixed(4)),
        bytes: record.bytes,
        sha256: record.sha256,
        duplicate_of: duplicateOf ?? null,
      });
    }
  }

  images.sort((a, b) => a.path.localeCompare(b.path));

  const counts = {};
  for (const account of ACCOUNTS) {
    counts[account.key] = images.filter((image) => image.role_key === account.key).length;
  }

  return { images, counts, problems };
}

// ---------------------------------------------------------------------------------------------
// 3. Compose and emit
// ---------------------------------------------------------------------------------------------

function main() {
  const planText = read(PLAN);
  const planHash = sha256(Buffer.from(planText, 'utf8'));

  const navigationMapBody = extractNavigationMap(planText);
  const navigationMapHash = sha256(Buffer.from(navigationMapBody, 'utf8'));

  const provenance = [
    '<!--',
    '  GENERATED FILE — do not edit by hand.',
    '',
    `  Source plan:      ${PLAN}`,
    '  Source section:   Appendix A — Binding Servana User Account Navigation Maps and Page Functional Scope',
    `  Source plan hash: sha256:${planHash}`,
    `  Extracted hash:   sha256:${navigationMapHash}`,
    '  Generated by:     node scripts/generate-ui-source-inventory.mjs',
    '',
    '  This file is the binding human-readable frontend page and workflow specification named by',
    '  the UI/UX plan. It is reproduced verbatim from Appendix A: no page name, route, purpose,',
    '  sub-feature, workflow, ownership boundary, or account responsibility may be paraphrased.',
    '  Edit Appendix A of the plan, then regenerate.',
    '-->',
    '',
  ].join('\n');

  const pages = parsePageSpecifications(navigationMapBody);
  const registerRows = parseSection30Register(planText);

  const problems = [
    ...validatePages(pages),
    ...reconcileRegisters(pages, registerRows),
  ];

  const content = buildRoleContentInventory();
  const brand = buildBrandInventory();
  const landing = buildLandingImageInventory();
  problems.push(...content.problems, ...brand.problems, ...landing.problems);

  if (problems.length > 0) {
    console.error('UI source inventory FAILED:\n');
    for (const problem of problems) {
      console.error(`  - ${problem}`);
    }
    process.exit(1);
  }

  const accountSummary = ACCOUNTS.map((account) => ({
    role_key: account.key,
    account: account.account,
    navigation_map_section: account.section,
    host: account.host,
    required_pages: account.pages,
    parsed_pages: pages.filter((page) => page.account_key === account.key).length,
  }));

  const written = [
    writeArtifact(NAV_MAP, `${provenance}${navigationMapBody}`),
    writeArtifact(`${INVENTORY_DIR}/navigation-map.json`, asJson({
      generated_by: 'scripts/generate-ui-source-inventory.mjs',
      source_plan: PLAN,
      source_section: 'Appendix A',
      source_plan_sha256: planHash,
      navigation_map: NAV_MAP,
      navigation_map_sha256: navigationMapHash,
      owner_phase_note: 'UI-00 registers the required page contract only. UI-07 owns the machine-readable runtime navigation contract, router parity, and implementation status.',
      total_required_pages: pages.length,
      accounts: accountSummary,
      section_30_register_rows: registerRows.length,
      section_30_parity: 'exact',
      pages,
    })),
    writeArtifact(`${INVENTORY_DIR}/role-content.json`, asJson({
      generated_by: 'scripts/generate-ui-source-inventory.mjs',
      canonical_directories: Object.fromEntries(CONTENT_CATEGORIES.map((c) => [c.category, c.directory])),
      note: 'docs/landing page/ (with a space) does not exist; docs/landing_page/ is the sole canonical landing-content directory.',
      required_per_role: CONTENT_CATEGORIES.length,
      total_documents: content.documents.length,
      documents: content.documents,
    })),
    writeArtifact(`${INVENTORY_DIR}/brand-assets.json`, asJson({
      generated_by: 'scripts/generate-ui-source-inventory.mjs',
      approved_primary_logo: 'public/assets/brand/Logo.png',
      deleted_by_authority: ['public/assets/brand/Logo.svg'],
      assets: brand.assets,
    })),
    writeArtifact(`${INVENTORY_DIR}/landing-images.json`, asJson({
      generated_by: 'scripts/generate-ui-source-inventory.mjs',
      selection_rule: 'Later UI phases (UI-05/UI-06) select approximately two to four primary supplied images per landing page. Never render every image merely because it exists.',
      owner_phase_note: 'UI-00 inventories the supplied source set only. The curated production selection manifest belongs to UI-05/UI-06.',
      total_images: landing.images.length,
      counts_by_role: landing.counts,
      images: landing.images,
    })),
  ];

  const changed = written.filter((artifact) => artifact.changed);

  if (CHECK_ONLY && changed.length > 0) {
    console.error('UI source inventory is STALE. Re-run: node scripts/generate-ui-source-inventory.mjs\n');
    for (const artifact of changed) {
      console.error(`  - ${artifact.path}`);
    }
    process.exit(1);
  }

  console.log(`UI source inventory OK — ${pages.length} pages across ${ACCOUNTS.length} accounts;`
    + ` ${content.documents.length} role documents; ${brand.assets.filter((a) => a.present).length} brand assets;`
    + ` ${landing.images.length} landing images.`);
  for (const account of accountSummary) {
    console.log(`  ${account.account.padEnd(24)} ${String(account.parsed_pages).padStart(3)} pages  ${account.host}`);
  }
  console.log(CHECK_ONLY ? 'All artifacts up to date.' : `${changed.length} artifact(s) written, ${written.length - changed.length} unchanged.`);
}

main();
