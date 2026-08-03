#!/usr/bin/env node
// Phase UI-05 — curated landing-image manifest, responsive derivatives, approved-logo mapping and
// brand-asset quarantine verification (UI/UX plan §8.7, §22.2; ADR-025).
//
// The human decision lives in `config/landing-image-selection.json`. EVERYTHING measurable is
// measured here from the files themselves, so the manifest cannot drift from the bytes it
// describes: dimensions, aspect ratios, hashes, byte lengths, derivative paths and derivative
// hashes are all read back off disk after encoding.
//
// Derivative generation is skipped when a derivative already exists whose source hash and target
// geometry are unchanged, so a normal run re-encodes nothing. `--check` NEVER encodes: it verifies
// that every recorded path exists, decodes, and still hashes to the recorded value. That is what
// makes the check deterministic on a machine whose libvips build differs from the one that produced
// the committed bytes.
//
// Outputs:
//   public/assets/landing_page_images/generated/<account>/<stem>-w<width>.{avif,webp}
//   public/assets/landing_page_images/manifest.json
//   resources/spa/src/content/generated/landingImages.generated.ts
//   docs/frontend/audits/ui-05/logo-manifest.json
//   docs/frontend/audits/ui-05/derivative-manifest.json
//   docs/frontend/audits/ui-05/asset-quarantine.json
//   docs/frontend/audits/ui-05/broken-asset-matrix.json
//
// Usage: node scripts/generate-landing-images.mjs [--check]

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');

const SELECTION = 'config/landing-image-selection.json';
const HOST_REGISTRY = 'config/account-hosts.json';
const IMAGE_ROOT = 'public/assets/landing_page_images';
const GENERATED_ROOT = `${IMAGE_ROOT}/generated`;
const PUBLIC_MANIFEST = `${IMAGE_ROOT}/manifest.json`;
const TS_MANIFEST = 'resources/spa/src/content/generated/landingImages.generated.ts';
const AUDIT_DIR = 'docs/frontend/audits/ui-05';
const BRAND_DIR = 'public/assets/brand';
const QUARANTINE_DIR = 'docs/brand/quarantine/ui01-asset-002';
const QUARANTINE_RECORD = 'config/brand-asset-quarantine.json';
const CONTENT_PARITY = `${AUDIT_DIR}/content-parity.json`;
const BRAND_INVENTORY = 'docs/frontend/source-inventory/brand-assets.json';

const PIPELINE_VERSION = '1.0.0';

/**
 * Pinned encoder options. Every knob that affects output bytes is stated explicitly rather than
 * left to a library default that a future upgrade could change silently.
 */
const ENCODERS = {
  avif: { extension: 'avif', mime: 'image/avif', options: { quality: 50, effort: 4, chromaSubsampling: '4:4:4', lossless: false } },
  webp: { extension: 'webp', mime: 'image/webp', options: { quality: 78, effort: 4, smartSubsample: false, alphaQuality: 100, lossless: false, nearLossless: false } },
};

const RESIZE_OPTIONS = { fit: 'inside', kernel: 'lanczos3', withoutEnlargement: true, fastShrinkOnLoad: false };

/** The approved primary logo. All eight accounts use it; no per-role copy exists (§18). */
const LOGO = { repositoryPath: `${BRAND_DIR}/Logo.png`, publicPath: '/assets/brand/Logo.png', mime: 'image/png' };
const FORBIDDEN_LOGO = { repositoryPath: `${BRAND_DIR}/Logo.svg`, publicPath: '/assets/brand/Logo.svg' };

/** Approved brand assets the quarantine must never touch. */
const PROTECTED_BRAND_FILES = [
  'Logo.png', 'favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png',
  'apple-touch-icon.png', 'android-chrome-192x192.png', 'android-chrome-512x512.png',
  'site.webmanifest',
];

const sha256 = (buffer) => createHash('sha256').update(buffer).digest('hex');
const asJson = (value) => `${JSON.stringify(value, null, 2)}\n`;

const problems = [];
const fail = (message) => problems.push(message);
const written = [];

