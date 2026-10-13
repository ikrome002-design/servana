#!/usr/bin/env node
// Phase UI-17 — frontend bundle analysis and compressed-budget gate (UI/UX plan §6.5, §22.1, §22.4).
//
// Reads the BUILT output (public/spa/.vite/manifest.json + .vite/chunk-modules.json + the emitted
// files) and proves, deterministically and without a browser:
//
//   1. Every route component is lazy: no page or layout module is reachable from the entry chunk
//      through a STATIC import (§6.5 "Do not import 160 pages into the initial bundle").
//   2. No account shell ships another account's code: the static closure of each account's layout
//      + dashboard contains no module owned by a different account (§22.1 "No all-role page bundle
//      in the initial request", §22.4).
//   3. The initial request and every account-shell critical path stay inside the reviewed
//      compressed budgets in config/frontend-performance-budget.json.
//   4. No single lazy chunk exceeds its reviewed cap (a regression that merges pages shows here).
//
// Sizes are measured on the bytes actually emitted: gzip at the edge's configured level (the wire
// format nginx serves) and brotli q11 (informational — the stock edge has no brotli module).
//
// Usage:
//   node scripts/ui17-bundle-budget.mjs [--out <report.json>] [--build-dir <dir>] [--budget <file>]
// `--build-dir` lets the negative controls point the gate at a mutated copy of a build.

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { brotliCompressSync, constants, gzipSync } from 'node:zlib';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function arg(name, fallback) {
  const index = process.argv.indexOf(name);

  return index === -1 ? fallback : process.argv[index + 1];
}

const BUILD_DIR = resolve(ROOT, arg('--build-dir', 'public/spa'));
const BUDGET_FILE = resolve(ROOT, arg('--budget', 'config/frontend-performance-budget.json'));
const OUT = arg('--out', null);

const failures = [];
const fail = (message) => failures.push(message);

function readJson(path, label) {
  if (!existsSync(path)) {
    console.error(`UI-17 bundle budget: ${label} is missing at ${path}. Run \`npm run build\` first.`);
    process.exit(2);
  }
  try {
    return JSON.parse(readFileSync(path, 'utf8'));
  } catch (error) {
    console.error(`UI-17 bundle budget: ${label} is not valid JSON (${error.message}).`);
    process.exit(2);
  }
}

const budget = readJson(BUDGET_FILE, 'budget file');
const manifest = readJson(join(BUILD_DIR, '.vite/manifest.json'), 'Vite manifest');
const chunkMap = readJson(join(BUILD_DIR, '.vite/chunk-modules.json'), 'chunk-module map');

if (!manifest['index.html']?.file) {
  console.error('UI-17 bundle budget: the manifest has no "index.html" entry.');
  process.exit(2);
}

const GZIP_LEVEL = budget.method.gzip_level;
const sizeCache = new Map();

/** Raw, gzip (edge level) and brotli (q11) sizes of one emitted file. */
function sizes(file) {
  if (sizeCache.has(file)) return sizeCache.get(file);
  const path = join(BUILD_DIR, file);
  if (!existsSync(path)) {
    fail(`emitted file ${file} named by the manifest does not exist`);
    const empty = { raw: 0, gzip: 0, brotli: 0 };
    sizeCache.set(file, empty);
    return empty;
  }
  const bytes = readFileSync(path);
  const result = {
    raw: bytes.length,
    gzip: gzipSync(bytes, { level: GZIP_LEVEL }).length,
    brotli: brotliCompressSync(bytes, { params: { [constants.BROTLI_PARAM_QUALITY]: 11 } }).length,
  };
  sizeCache.set(file, result);
  return result;
}

/**
 * The manifest key of the chunk that carries source module `src` (e.g. `src/pages/x/Y.vue`): its own
 * key when it is a dynamic entry, otherwise the key of the shared chunk it was bundled into.
 */
function keyFor(src) {
  if (manifest[src]) return src;
  const chunk = chunkMap.chunks.find((candidate) => candidate.modules.includes(`resources/spa/${src}`));
  if (!chunk) return null;
  return Object.keys(manifest).find((key) => manifest[key].file === chunk.file) ?? null;
}

/** Static-import closure of manifest keys (imports only — dynamic imports are lazy by definition). */
function staticClosure(keys) {
  const seen = new Set();
  const stack = [...keys];
  while (stack.length > 0) {
    const key = stack.pop();
    if (seen.has(key)) continue;
    const entry = manifest[key];
    if (!entry) {
      fail(`manifest key ${key} is referenced but absent`);
      continue;
    }
    seen.add(key);
    for (const next of entry.imports ?? []) stack.push(next);
  }
  return seen;
}

