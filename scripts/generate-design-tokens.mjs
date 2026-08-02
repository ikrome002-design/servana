#!/usr/bin/env node
// Phase UI-04 — design-token generator (ADR-021, ADR-024; UI/UX plan §9.2, §9.3, §9.6, §13.2).
//
// `resources/spa/src/design-system/tokens.json` is the SINGLE authority for Servana's brand,
// semantic and component tokens. This script derives every consumer that cannot read it at
// runtime:
//
//   resources/spa/src/styles/generated/tokens.css        CSS custom properties, light + dark
//   resources/spa/src/design-system/tokens.generated.ts  typed constants + the breakpoint contract
//
// Output is deterministic and ordered, so a second run produces no diff. It reads only the
// filesystem and never the network.
//
// Usage: node scripts/generate-design-tokens.mjs [--check]

import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');

const SOURCE = 'resources/spa/src/design-system/tokens.json';
const CSS_TARGET = 'resources/spa/src/styles/generated/tokens.css';
const TS_TARGET = 'resources/spa/src/design-system/tokens.generated.ts';

const raw = readFileSync(join(ROOT, SOURCE), 'utf8');
const source = JSON.parse(raw);
const SOURCE_SHA256 = createHash('sha256').update(raw).digest('hex');

const THEMES = ['light', 'dark'];
const HEX = /^#[0-9A-F]{6}$/;

// ---------------------------------------------------------------------------
// Colour maths — the contrast contract is CHECKED, not asserted by eye.
// ---------------------------------------------------------------------------