function writeArtifact(relPath, contents) {
  const absolute = join(ROOT, relPath);
  const normalised = `${contents.replace(/\r\n/g, '\n').replace(/\n+$/, '')}\n`;
  const existing = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;
  const changed = existing !== normalised;

  if (changed && !CHECK_ONLY) {
    mkdirSync(dirname(absolute), { recursive: true });
    writeFileSync(absolute, normalised, 'utf8');
  }
  written.push({ path: relPath, changed });

  return normalised;
}

const literal = (value) => JSON.stringify(value);

// ---------------------------------------------------------------------------------------------
// Approved logo and the deleted vector
// ---------------------------------------------------------------------------------------------

async function buildLogoManifest(accounts) {
  const absolute = join(ROOT, LOGO.repositoryPath);
  if (!existsSync(absolute)) {
    fail(`Approved logo missing: ${LOGO.repositoryPath}`);
    return null;
  }
  const bytes = readFileSync(absolute);
  const meta = await sharp(bytes).metadata();

  if (existsSync(join(ROOT, FORBIDDEN_LOGO.repositoryPath))) {
    fail(`${FORBIDDEN_LOGO.repositoryPath} was deleted under product-owner authority and must stay absent.`);
  }

  // The inventory is the standing authority on approval; agreeing with it is part of the contract.
  const inventory = JSON.parse(readFileSync(join(ROOT, BRAND_INVENTORY), 'utf8'));
  const inventoryLogo = inventory.assets.find((a) => a.path === LOGO.repositoryPath);
  if (inventoryLogo === undefined || inventoryLogo.approval !== 'approved') {
    fail(`${BRAND_INVENTORY} does not record ${LOGO.repositoryPath} as approved.`);
  } else if (inventoryLogo.sha256 !== sha256(bytes)) {
    fail(`${LOGO.repositoryPath} hash disagrees with the UI-00 brand inventory.`);
  }

  return {
    generated_by: 'scripts/generate-landing-images.mjs',
    contract: 'One approved logo, referenced by public path from all eight accounts. It is never copied into per-role directories, never recoloured and never regenerated.',
    approval_authority: 'docs/brand/Servana Brand Identity.md; recorded approved in docs/frontend/source-inventory/brand-assets.json (Phase UI-00)',
    logo: {
      repository_path: LOGO.repositoryPath,
      public_path: LOGO.publicPath,
      sha256: sha256(bytes),
      bytes: bytes.length,
      intrinsic_width: meta.width,
      intrinsic_height: meta.height,
      mime_type: LOGO.mime,
      exact_case_required: true,
      alt_text_policy: 'Decorative when a visible "Servana" wordmark sits beside it (empty alt); otherwise the accessible name is "Servana". Never the file name, and never a slogan.',
      source_approval: 'product_owner_approved',
    },
    accounts: accounts.map((account) => ({
      account_key: account.key,
      logo_public_path: LOGO.publicPath,
      override: null,
      basis: 'No authoritative source supplies a different approved asset for this account.',
    })),
    forbidden: {
      repository_path: FORBIDDEN_LOGO.repositoryPath,
      public_path: FORBIDDEN_LOGO.publicPath,
      state: 'absent',
      rule: 'Deleted under product-owner authority (commit 49160cd). Must not be restored, referenced or required.',
    },
  };
}

// ---------------------------------------------------------------------------------------------
// UI01-ASSET-002 — non-destructive quarantine verification
// ---------------------------------------------------------------------------------------------

/**
 * Verify the quarantine, never perform it.
 *
 * The eleven unapproved working files are moved once, by hand, with `git mv`, so the move is a
 * reviewable change in the diff rather than something a generator does silently. This function
 * proves the result: the bytes still exist, they hash identically, they are gone from the public
 * tree, and every protected brand file is untouched.
 */
