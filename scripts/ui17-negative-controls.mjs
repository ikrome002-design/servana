#!/usr/bin/env node
// Phase UI-17 — negative controls for the bundle-budget gate and the edge-security contract.
//
// A gate is only worth anything if it fails when it should. Each control below copies the real
// build (or the real edge files) into an OS temporary directory, breaks exactly ONE thing, and
// requires the gate to exit non-zero with a message naming that problem. Nothing touches the
// working tree, so no mutation can be committed. A control that stops failing is itself a defect.
//
// Prerequisite: a current `npm run build` (public/spa with .vite/manifest.json + chunk-modules.json).
// Usage: node scripts/ui17-negative-controls.mjs [--json]

import { spawnSync } from 'node:child_process';
import { appendFileSync, cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { randomBytes } from 'node:crypto';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const AS_JSON = process.argv.includes('--json');

function buildSandbox() {
  const dir = mkdtempSync(join(tmpdir(), 'servana-ui17-nc-'));
  cpSync(join(ROOT, 'public/spa/.vite'), join(dir, '.vite'), { recursive: true });
  cpSync(join(ROOT, 'public/spa/spa-assets'), join(dir, 'spa-assets'), { recursive: true });
  return dir;
}

function edgeSandbox() {
  const dir = mkdtempSync(join(tmpdir(), 'servana-ui17-edge-nc-'));
  for (const path of ['resources/views/spa.blade.php', 'resources/spa/index.html', 'docker/nginx/default.conf']) {
    mkdirSync(dirname(join(dir, path)), { recursive: true });
    cpSync(join(ROOT, path), join(dir, path));
  }
  return dir;
}

const read = (dir, path) => JSON.parse(readFileSync(join(dir, path), 'utf8'));
const write = (dir, path, value) => writeFileSync(join(dir, path), typeof value === 'string' ? value : JSON.stringify(value));

function runBudget(dir) {
  return spawnSync(process.execPath, [join(ROOT, 'scripts/ui17-bundle-budget.mjs'), '--build-dir', dir], { cwd: ROOT, encoding: 'utf8' });
}
function runEdge(dir) {
  return spawnSync(process.execPath, [join(ROOT, 'scripts/ui17-edge-security-contract.mjs'), '--root', dir], { cwd: ROOT, encoding: 'utf8' });
}

const BUILD_CONTROLS = [
  {
    id: 'oversized-initial-request',
    expect: /initial request JS \d+ B gzip exceeds/,
    mutate(dir) {
      // Incompressible bytes appended to the entry chunk: the wire size really grows.
      const entry = read(dir, '.vite/manifest.json')['index.html'].file;
      appendFileSync(join(dir, entry), `\n/*${randomBytes(200_000).toString('base64')}*/\n`);
    },
  },
  {
    id: 'route-page-made-eager',
    expect: /bundled into the initial request/,
    mutate(dir) {
      // Simulate a static import of a page: its module lands in the entry chunk.
      const map = read(dir, '.vite/chunk-modules.json');
      const entry = map.chunks.find((chunk) => chunk.is_entry);
      entry.modules.push('resources/spa/src/pages/finance/FinanceDashboard.vue');
      map.chunks = map.chunks.map((chunk) =>
        chunk === entry ? chunk : { ...chunk, modules: chunk.modules.filter((id) => id !== 'resources/spa/src/pages/finance/FinanceDashboard.vue') },
      );
      // Order matters for the gate's module→chunk lookup: put the entry first.
      map.chunks.sort((a, b) => Number(b.is_entry) - Number(a.is_entry));
      write(dir, '.vite/chunk-modules.json', map);
    },
  },
  {
    id: 'cross-account-module-in-shell',
    expect: /shell\.merchant_finance: critical path bundles merchant_personnel module/,
    mutate(dir) {
      const manifest = read(dir, '.vite/manifest.json');
      const file = manifest['src/pages/finance/FinanceDashboard.vue'].file;
      const map = read(dir, '.vite/chunk-modules.json');
      map.chunks.find((chunk) => chunk.file === file).modules.push('resources/spa/src/pages/personnel/PersonnelDashboard.vue');
      write(dir, '.vite/chunk-modules.json', map);
    },
  },
  {
    id: 'oversized-lazy-route-chunk',
    expect: /lazy chunk src\/pages\/hr\/Compensation\.vue is \d+ B gzip, above/,
    mutate(dir) {
      const file = read(dir, '.vite/manifest.json')['src/pages/hr/Compensation.vue'].file;
      appendFileSync(join(dir, file), `\n/*${randomBytes(60_000).toString('base64')}*/\n`);
    },
  },
  {
    id: 'archetype-module-renamed',
    expect: /shell\.merchant_audit: module src\/pages\/audit\/AuditDashboard\.vue is not in any emitted chunk/,
    mutate(dir) {
      const manifest = read(dir, '.vite/manifest.json');
      delete manifest['src/pages/audit/AuditDashboard.vue'];
      write(dir, '.vite/manifest.json', manifest);
      const map = read(dir, '.vite/chunk-modules.json');
      for (const chunk of map.chunks) chunk.modules = chunk.modules.filter((id) => !id.endsWith('pages/audit/AuditDashboard.vue'));
      write(dir, '.vite/chunk-modules.json', map);
    },
  },
  {
    id: 'font-inlined-as-data-uri',
    expect: /inlines 1 font\(s\) as data: URIs/,
    mutate(dir) {
      const css = read(dir, '.vite/manifest.json')['index.html'].css[0];
      appendFileSync(join(dir, css), '\n@font-face{font-family:x;src:url(data:font/woff2;base64,AAAA)}\n');
    },
  },
  {
    id: 'invalid-manifest',
    expect: /Vite manifest is not valid JSON/,
    exitCode: 2,
    mutate(dir) {
      write(dir, '.vite/manifest.json', '{ "index.html": ');
    },
  },
  {
    id: 'missing-chunk-module-map',
    expect: /chunk-module map is missing/,
    exitCode: 2,
    mutate(dir) {
      rmSync(join(dir, '.vite/chunk-modules.json'));
    },
  },
];

const EDGE_CONTROLS = [
  {
    id: 'theme-script-edited-without-csp-update',
    expect: /script-src does not allow the theme bootstrap hash/,
    mutate(dir) {
      for (const path of ['resources/views/spa.blade.php', 'resources/spa/index.html']) {
        const file = join(dir, path);
        writeFileSync(file, readFileSync(file, 'utf8').replace("svRoot.classList.add('dark');", "svRoot.classList.add('dark'); /* edited */"));
      }
    },
  },
  {
    id: 'unsafe-inline-script',
    expect: /script-src grants 'unsafe-inline'/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      writeFileSync(file, readFileSync(file, 'utf8').replace("script-src 'self'", "script-src 'self' 'unsafe-inline'"));
    },
  },
  {
    id: 'data-fonts-allowed',
    expect: /font-src must be 'self'/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      writeFileSync(file, readFileSync(file, 'utf8').replace("font-src 'self';", "font-src 'self' data:;"));
    },
  },
  {
    id: 'gzip-lost-behind-http10-proxy',
    expect: /gzip_http_version must be 1\.0/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      writeFileSync(file, readFileSync(file, 'utf8').replace('gzip_http_version 1.0;', ''));
    },
  },
  {
    id: 'request-line-logged-again',
    expect: /servana_edge log format records \$request/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      writeFileSync(file, readFileSync(file, 'utf8').replace('"path":"$servana_log_path"', '"path":"$request"'));
    },
  },
  {
    id: 'stock-log-format-on-a-server',
    expect: /server block 3 does not use the servana_edge log format/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      const text = readFileSync(file, 'utf8');
      const last = text.lastIndexOf('access_log /var/log/nginx/access.log servana_edge;');
      writeFileSync(file, `${text.slice(0, last)}access_log /var/log/nginx/access.log main;${text.slice(last + 'access_log /var/log/nginx/access.log servana_edge;'.length)}`);
    },
  },
  {
    id: 'literal-referrer-policy-appended',
    expect: /edge Referrer-Policy must defer/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      writeFileSync(file, readFileSync(file, 'utf8').replace('add_header Referrer-Policy $servana_referrer_policy always;', 'add_header Referrer-Policy "strict-origin-when-cross-origin" always;'));
    },
  },
  {
    id: 'gzip-at-http-level',
    expect: /gzip must not be enabled at the http level/,
    mutate(dir) {
      const file = join(dir, 'docker/nginx/default.conf');
      writeFileSync(file, readFileSync(file, 'utf8').replace('gzip_comp_level 6;', 'gzip on;\ngzip_comp_level 6;'));
    },
  },
];

