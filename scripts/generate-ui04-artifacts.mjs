#!/usr/bin/env node
// Phase UI-04 — derive the machine-readable audit artifacts from their real sources.
//
// These are DERIVED, never hand-maintained, so they cannot drift from the code they describe:
//
//   docs/frontend/audits/ui-04/token-manifest.json      <- tokens.json + generated consumers
//   docs/frontend/audits/ui-04/component-contracts.json <- componentRegistry.ts
//   docs/frontend/audits/ui-04/component-coverage.json  <- registry + the filesystem
//
// Usage: node scripts/generate-ui04-artifacts.mjs [--check]

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');
const OUT = 'docs/frontend/audits/ui-04';

const sha256 = (path) =>
  existsSync(join(ROOT, path)) ? createHash('sha256').update(readFileSync(join(ROOT, path))).digest('hex') : null;

const tokens = JSON.parse(readFileSync(join(ROOT, 'resources/spa/src/design-system/tokens.json'), 'utf8'));

// ---------------------------------------------------------------------------
// Token manifest
// ---------------------------------------------------------------------------

const palette = Object.fromEntries(tokens.palette.map((entry) => [entry.name, entry.value]));
const resolveSide = (token, theme) =>
  token.raw === true ? token[theme] : (palette[token[theme]] ?? null);

function tokenManifest() {
  return {
    generated_by: 'node scripts/generate-ui04-artifacts.mjs',
    phase: 'UI-04',
    schema_version: tokens.schema_version,
    authority: {
      path: 'resources/spa/src/design-system/tokens.json',
      sha256: sha256('resources/spa/src/design-system/tokens.json'),
    },
    generator: {
      command: 'node scripts/generate-design-tokens.mjs',
      check: 'node scripts/generate-design-tokens.mjs --check',
    },
    generated_consumers: [
      {
        path: 'resources/spa/src/styles/generated/tokens.css',
        sha256: sha256('resources/spa/src/styles/generated/tokens.css'),
      },
      {
        path: 'resources/spa/src/design-system/tokens.generated.ts',
        sha256: sha256('resources/spa/src/design-system/tokens.generated.ts'),
      },
    ],
    tailwind_config: { path: 'tailwind.config.ts', sha256: sha256('tailwind.config.ts') },
    counts: {
      palette: tokens.palette.length,
      semantic: tokens.semantic.length,
      component: tokens.component.length,
      typography_scale_steps: tokens.typography.scale.length,
      contrast_requirements: tokens.contrast_requirements.length,
    },
    breakpoints: tokens.breakpoints,
    typography: {
      families: tokens.typography.families,
      scale: tokens.typography.scale,
      weights: tokens.typography.weights,
    },
    palette: tokens.palette,
    semantic: tokens.semantic.map((token) => ({
      name: token.name,
      light: resolveSide(token, 'light'),
      dark: resolveSide(token, 'dark'),
      note: token.note ?? null,
    })),
    component: tokens.component,
    legacy_aliases: tokens.legacy_aliases,
    contrast_requirements: tokens.contrast_requirements,
  };
}

// ---------------------------------------------------------------------------
// Component contracts + coverage, parsed from the registry
// ---------------------------------------------------------------------------

const registrySource = readFileSync(
  join(ROOT, 'resources/spa/src/design-system/componentRegistry.ts'),
  'utf8',
);