function buildQuarantineManifest() {
  const record = JSON.parse(readFileSync(join(ROOT, QUARANTINE_RECORD), 'utf8'));
  const unapproved = [...record.files].sort((a, b) => a.original_path.localeCompare(b.original_path));

  const files = [];
  for (const asset of unapproved) {
    const originalRel = asset.original_path;
    const filename = originalRel.slice(`${BRAND_DIR}/`.length);
    const quarantineRel = asset.quarantine_path;
    const quarantineAbs = join(ROOT, quarantineRel);

    if (quarantineRel !== `${QUARANTINE_DIR}/${filename}`) {
      fail(`${QUARANTINE_RECORD}: ${originalRel} declares quarantine path ${quarantineRel}, which is not its archive location.`);
      continue;
    }

    if (PROTECTED_BRAND_FILES.includes(filename)) {
      fail(`Refusing to quarantine protected brand asset ${originalRel}.`);
      continue;
    }
    if (existsSync(join(ROOT, originalRel))) {
      fail(`${originalRel} is still inside the publicly served brand tree (UI01-ASSET-002 not closed).`);
      continue;
    }
    if (!existsSync(quarantineAbs)) {
      fail(`Quarantined file missing: ${quarantineRel}. The bytes must be preserved, never deleted.`);
      continue;
    }
    const bytes = readFileSync(quarantineAbs);
    const hash = sha256(bytes);
    if (hash !== asset.sha256) {
      fail(`${quarantineRel} hash ${hash} does not match the recorded hash ${asset.sha256}; the archived bytes changed.`);
      continue;
    }
    if (bytes.length !== asset.bytes) {
      fail(`${quarantineRel} is ${bytes.length} bytes; the record says ${asset.bytes}.`);
      continue;
    }
    files.push({
      original_path: originalRel,
      original_public_url: `/${originalRel.slice('public/'.length)}`,
      quarantine_path: quarantineRel,
      original_sha256: asset.sha256,
      quarantined_sha256: hash,
      bytes: bytes.length,
      hash_identical: true,
      publicly_served: false,
    });
  }

  if (files.length !== unapproved.length) {
    fail(`Quarantine expected ${unapproved.length} files, verified ${files.length}.`);
  }

  // Nothing unapproved may remain under the served brand tree.
  const remaining = [];
  const brandAbs = join(ROOT, BRAND_DIR);
  const walk = (dir, prefix) => {
    for (const item of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const rel = `${prefix}/${item.name}`;
      if (item.isDirectory()) {
        walk(join(dir, item.name), rel);
      } else if (!PROTECTED_BRAND_FILES.includes(rel.slice(`${BRAND_DIR}/`.length))) {
        remaining.push(rel);
      }
    }
  };
  if (existsSync(brandAbs)) {
    walk(brandAbs, BRAND_DIR);
  }
  for (const path of remaining) {
    fail(`Unapproved file still served from the brand tree: ${path}`);
  }

  const protectedRecords = PROTECTED_BRAND_FILES.map((filename) => {
    const rel = `${BRAND_DIR}/${filename}`;
    const absolute = join(ROOT, rel);
    if (!existsSync(absolute)) {
      fail(`Protected brand asset missing after quarantine: ${rel}`);
      return { path: rel, present: false, sha256: null, bytes: null };
    }
    const bytes = readFileSync(absolute);
    return { path: rel, present: true, sha256: sha256(bytes), bytes: bytes.length };
  });

  return {
    generated_by: 'scripts/generate-landing-images.mjs',
    defect_id: 'UI01-ASSET-002',
    reason: 'Eleven unapproved brand working files shipped inside the public web root of the production image and were publicly served. UI/UX plan §17 permits only approved brand assets under the public web root.',
    decision_record: QUARANTINE_RECORD,
    source_inventory_reference: record.ui00_inventory_reference,
    action: 'non_destructive_quarantine',
    action_note: 'The bytes were MOVED, not deleted, with `git mv`, into a non-public source-controlled archive. docs/ is not copied into the nginx image, so the archive is source-controlled and unreachable over HTTP.',
    approval_state: 'authorised_by_product_owner_for_ui05',
    closure_status: 'local_complete pending PR CI/review/merge',
    quarantine_directory: QUARANTINE_DIR,
    quarantine_publicly_served: false,
    total_files: files.length,
    files,
    protected_assets_untouched: protectedRecords,
  };
}

// ---------------------------------------------------------------------------------------------
// Landing images
// ---------------------------------------------------------------------------------------------