function channel(value) {
  const c = value / 255;

  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

function relativeLuminance(hex) {
  const r = Number.parseInt(hex.slice(1, 3), 16);
  const g = Number.parseInt(hex.slice(3, 5), 16);
  const b = Number.parseInt(hex.slice(5, 7), 16);

  return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

export function contrastRatio(foreground, background) {
  const a = relativeLuminance(foreground);
  const b = relativeLuminance(background);
  const lighter = Math.max(a, b);
  const darker = Math.min(a, b);

  return (lighter + 0.05) / (darker + 0.05);
}

// ---------------------------------------------------------------------------
// Resolution
// ---------------------------------------------------------------------------

const paletteByName = new Map(source.palette.map((entry) => [entry.name, entry]));

/**
 * Resolve one side of a semantic token. A semantic token names a palette entry unless it is
 * explicitly marked `raw` (used only for alpha values, which no palette entry can express).
 */
function resolveSemantic(token, theme) {
  const declared = token[theme];
  if (token.raw === true) {
    return { value: declared, isHex: false };
  }
  const entry = paletteByName.get(declared);
  if (entry === undefined) {
    return { value: null, isHex: false, missing: declared };
  }

  return { value: entry.value, isHex: HEX.test(entry.value) };
}

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

function validate() {
  const problems = [];

  const seen = new Set();
  const addName = (name, where) => {
    if (seen.has(name)) {
      problems.push(`duplicate token name '${name}' (${where})`);
    }
    seen.add(name);
  };

  for (const entry of source.palette) {
    if (!HEX.test(entry.value)) {
      problems.push(`palette '${entry.name}': value must be an uppercase 6-digit hex, found '${entry.value}'`);
    }
    addName(`palette:${entry.name}`, 'palette');
  }

  for (const token of source.semantic) {
    addName(`semantic:${token.name}`, 'semantic');
    if (!token.name.startsWith('color-')) {
      problems.push(`semantic '${token.name}': every semantic token in this layer is a colour and must be named color-*`);
    }
    for (const theme of THEMES) {
      if (token[theme] === undefined) {
        problems.push(`semantic '${token.name}': missing '${theme}' value — both themes are mandatory (ADR-021 §6)`);

        continue;
      }
      const resolved = resolveSemantic(token, theme);
      if (resolved.missing !== undefined) {
        problems.push(`semantic '${token.name}' (${theme}): references unknown palette entry '${resolved.missing}'`);
      }
    }
  }

  for (const token of source.component) {
    addName(`component:${token.name}`, 'component');
    if (typeof token.value !== 'string') {
      problems.push(`component '${token.name}': value must be a string`);
    }
    if (typeof token.value === 'string' && /#[0-9a-fA-F]{3,8}\b/.test(token.value) && !token.value.includes('rgb')) {
      problems.push(`component '${token.name}': component tokens may not carry a raw colour`);
    }
  }

  for (const family of source.typography.families) {
    addName(`typography:${family.name}`, 'typography');
  }
  for (const step of source.typography.scale) {
    addName(`typography:${step.name}`, 'typography');
    if (typeof step.line_height !== 'string') {
      problems.push(`typography '${step.name}': every scale step needs a line_height`);
    }
  }
  for (const weight of source.typography.weights) {
    addName(`typography:${weight.name}`, 'typography');
  }

  // The breakpoint contract is binding (UI/UX plan §13.2) and may not drift.
  const bp = source.breakpoints;
  if (bp.mobile_max_px !== 767 || bp.tablet_min_px !== 768 || bp.tablet_max_px !== 1024 || bp.desktop_min_px !== 1025) {
    problems.push('breakpoints must be exactly mobile ≤767, tablet 768–1024, desktop ≥1025 (UI/UX plan §13.2)');
  }

  // Legacy aliases must resolve to a real semantic or component token.
  const semanticNames = new Set(source.semantic.map((t) => t.name));
  const componentNames = new Set(source.component.map((t) => t.name));
  for (const [alias, target] of Object.entries(source.legacy_aliases.map)) {
    if (!semanticNames.has(target) && !componentNames.has(target)) {
      problems.push(`legacy alias '${alias}': target token '${target}' does not exist`);
    }
  }

  // Contrast contract — evaluated in BOTH themes.
  for (const requirement of source.contrast_requirements) {
    for (const theme of THEMES) {
      const fg = source.semantic.find((t) => t.name === requirement.foreground);
      const bg = source.semantic.find((t) => t.name === requirement.background);
      if (fg === undefined || bg === undefined) {
        problems.push(`contrast '${requirement.id}': names a semantic token that does not exist`);

        continue;
      }
      const fgValue = resolveSemantic(fg, theme);
      const bgValue = resolveSemantic(bg, theme);
      if (!fgValue.isHex || !bgValue.isHex) {
        problems.push(`contrast '${requirement.id}' (${theme}): both sides must resolve to a hex colour`);

        continue;
      }
      const ratio = contrastRatio(fgValue.value, bgValue.value);
      if (ratio + 1e-9 < requirement.min_ratio) {
        problems.push(
          `contrast '${requirement.id}' (${theme}): ${fgValue.value} on ${bgValue.value} is ${ratio.toFixed(2)}:1, below the required ${requirement.min_ratio}:1`,
        );
      }
    }
  }

  return problems;
}

// ---------------------------------------------------------------------------
// Emit — CSS
// ---------------------------------------------------------------------------

function cssBlock(theme, indent = '  ') {
  const lines = [];
  for (const token of source.semantic) {
    const resolved = resolveSemantic(token, theme);
    lines.push(`${indent}--sv-${token.name}: ${resolved.value};`);
  }

  return lines.join('\n');
}

function buildCss() {
  const componentLines = source.component.map((token) => `  --sv-${token.name}: ${token.value};`);
  const familyLines = source.typography.families.map((f) => `  --sv-${f.name}: ${f.value};`);
  const scaleLines = source.typography.scale.flatMap((s) => [
    `  --sv-${s.name}: ${s.value};`,
    `  --sv-${s.name.replace('font-size-', 'line-height-')}: ${s.line_height};`,
  ]);
  const weightLines = source.typography.weights.map((w) => `  --sv-${w.name}: ${w.value};`);

  const aliasLines = Object.entries(source.legacy_aliases.map).map(
    ([alias, target]) => `  ${alias}: var(--sv-${target});`,
  );

  return `/* GENERATED FILE — do not edit by hand.
 *
 * Source:     ${SOURCE}
 * Generator:  node scripts/generate-design-tokens.mjs
 * Verify:     node scripts/generate-design-tokens.mjs --check
 * Source SHA-256: ${SOURCE_SHA256}
 *
 * Servana design tokens (ADR-021). Light is the DEFAULT: the dark block applies only when the
 * root element carries the \`dark\` class, which is set exclusively from an explicit Servana
 * preference. The operating-system colour-scheme media feature is never consulted here — a
 * contract \`DesignTokenGenerationTest\` enforces by scanning this file.
 */

:root {
  color-scheme: light;

  /* --- semantic colour (light) --- */
${cssBlock('light')}

  /* --- component --- */
${componentLines.join('\n')}

  /* --- typography --- */
${familyLines.join('\n')}
${scaleLines.join('\n')}
${weightLines.join('\n')}

  /* --- legacy aliases (generated; see tokens.json legacy_aliases) --- */
${aliasLines.join('\n')}
}

:root.dark {
  color-scheme: dark;

  /* --- semantic colour (dark) --- */
${cssBlock('dark')}
}
`;
}

// ---------------------------------------------------------------------------
// Emit — TypeScript
// ---------------------------------------------------------------------------

function tsRecord(entries, indent = '  ') {
  return entries.map(([key, value]) => `${indent}'${key}': ${JSON.stringify(value)},`).join('\n');
}

function buildTs() {
  const semanticNames = source.semantic.map((t) => t.name);
  const componentNames = source.component.map((t) => t.name);

  const lightPairs = source.semantic.map((t) => [t.name, resolveSemantic(t, 'light').value]);
  const darkPairs = source.semantic.map((t) => [t.name, resolveSemantic(t, 'dark').value]);
  const componentPairs = source.component.map((t) => [t.name, t.value]);
  const palettePairs = source.palette.map((p) => [p.name, p.value]);

  const bp = source.breakpoints;

  return `// GENERATED FILE — do not edit by hand.
//
// Source:     ${SOURCE}
// Generator:  node scripts/generate-design-tokens.mjs
// Verify:     node scripts/generate-design-tokens.mjs --check
// Source SHA-256: ${SOURCE_SHA256}
//
// Typed access to the Servana design tokens (ADR-021). Components should consume the CSS custom
// properties (via Tailwind's semantic aliases) rather than these constants; these exist so tests,
// contrast guards and the design-system fixture can reason about token VALUES deterministically.

export const DESIGN_TOKEN_SCHEMA_VERSION = '${source.schema_version}';
export const DESIGN_TOKEN_SOURCE_SHA256 = '${SOURCE_SHA256}';

export type ThemeName = 'light' | 'dark';

export type SemanticColorToken =
${semanticNames.map((n) => `  | '${n}'`).join('\n')};

export type ComponentToken =
${componentNames.map((n) => `  | '${n}'`).join('\n')};

/** Raw brand palette. Present for provenance and tests — never consume directly in a component. */
export const PALETTE: Readonly<Record<string, string>> = Object.freeze({
${tsRecord(palettePairs)}
});

export const SEMANTIC_COLORS: Readonly<Record<ThemeName, Readonly<Record<SemanticColorToken, string>>>> =
  Object.freeze({
    light: Object.freeze({
${tsRecord(lightPairs, '      ')}
    }),
    dark: Object.freeze({
${tsRecord(darkPairs, '      ')}
    }),
  }) as Readonly<Record<ThemeName, Readonly<Record<SemanticColorToken, string>>>>;

export const COMPONENT_TOKENS: Readonly<Record<ComponentToken, string>> = Object.freeze({
${tsRecord(componentPairs)}
}) as Readonly<Record<ComponentToken, string>>;

/**
 * The binding viewport contract (UI/UX plan §13.2, CLAUDE.md guardrail 1).
 *
 * These numbers exist so a TEST can assert Tailwind and the CSS agree with the plan. They are
 * NEVER used at runtime to choose a layout: responsive behaviour is CSS media queries only, and
 * JavaScript device detection is forbidden.
 */
export const BREAKPOINTS = Object.freeze({
  mobileMaxPx: ${bp.mobile_max_px},
  tabletMinPx: ${bp.tablet_min_px},
  tabletMaxPx: ${bp.tablet_max_px},
  desktopMinPx: ${bp.desktop_min_px},
});

/** CSS variable reference for a semantic colour token. */
export function semanticVar(token: SemanticColorToken): string {
  return \`var(--sv-\${token})\`;
}

/** CSS variable reference for a component token. */
export function componentVar(token: ComponentToken): string {
  return \`var(--sv-\${token})\`;
}
`;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

function writeArtifact(relPath, contents) {
  const absolute = join(ROOT, relPath);
  const existing = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;

  if (existing === contents) {
    return { path: relPath, changed: false, sha256: createHash('sha256').update(contents).digest('hex') };
  }
  if (!CHECK_ONLY) {
    mkdirSync(dirname(absolute), { recursive: true });
    writeFileSync(absolute, contents, 'utf8');
  }

  return { path: relPath, changed: true, sha256: createHash('sha256').update(contents).digest('hex') };
}

const problems = validate();
if (problems.length > 0) {
  console.error('Design tokens FAILED validation:\n');
  for (const problem of problems) {
    console.error(`  - ${problem}`);
  }
  process.exit(1);
}

const written = [writeArtifact(CSS_TARGET, buildCss()), writeArtifact(TS_TARGET, buildTs())];
const changed = written.filter((artifact) => artifact.changed);

if (CHECK_ONLY && changed.length > 0) {
  console.error('Design-token artifacts are STALE. Re-run: node scripts/generate-design-tokens.mjs\n');
  for (const artifact of changed) {
    console.error(`  - ${artifact.path}`);
  }
  process.exit(1);
}

console.log(
  `Design tokens OK — ${source.palette.length} palette, ${source.semantic.length} semantic (×2 themes), ` +
    `${source.component.length} component, ${source.typography.scale.length} type steps, ` +
    `${source.contrast_requirements.length} contrast requirements.`,
);
console.log(`  source ${SOURCE} sha256=${SOURCE_SHA256}`);
for (const artifact of written) {
  console.log(`  ${artifact.path} sha256=${artifact.sha256}`);
}
console.log(CHECK_ONLY ? 'All artifacts up to date.' : `${changed.length} artifact(s) written, ${written.length - changed.length} unchanged.`);
