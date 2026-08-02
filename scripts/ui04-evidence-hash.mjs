#!/usr/bin/env node
// Phase UI-04 — historical browser-evidence integrity check.
//
// UI-01, UI-02 and UI-03 committed browser evidence that later phases must not rewrite. The full
// Playwright suite executes UI-01's evidence-capture spec, so any run risks overwriting it.
//
// This records an aggregate SHA-256 per evidence set (file paths AND bytes, in sorted order) so
// the sets can be proven byte-identical before and after a browser run. It only ever READS.
//
// Usage:
//   node scripts/ui04-evidence-hash.mjs > before.json
//   node scripts/ui04-evidence-hash.mjs --compare before.json

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** The evidence sets earlier phases own. None of these may change in UI-04. */
const SETS = [
  'docs/frontend/audits/ui-01',
  'docs/proof/ui-01',
  'docs/frontend/audits/ui-02',
  'docs/frontend/audits/ui-03',
];

function walk(dir, out = []) {
  if (!existsSync(dir)) {
    return out;
  }
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) {
      walk(path, out);
      continue;
    }
    out.push(path);
  }

  return out;
}

function fingerprint() {
  const manifest = {};
  let total = 0;

  for (const relative of SETS) {
    const files = walk(join(ROOT, relative)).sort();
    const hash = createHash('sha256');
    for (const file of files) {
      // Path AND bytes: a renamed file must change the fingerprint too.
      hash.update(file.replace(/\\/g, '/').slice(ROOT.length));
      hash.update(readFileSync(file));
    }
    manifest[relative] = { files: files.length, aggregate_sha256: hash.digest('hex') };
    total += files.length;
  }
  manifest._total_files = total;

  return manifest;
}

const current = fingerprint();
const compareIndex = process.argv.indexOf('--compare');

if (compareIndex === -1) {
  console.log(JSON.stringify(current, null, 2));
  process.exit(0);
}

const baseline = JSON.parse(readFileSync(process.argv[compareIndex + 1], 'utf8'));
const drift = [];

for (const relative of SETS) {
  if (baseline[relative]?.aggregate_sha256 !== current[relative]?.aggregate_sha256) {
    drift.push(
      `${relative}: ${baseline[relative]?.files ?? 0} files ${baseline[relative]?.aggregate_sha256 ?? 'absent'}` +
        ` -> ${current[relative]?.files ?? 0} files ${current[relative]?.aggregate_sha256 ?? 'absent'}`,
    );
  }
}

if (drift.length > 0) {
  console.error('HISTORICAL EVIDENCE CHANGED — earlier phases own these files:\n');
  for (const line of drift) {
    console.error(`  - ${line}`);
  }
  process.exit(1);
}

console.log(`Historical evidence unchanged — ${current._total_files} files across ${SETS.length} sets.`);
for (const relative of SETS) {
  console.log(`  ${relative.padEnd(32)} ${current[relative].files} files  ${current[relative].aggregate_sha256}`);
}