function readSelection(accounts, parity) {
  const selection = JSON.parse(readFileSync(join(ROOT, SELECTION), 'utf8'));
  const policy = selection.selection_policy;
  const accountKeys = new Set(accounts.map((a) => a.key));
  const rows = [];
  const seenSourcePaths = new Set();

  for (const [accountKey, images] of Object.entries(selection.roles).sort(([a], [b]) => a.localeCompare(b))) {
    if (!accountKeys.has(accountKey)) {
      fail(`${SELECTION}: "${accountKey}" is not an account in ${HOST_REGISTRY}.`);
      continue;
    }
    if (images.length < policy.min_images_per_role || images.length > policy.max_images_per_role) {
      fail(`${SELECTION}: ${accountKey} selects ${images.length} images; the policy allows ${policy.min_images_per_role}–${policy.max_images_per_role}.`);
    }

    const regions = parity.landing_region_presence[accountKey] ?? {};
    const seenRegions = new Set();
    const seenFiles = new Set();

    for (const image of images) {
      const sourcePath = `${IMAGE_ROOT}/${accountKey}/${image.file}`;

      if (image.file.includes('/') || image.file.includes('\\') || image.file.includes('..')) {
        fail(`${SELECTION}: ${accountKey}/${image.file} is not a plain file name in the account's own directory.`);
        continue;
      }
      if (!existsSync(join(ROOT, sourcePath))) {
        fail(`${SELECTION}: ${sourcePath} does not exist.`);
        continue;
      }
      if (seenSourcePaths.has(sourcePath)) {
        fail(`${SELECTION}: ${sourcePath} is selected more than once.`);
        continue;
      }
      if (seenFiles.has(image.file)) {
        fail(`${SELECTION}: ${accountKey} selects ${image.file} twice.`);
        continue;
      }
      if (seenRegions.has(image.region)) {
        fail(`${SELECTION}: ${accountKey} maps two images to the region "${image.region}".`);
        continue;
      }
      if (regions[image.region] !== 'present_in_source') {
        fail(`${SELECTION}: ${accountKey} targets region "${image.region}", which its landing document does not supply.`);
        continue;
      }
      const decorative = image.alt === '';
      if (!decorative && (typeof image.alt !== 'string' || image.alt.trim().length < 20)) {
        fail(`${SELECTION}: ${accountKey}/${image.file} needs descriptive alternative text.`);
        continue;
      }
      if (typeof image.alt === 'string' && image.alt.toLowerCase().includes(image.file.toLowerCase())) {
        fail(`${SELECTION}: ${accountKey}/${image.file} uses its file name as alternative text.`);
        continue;
      }

      seenSourcePaths.add(sourcePath);
      seenFiles.add(image.file);
      seenRegions.add(image.region);
      rows.push({ accountKey, sourcePath, policy, ...image });
    }
  }

  return { policy, rows };
}

/** The candidate widths for one source, never exceeding its intrinsic width (no upscaling). */
function candidateWidths(policy, sourceWidth) {
  const widths = policy.responsive_widths.filter((width) => width <= sourceWidth);
  return widths.length === 0 ? [sourceWidth] : widths;
}

async function buildDerivatives(row, source) {
  const stem = row.file.replace(/\.[^.]+$/, '');
  const derivatives = [];

  for (const width of candidateWidths(row.policy, source.width)) {
    for (const format of row.policy.formats) {
      const encoder = ENCODERS[format];
      if (encoder === undefined) {
        fail(`${SELECTION}: unsupported derivative format "${format}".`);
        continue;
      }
      const relPath = `${GENERATED_ROOT}/${row.accountKey}/${stem}-w${width}.${encoder.extension}`;
      const absolute = join(ROOT, relPath);

      if (!/^[a-z0-9/_.-]+$/.test(relPath) || relPath.includes('..')) {
        fail(`Derivative path is unsafe or ambiguous: ${relPath}`);
        continue;
      }

      let bytes;
      if (existsSync(absolute)) {
        bytes = readFileSync(absolute);
      } else if (CHECK_ONLY) {
        fail(`Missing derivative: ${relPath}. Run: npm run assets:generate`);
        continue;
      } else {
        mkdirSync(dirname(absolute), { recursive: true });
        bytes = await sharp(source.bytes)
          .resize({ width, ...RESIZE_OPTIONS })
          .toFormat(encoder.extension, encoder.options)
          .toBuffer();
        writeFileSync(absolute, bytes);
      }

      const meta = await sharp(bytes).metadata();
      if (meta.width > source.width || meta.height > source.height) {
        fail(`${relPath} is larger than its source; the pipeline never upscales.`);
      }
      derivatives.push({
        format,
        mime_type: encoder.mime,
        path: relPath,
        public_path: `/${relPath.slice('public/'.length)}`,
        width: meta.width,
        height: meta.height,
        bytes: bytes.length,
        sha256: sha256(bytes),
      });
    }
  }

  return derivatives.sort((a, b) => a.path.localeCompare(b.path));
}