const constants = Object.fromEntries(
  [...registrySource.matchAll(/^const (\w+) = `\$\{UI\}([^`]+)`;$/gm)].map((m) => [
    m[1],
    `resources/spa/src/components/ui/${m[2]}`,
  ]),
);

function parseRegistry() {
  const entries = [];
  const blocks = registrySource.split(/\n  \{\n/).slice(1);

  for (const block of blocks) {
    const field = (name) => block.match(new RegExp(`${name}: '([^']*)'`))?.[1] ?? null;
    const name = field('name');
    if (name === null) {
      continue;
    }
    const sourceTemplate = block.match(/source: `\$\{UI\}([^`]+)`/)?.[1];
    const source = sourceTemplate !== undefined
      ? `resources/spa/src/components/ui/${sourceTemplate}`
      : field('source');
    const testRef = block.match(/test: (\w+|'[^']+'),/)?.[1] ?? '';
    const test = testRef.startsWith("'") ? testRef.slice(1, -1) : (constants[testRef] ?? testRef);
    const states = (block.match(/states: \[([^\]]*)\]/)?.[1] ?? '')
      .split(',')
      .map((s) => s.trim().replace(/'/g, ''))
      .filter(Boolean);

    entries.push({
      name,
      category: field('category'),
      source,
      test,
      purpose: field('purpose'),
      states,
      keyboard: field('keyboard'),
      responsive: field('responsive'),
      theme: field('theme'),
    });
  }

  return entries;
}

const registry = parseRegistry();

function componentContracts() {
  return {
    generated_by: 'node scripts/generate-ui04-artifacts.mjs',
    phase: 'UI-04',
    authority: {
      path: 'resources/spa/src/design-system/componentRegistry.ts',
      sha256: sha256('resources/spa/src/design-system/componentRegistry.ts'),
    },
    enforced_by: 'tests/Feature/DesignSystem/ComponentContractTest.php',
    note:
      'A component with no loading/disabled/error state declares an empty `states` list rather than implementing fake behaviour — the contract is honest, not aspirational.',
    total: registry.length,
    by_category: Object.fromEntries(
      [...new Set(registry.map((c) => c.category))].sort().map((category) => [
        category,
        registry.filter((c) => c.category === category).length,
      ]),
    ),
    components: registry,
  };
}

function componentCoverage() {
  const rows = registry.map((entry) => ({
    name: entry.name,
    category: entry.category,
    source_exists: existsSync(join(ROOT, entry.source)),
    test_exists: existsSync(join(ROOT, entry.test)),
    test: entry.test,
    referenced_in_test: existsSync(join(ROOT, entry.test))
      ? readFileSync(join(ROOT, entry.test), 'utf8').includes(entry.name)
      : false,
    in_design_system_fixture: readFileSync(
      join(ROOT, 'resources/spa/src/pages/dev/DesignSystemDemo.vue'),
      'utf8',
    ).includes(entry.name),
    source_bytes: existsSync(join(ROOT, entry.source))
      ? readFileSync(join(ROOT, entry.source)).length
      : 0,
  }));

  return {
    generated_by: 'node scripts/generate-ui04-artifacts.mjs',
    phase: 'UI-04',
    note:
      'in_design_system_fixture records which components the shared fixture route renders directly. A false value is not a gap in itself — several components are exercised through a parent (SvFormField through every input, SvSkeleton through SvMetricCard) — but every component has a real behavioural spec, which is the enforced requirement.',
    total: rows.length,
    with_source: rows.filter((r) => r.source_exists).length,
    with_test: rows.filter((r) => r.test_exists && r.referenced_in_test).length,
    rendered_in_fixture: rows.filter((r) => r.in_design_system_fixture).length,
    components: rows,
  };
}

// ---------------------------------------------------------------------------
// Write
// ---------------------------------------------------------------------------

function write(relative, value) {
  const absolute = join(ROOT, relative);
  const contents = `${JSON.stringify(value, null, 2)}\n`;
  const existing = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;

  if (existing === contents) {
    return { path: relative, changed: false };
  }
  if (!CHECK_ONLY) {
    mkdirSync(dirname(absolute), { recursive: true });
    writeFileSync(absolute, contents, 'utf8');
  }

  return { path: relative, changed: true };
}

const written = [
  write(`${OUT}/token-manifest.json`, tokenManifest()),
  write(`${OUT}/component-contracts.json`, componentContracts()),
  write(`${OUT}/component-coverage.json`, componentCoverage()),
];
const changed = written.filter((a) => a.changed);

if (CHECK_ONLY && changed.length > 0) {
  console.error('UI-04 audit artifacts are STALE. Re-run: node scripts/generate-ui04-artifacts.mjs\n');
  for (const artifact of changed) {
    console.error(`  - ${artifact.path}`);
  }
  process.exit(1);
}

console.log(
  `UI-04 artifacts OK — ${registry.length} components, ${tokens.semantic.length} semantic tokens, ` +
    `${tokens.contrast_requirements.length} contrast requirements.`,
);
console.log(CHECK_ONLY ? 'All artifacts up to date.' : `${changed.length} written, ${written.length - changed.length} unchanged.`);