function measure(keys) {
  const closure = staticClosure(keys);
  const js = new Set();
  const css = new Set();
  for (const key of closure) {
    const entry = manifest[key];
    if (entry.file.endsWith('.js')) js.add(entry.file);
    for (const sheet of entry.css ?? []) css.add(sheet);
  }
  const total = (files) =>
    [...files].reduce(
      (sum, file) => {
        const s = sizes(file);
        return { raw: sum.raw + s.raw, gzip: sum.gzip + s.gzip, brotli: sum.brotli + s.brotli };
      },
      { raw: 0, gzip: 0, brotli: 0 },
    );
  return {
    closure,
    js_files: [...js].sort(),
    css_files: [...css].sort(),
    js: total(js),
    css: total(css),
    requests: js.size + css.size,
  };
}

/** Repo-relative source modules bundled into the chunks of a closure. */
const modulesByFile = new Map(chunkMap.chunks.map((chunk) => [chunk.file, chunk.modules]));
function modulesIn(closure) {
  const modules = new Set();
  for (const key of closure) {
    for (const id of modulesByFile.get(manifest[key].file) ?? []) modules.add(id);
  }
  return modules;
}

const owners = budget.account_ownership;
function ownerOf(moduleId) {
  for (const [account, prefixes] of Object.entries(owners)) {
    if (prefixes.some((prefix) => moduleId.startsWith(prefix))) return account;
  }
  return null;
}

// ---------------------------------------------------------------------------------------------
// 1. Lazy route components
// ---------------------------------------------------------------------------------------------
const entryKey = 'index.html';
const entryClosure = staticClosure([entryKey]);
const entryModules = modulesIn(entryClosure);
const eagerPages = [...entryModules].filter((id) => budget.lazy_only_prefixes.some((prefix) => id.startsWith(prefix)));
for (const id of eagerPages) fail(`route-level module ${id} is statically bundled into the initial request`);

const routeComponents = new Set();
for (const routeFile of budget.route_files) {
  const source = readFileSync(join(ROOT, routeFile), 'utf8');
  for (const match of source.matchAll(/import\(\s*'@\/((?:pages|layouts)\/[^']+\.vue)'\s*\)/g)) {
    routeComponents.add(`src/${match[1]}`);
  }
}
// A route component is lazy when the chunk carrying it is NOT part of the initial request. It need
// not be its own chunk: a component that is also imported statically by sibling pages (the shared
// Account & Security panel, the shared Get Started page) is placed by Rolldown in a shared on-demand
// chunk, which the dynamic import then loads. Only a module that lands in the entry closure is eager.
const initialFiles = new Set([...entryClosure].map((key) => manifest[key].file));
const chunkOfModule = new Map();
for (const chunk of chunkMap.chunks) {
  for (const id of chunk.modules) if (!chunkOfModule.has(id)) chunkOfModule.set(id, chunk.file);
}
const notLazy = [];
for (const src of routeComponents) {
  const file = chunkOfModule.get(`resources/spa/${src}`);
  if (file === undefined) {
    notLazy.push(src);
    fail(`route component ${src} is not present in any emitted chunk`);
  } else if (initialFiles.has(file)) {
    notLazy.push(src);
    fail(`route component ${src} is bundled into the initial request (${file})`);
  }
}

// ---------------------------------------------------------------------------------------------
// 2 + 3. Initial request and per-account critical paths
// ---------------------------------------------------------------------------------------------
const initial = measure([entryKey]);
const initialBudget = budget.budgets.initial_request;
if (initial.js.gzip > initialBudget.max_js_gzip_bytes) {
  fail(`initial request JS ${initial.js.gzip} B gzip exceeds the ${initialBudget.max_js_gzip_bytes} B budget`);
}
if (initial.css.gzip > initialBudget.max_css_gzip_bytes) {
  fail(`initial request CSS ${initial.css.gzip} B gzip exceeds the ${initialBudget.max_css_gzip_bytes} B budget`);
}