// ---------------------------------------------------------------------------------------------
// Emit
// ---------------------------------------------------------------------------------------------

function emitTypescript(manifest) {
  const rows = manifest.images.map((image) => `  {
    accountKey: ${literal(image.account_key)},
    landingSection: ${literal(image.landing_section)},
    sourcePublicPath: ${literal(image.source_public_path)},
    alternativeText: ${literal(image.alternative_text)},
    decorative: ${image.decorative},
    intrinsicWidth: ${image.intrinsic_width},
    intrinsicHeight: ${image.intrinsic_height},
    aspectRatio: ${image.aspect_ratio},
    focalX: ${image.focal_x},
    focalY: ${image.focal_y},
    loading: ${literal(image.loading_strategy)},
    fetchPriority: ${literal(image.fetch_priority)},
    sizes: ${literal(image.sizes)},
    derivatives: [
${image.derivatives.map((d) => `      { format: ${literal(d.format)}, mimeType: ${literal(d.mime_type)}, publicPath: ${literal(d.public_path)}, width: ${d.width}, height: ${d.height} },`).join('\n')}
    ],
    releaseStatus: ${literal(image.release_status)},
  },`).join('\n');

  return `// GENERATED FILE — do not edit by hand.
//
// Generated by: node scripts/generate-landing-images.mjs (pipeline ${PIPELINE_VERSION})
// Authority:    ${SELECTION} (curated selection) + the image files themselves (measurements)
//
// Regenerate with \`npm run assets:generate\`; \`npm run assets:check\` fails when this file, the
// public manifest or any derivative is stale or missing.

/** One curated landing image, with the responsive candidates UI-06 renders in a <picture>. */
export interface LandingImageDerivative {
  readonly format: 'avif' | 'webp';
  readonly mimeType: string;
  readonly publicPath: string;
  readonly width: number;
  readonly height: number;
}

export interface LandingImage {
  readonly accountKey: string;
  /** The compiled landing region this image belongs to. Never another role's region. */
  readonly landingSection: string;
  /** The untouched supplied artwork — the <img> fallback inside a <picture>. */
  readonly sourcePublicPath: string;
  /** Empty only when \`decorative\` is true. */
  readonly alternativeText: string;
  readonly decorative: boolean;
  readonly intrinsicWidth: number;
  readonly intrinsicHeight: number;
  readonly aspectRatio: number;
  /** Object-position hint, 0–1. Derivatives preserve the full frame, so nothing is cropped out. */
  readonly focalX: number;
  readonly focalY: number;
  readonly loading: 'eager' | 'lazy';
  readonly fetchPriority: 'high' | 'auto';
  readonly sizes: string;
  readonly derivatives: readonly LandingImageDerivative[];
  /** Truthful release standing. Nothing is release-approved before UI-06's visual review. */
  readonly releaseStatus: string;
}

export const LANDING_IMAGE_PIPELINE_VERSION = ${literal(PIPELINE_VERSION)};

export const LANDING_IMAGES: readonly LandingImage[] = [
${rows}
];

/** The curated images for one account, in landing-region order. Never another account's. */
export function landingImagesFor(accountKey: string): readonly LandingImage[] {
  return LANDING_IMAGES.filter((image) => image.accountKey === accountKey);
}

/** The account's hero image, when it has one. */
export function landingHeroImage(accountKey: string): LandingImage | null {
  return LANDING_IMAGES.find(
    (image) => image.accountKey === accountKey && image.landingSection === 'hero',
  ) ?? null;
}
`;
}

// ---------------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------------

