#!/usr/bin/env node
/**
 * Phase UI-08 — historical browser-evidence protection.
 *
 * UI-01 … UI-07 committed browser evidence that a later phase must not rewrite, and the full
 * Playwright suite executes those phases' own capture specs — so any full run risks overwriting
 * them. This records a PER-FILE manifest (relative path, byte size, SHA-256) so a run can be proven
 * to have left them byte-identical, and so anything it did rewrite can be restored EXACTLY rather
 * than by reverting a whole directory.
 *
 * It only ever READS. Restoration is `git checkout --` of the exact enumerated paths, performed
 * deliberately by the caller — never `git clean`, never a directory-wide revert.
 *
 * Usage:
 *   node scripts/ui08-evidence-hash.mjs > before.json
 *   node scripts/ui08-evidence-hash.mjs --compare before.json
 *
 * `--compare` exits 0 when every set is byte-identical, and 1 otherwise, printing the exact
 * changed, added and removed paths.
 */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Every evidence set an earlier UI phase owns. UI-08's own evidence is deliberately absent. */
const SETS = [
  'docs/frontend/audits/ui-01',
  'docs/proof/ui-01',
  'docs/frontend/audits/ui-02',
  'docs/frontend/audits/ui-03',
  'docs/frontend/audits/ui-04',
  'docs/frontend/audits/ui-05',
  'docs/frontend/audits/ui-06',
  'docs/proof/ui-06',
  'docs/frontend/audits/ui-07',
  'docs/proof/ui-07.md',
];

/**
 * A GENERATED projection is not historical evidence, and must not be frozen.
 *
 * `docs/frontend/audits/ui-07/` holds two different kinds of file: browser evidence and
 * hand-written records, which a later phase must never rewrite — and projections that
 * `npm run nav:generate` derives from the router, the canonical map and the screen inventory,
 * which a later phase legitimately CHANGES when it changes the router. UI-08 does exactly that.
 *
 * Freezing the second kind would forbid the activation this phase exists to perform, and it would
 * also be a weaker guarantee than the one they already have: `nav:check` regenerates them and fails
 * if a single byte is stale. They are identified by the `generated_by` marker the generator writes,
 * so the rule is data-driven rather than a hard-coded list that would rot.
 */
function isGeneratedProjection(absolute) {
  if (!absolute.endsWith('.json')) return false;
  try {
    return typeof JSON.parse(readFileSync(absolute, 'utf8')).generated_by === 'string';
  } catch {
    return false;
  }
}

function walk(target, out = []) {
  if (!existsSync(target)) return out;
  if (statSync(target).isFile()) {
    out.push(target);
    return out;
  }
  for (const entry of readdirSync(target, { withFileTypes: true })) {
    const path = join(target, entry.name);
    if (entry.isDirectory()) walk(path, out);
    else out.push(path);
  }
  return out;
}

function fingerprint() {
  const files = {};
  const sets = {};
  let total = 0;

  for (const relative of SETS) {
    const found = walk(join(ROOT, relative)).sort();
    const aggregate = createHash('sha256');

    for (const absolute of found) {
      if (isGeneratedProjection(absolute)) continue;
      const key = absolute.replace(/\\/g, '/').slice(ROOT.length + 1);
      const bytes = readFileSync(absolute);
      const sha256 = createHash('sha256').update(bytes).digest('hex');
      // Path AND bytes: a renamed file must change the aggregate too.
      aggregate.update(key);
      aggregate.update(bytes);
      files[key] = { bytes: bytes.length, sha256 };
    }

    const generated = found.filter(isGeneratedProjection).length;
    sets[relative] = {
      files: found.length - generated,
      generated_projections_excluded: generated,
      aggregate_sha256: aggregate.digest('hex'),
    };
    total += found.length - generated;
  }

  return { total_files: total, sets, files };
}

const current = fingerprint();
const compareIndex = process.argv.indexOf('--compare');

if (compareIndex === -1) {
  process.stdout.write(`${JSON.stringify(current, null, 2)}\n`);
  process.exit(0);
}

const baseline = JSON.parse(readFileSync(process.argv[compareIndex + 1], 'utf8'));

const changed = [];
const removed = [];
const added = [];

for (const [path, entry] of Object.entries(baseline.files)) {
  const now = current.files[path];
  if (!now) removed.push(path);
  else if (now.sha256 !== entry.sha256) changed.push(path);
}
for (const path of Object.keys(current.files)) {
  if (!baseline.files[path]) added.push(path);
}

console.log(`baseline ${baseline.total_files} files · current ${current.total_files} files`);
for (const path of changed) console.log(`CHANGED  ${path}`);
for (const path of removed) console.log(`REMOVED  ${path}`);
for (const path of added) console.log(`ADDED    ${path}`);

if (changed.length === 0 && removed.length === 0 && added.length === 0) {
  console.log('historical evidence is byte-identical to the baseline');
  process.exit(0);
}

console.log(`\n${changed.length} changed, ${removed.length} removed, ${added.length} added`);
process.exit(1);