const archetypes = [];
const archetypeBudget = budget.budgets.critical_path;
for (const archetype of budget.archetypes) {
  const keys = [entryKey];
  for (const src of archetype.modules) {
    const key = keyFor(src);
    if (!key) {
      fail(`${archetype.id}: module ${src} is not in any emitted chunk (renamed or removed)`);
      continue;
    }
    keys.push(key);
  }
  const measured = measure(keys);
  const foreign = [];
  if (archetype.account_key) {
    for (const id of modulesIn(measured.closure)) {
      const owner = ownerOf(id);
      if (owner !== null && owner !== archetype.account_key && !(archetype.shared_with ?? []).includes(owner)) {
        foreign.push({ module: id, owner });
      }
    }
  }
  for (const item of foreign) fail(`${archetype.id}: critical path bundles ${item.owner} module ${item.module}`);
  if (measured.js.gzip > archetypeBudget.max_js_gzip_bytes) {
    fail(`${archetype.id}: critical-path JS ${measured.js.gzip} B gzip exceeds ${archetypeBudget.max_js_gzip_bytes} B`);
  }
  if (measured.css.gzip > archetypeBudget.max_css_gzip_bytes) {
    fail(`${archetype.id}: critical-path CSS ${measured.css.gzip} B gzip exceeds ${archetypeBudget.max_css_gzip_bytes} B`);
  }
  if (measured.requests > archetypeBudget.max_requests) {
    fail(`${archetype.id}: ${measured.requests} critical-path JS/CSS requests exceed ${archetypeBudget.max_requests}`);
  }
  archetypes.push({
    id: archetype.id,
    kind: archetype.kind,
    account_key: archetype.account_key ?? null,
    modules: archetype.modules,
    js: measured.js,
    css: measured.css,
    requests: measured.requests,
    foreign_account_modules: foreign,
  });
}

// ---------------------------------------------------------------------------------------------
// 4. Largest lazy chunks
// ---------------------------------------------------------------------------------------------
const lazy = Object.entries(manifest)
  .filter(([, entry]) => entry.isDynamicEntry && entry.file.endsWith('.js'))
  .map(([key, entry]) => ({ key, file: entry.file, ...sizes(entry.file) }));
const chunkCaps = budget.budgets.lazy_chunk;
for (const chunk of lazy) {
  const isContent = chunkCaps.content_prefixes.some((prefix) => chunk.key.startsWith(prefix));
  const cap = isContent ? chunkCaps.max_content_gzip_bytes : chunkCaps.max_route_gzip_bytes;
  if (chunk.gzip > cap) fail(`lazy chunk ${chunk.key} is ${chunk.gzip} B gzip, above its ${cap} B cap`);
}
const largest = [...lazy].sort((a, b) => b.gzip - a.gzip).slice(0, 15);

// ---------------------------------------------------------------------------------------------
// 5. No inlined fonts — the edge CSP allows `font-src 'self'` only, so a `data:` font in the CSS is
//    refused by the browser and its glyph range silently falls back (found by the UI-17 origin gate).
// ---------------------------------------------------------------------------------------------
const cssFiles = [...new Set(Object.values(manifest).flatMap((entry) => [...(entry.css ?? []), ...(entry.file.endsWith('.css') ? [entry.file] : [])]))];
const inlinedFonts = [];
for (const file of cssFiles) {
  const path = join(BUILD_DIR, file);
  if (!existsSync(path)) continue;
  const count = (readFileSync(path, 'utf8').match(/url\(["']?data:(?:font\/|application\/(?:x-)?font)/g) ?? []).length;
  if (count > 0) {
    inlinedFonts.push({ file, count });
    fail(`${file} inlines ${count} font(s) as data: URIs, which the edge CSP (font-src 'self') refuses`);
  }
}

const allJs = Object.values(manifest).filter((entry) => entry.file.endsWith('.js'));
const report = {
  generated_by: 'scripts/ui17-bundle-budget.mjs',
  budget_file: 'config/frontend-performance-budget.json',
  method: budget.method,
  manifest_entries: Object.keys(manifest).length,
  js_chunks: allJs.length,
  lazy_route_components: { declared: routeComponents.size, not_lazy: notLazy },
  initial_request: {
    js_files: initial.js_files,
    css_files: initial.css_files,
    js: initial.js,
    css: initial.css,
    requests: initial.requests,
    eager_route_modules: eagerPages,
    budget: initialBudget,
  },
  archetypes,
  largest_lazy_chunks: largest,
  css_files_checked_for_inlined_fonts: cssFiles.length,
  inlined_fonts: inlinedFonts,
  passed: failures.length === 0,
  failures,
};

if (OUT) {
  mkdirSync(dirname(resolve(ROOT, OUT)), { recursive: true });
  writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
}

const kb = (bytes) => `${(bytes / 1024).toFixed(1)} KiB`;
if (failures.length > 0) {
  console.error(`UI-17 bundle budget FAILED (${failures.length}):`);
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}
console.log(
  `UI-17 bundle budget OK — initial ${kb(initial.js.gzip)} JS + ${kb(initial.css.gzip)} CSS gzip; ` +
    `${routeComponents.size} route components lazy; ${archetypes.length} archetypes in budget, no cross-account code.`,
);
for (const row of archetypes) {
  console.log(`  ${row.id.padEnd(30)} JS ${kb(row.js.gzip).padStart(10)}  CSS ${kb(row.css.gzip).padStart(9)}  requests ${row.requests}`);
}