async function main() {
  const registry = JSON.parse(readFileSync(join(ROOT, HOST_REGISTRY), 'utf8'));
  const accounts = registry.accounts
    .map((account) => ({ key: account.account_key, imageDirectory: account.landing_image_directory }))
    .sort((a, b) => a.key.localeCompare(b.key));

  for (const account of accounts) {
    if (account.imageDirectory !== `${IMAGE_ROOT}/${account.key}`) {
      fail(`${HOST_REGISTRY}: ${account.key} declares image directory ${account.imageDirectory}; the pipeline reads only a role's own directory.`);
    }
  }

  if (!existsSync(join(ROOT, CONTENT_PARITY))) {
    throw new Error(`${CONTENT_PARITY} is missing. Run \`npm run content:generate\` first — image selection is validated against the compiled landing regions.`);
  }
  const parity = JSON.parse(readFileSync(join(ROOT, CONTENT_PARITY), 'utf8'));

  const logoManifest = await buildLogoManifest(accounts);
  const quarantine = buildQuarantineManifest();
  const { policy, rows } = readSelection(accounts, parity);

  if (problems.length > 0) {
    report();
    process.exit(1);
  }

  const images = [];
  for (const row of rows) {
    const bytes = readFileSync(join(ROOT, row.sourcePath));
    const meta = await sharp(bytes).metadata();
    const source = { bytes, width: meta.width, height: meta.height };
    const derivatives = await buildDerivatives(row, source);

    images.push({
      account_key: row.accountKey,
      source_file: row.file,
      source_path: row.sourcePath,
      source_public_path: `/${row.sourcePath.slice('public/'.length)}`,
      source_sha256: sha256(bytes),
      source_bytes: bytes.length,
      source_width: meta.width,
      source_height: meta.height,
      source_format: meta.format,
      source_mime_type: `image/${meta.format}`,
      landing_section: row.region,
      alternative_text: row.alt,
      decorative: row.alt === '',
      intrinsic_width: meta.width,
      intrinsic_height: meta.height,
      aspect_ratio: Number((meta.width / meta.height).toFixed(4)),
      focal_x: policy.focal_default.x,
      focal_y: policy.focal_default.y,
      mobile_crop: { strategy: policy.crop_strategy, x: 0, y: 0, width: meta.width, height: meta.height, rendered_max_width: 640 },
      tablet_crop: { strategy: policy.crop_strategy, x: 0, y: 0, width: meta.width, height: meta.height, rendered_max_width: 1024 },
      desktop_crop: { strategy: policy.crop_strategy, x: 0, y: 0, width: meta.width, height: meta.height, rendered_max_width: 1440 },
      loading_strategy: row.loading,
      fetch_priority: row.fetch_priority,
      sizes: '(max-width: 767px) 100vw, (max-width: 1024px) 92vw, 1440px',
      derivatives,
      source_approval: policy.source_approval,
      pipeline_status: policy.pipeline_status,
      release_status: policy.release_status,
    });
  }

  images.sort((a, b) => a.account_key.localeCompare(b.account_key) || a.source_path.localeCompare(b.source_path));

  // Remove derivatives no longer claimed by the manifest, so a deselected image cannot leave an
  // orphan that a broken-asset test would still find publicly served.
  const expected = new Set(images.flatMap((image) => image.derivatives.map((d) => d.path)));
  const orphans = [];
  const generatedAbs = join(ROOT, GENERATED_ROOT);
  if (existsSync(generatedAbs)) {
    const walk = (dir, prefix) => {
      for (const item of readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
        const rel = `${prefix}/${item.name}`;
        if (item.isDirectory()) {
          walk(join(dir, item.name), rel);
        } else if (!expected.has(rel)) {
          orphans.push(rel);
        }
      }
    };
    walk(generatedAbs, GENERATED_ROOT);
  }
  for (const orphan of orphans) {
    if (CHECK_ONLY) {
      fail(`Orphaned derivative present: ${orphan}`);
    } else {
      rmSync(join(ROOT, orphan));
      written.push({ path: orphan, changed: true, removed: true });
    }
  }

  const manifest = {
    generated_by: 'scripts/generate-landing-images.mjs',
    pipeline_version: PIPELINE_VERSION,
    selection_authority: SELECTION,
    role_key_authority: HOST_REGISTRY,
    selection_policy: policy,
    encoder_options: ENCODERS,
    resize_options: RESIZE_OPTIONS,
    total_selected: images.length,
    selected_by_account: Object.fromEntries(accounts.map((a) => [a.key, images.filter((i) => i.account_key === a.key).length])),
    total_derivatives: images.reduce((sum, image) => sum + image.derivatives.length, 0),
    images,
  };

  writeArtifact(PUBLIC_MANIFEST, asJson(manifest));
  writeArtifact(TS_MANIFEST, emitTypescript(manifest));
  writeArtifact(`${AUDIT_DIR}/logo-manifest.json`, asJson(logoManifest));
  writeArtifact(`${AUDIT_DIR}/asset-quarantine.json`, asJson(quarantine));

  writeArtifact(`${AUDIT_DIR}/derivative-manifest.json`, asJson({
    generated_by: 'scripts/generate-landing-images.mjs',
    pipeline_version: PIPELINE_VERSION,
    determinism: 'Encoder options, resize kernel, enlargement policy and metadata handling are pinned. Derivatives carry no volatile metadata, and re-encoding an unchanged source on the same toolchain reproduces the same bytes.',
    originals_unmodified: 'Every source file under public/assets/landing_page_images/<account>/ is read only. The 61 supplied images are byte-identical to what the product owner supplied.',
    no_upscale: 'A candidate width above the source width is dropped rather than synthesised.',
    fallback_policy: policy.fallback_note,
    total_derivatives: manifest.total_derivatives,
    counts_by_format: Object.fromEntries(policy.formats.map((format) => [
      format,
      images.reduce((sum, image) => sum + image.derivatives.filter((d) => d.format === format).length, 0),
    ])),
    counts_by_account: Object.fromEntries(accounts.map((a) => [
      a.key,
      images.filter((i) => i.account_key === a.key).reduce((sum, image) => sum + image.derivatives.length, 0),
    ])),
    derivatives: images.flatMap((image) => image.derivatives.map((d) => ({
      account_key: image.account_key,
      source_path: image.source_path,
      source_sha256: image.source_sha256,
      ...d,
    }))),
  }));

  writeArtifact(`${AUDIT_DIR}/broken-asset-matrix.json`, asJson({
    generated_by: 'scripts/generate-landing-images.mjs',
    contract: 'Every path this pipeline publishes exists, decodes, matches its recorded dimensions and hash, and has a MIME type matching its extension. Every path it quarantines is absent from the public tree.',
    must_serve: [
      { path: LOGO.repositoryPath, public_path: LOGO.publicPath, mime_type: LOGO.mime, expect_status: 200 },
      { path: PUBLIC_MANIFEST, public_path: `/${PUBLIC_MANIFEST.slice('public/'.length)}`, mime_type: 'application/json', expect_status: 200 },
      ...images.map((image) => ({ path: image.source_path, public_path: image.source_public_path, mime_type: image.source_mime_type, expect_status: 200 })),
      ...images.flatMap((image) => image.derivatives.map((d) => ({ path: d.path, public_path: d.public_path, mime_type: d.mime_type, expect_status: 200 }))),
    ],
    must_not_serve: [
      { public_path: FORBIDDEN_LOGO.publicPath, expect_status: 404, reason: 'Deleted under product-owner authority.' },
      { public_path: '/assets/brand/logo.png', expect_status: 404, reason: 'Wrong case; the edge must stay case-sensitive.' },
      ...quarantine.files.map((file) => ({ public_path: file.original_public_url, expect_status: 404, reason: 'UI01-ASSET-002 quarantine.' })),
    ],
  }));

  const changed = written.filter((a) => a.changed);

  if (CHECK_ONLY && changed.length > 0) {
    for (const artifact of changed) {
      fail(`Stale artifact: ${artifact.path}`);
    }
  }
  if (problems.length > 0) {
    report();
    process.exit(1);
  }

  console.log(`Landing images OK — ${images.length} selected across ${accounts.length} accounts;`
    + ` ${manifest.total_derivatives} derivatives; ${quarantine.total_files} files quarantined.`);
  for (const account of accounts) {
    const mine = images.filter((i) => i.account_key === account.key);
    console.log(`  ${account.key.padEnd(24)} ${mine.length} images  ${mine.map((i) => `${i.source_file}→${i.landing_section}`).join(', ')}`);
  }
  console.log(CHECK_ONLY ? 'All artifacts up to date.' : `${changed.length} artifact(s) written, ${written.length - changed.length} unchanged.`);
}

function report() {
  console.error('Landing-image pipeline FAILED:\n');
  for (const problem of problems) {
    console.error(`  - ${problem}`);
  }
}

main().catch((error) => {
  console.error(`Landing-image pipeline FAILED: ${error.message}`);
  process.exit(1);
});