const results = [];
function execute(controls, sandbox, run) {
  for (const control of controls) {
    const dir = sandbox();
    try {
      control.mutate(dir);
      const outcome = run(dir);
      const output = `${outcome.stdout}\n${outcome.stderr}`;
      const failedAsExpected = outcome.status === (control.exitCode ?? 1) && control.expect.test(output);
      results.push({ id: control.id, exit: outcome.status, fired: failedAsExpected });
      if (!failedAsExpected) results.at(-1).output = output.trim().split('\n').slice(0, 6);
    } finally {
      rmSync(dir, { recursive: true, force: true });
    }
  }
}

// The unmutated baselines must PASS, otherwise every control would "fire" for the wrong reason.
for (const [label, sandbox, run] of [['build', buildSandbox, runBudget], ['edge', edgeSandbox, runEdge]]) {
  const dir = sandbox();
  try {
    const outcome = run(dir);
    results.push({ id: `baseline-${label}-passes`, exit: outcome.status, fired: outcome.status === 0 });
    if (outcome.status !== 0) results.at(-1).output = `${outcome.stdout}\n${outcome.stderr}`.trim().split('\n').slice(0, 6);
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
}
execute(BUILD_CONTROLS, buildSandbox, runBudget);
execute(EDGE_CONTROLS, edgeSandbox, runEdge);

const failed = results.filter((result) => !result.fired);
if (AS_JSON) console.log(JSON.stringify({ generated_by: 'scripts/ui17-negative-controls.mjs', results, passed: failed.length === 0 }, null, 2));
else {
  for (const result of results) console.log(`${result.fired ? 'ok  ' : 'FAIL'} ${result.id} (exit ${result.exit})`);
  for (const result of failed) console.error(`\n${result.id}:\n  ${(result.output ?? []).join('\n  ')}`);
  console.log(`\n${results.length - failed.length}/${results.length} controls behaved as required.`);
}
process.exit(failed.length === 0 ? 0 : 1);
