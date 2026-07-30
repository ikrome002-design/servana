#!/usr/bin/env node
// Phase UI-02 — account-host smoke matrix.
//
// Probes a RUNNING Servana edge with explicit Host headers and asserts the deployed serving
// contract that unit and feature tests cannot reach: what Nginx actually returns, with which
// status, content type and cache policy.
//
// This is deliberately NOT the release-wide browser gate. UI01-PROV-003 (the Playwright
// suite runs against a Vite preview origin rather than the deployed one) stays open and is
// owned by UI-16. This script proves the four host/serving defects UI-02 owns.
//
// Usage:
//   node scripts/ui02-host-smoke.mjs [--origin http://127.0.0.1:8080] [--out <path>]
//
// Exits non-zero on the first failed assertion so CI cannot pass a broken serving model.

import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import http from 'node:http';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function arg(name, fallback) {
  const index = process.argv.indexOf(name);

  return index === -1 ? fallback : process.argv[index + 1];
}

const ORIGIN = arg('--origin', 'http://127.0.0.1:8080');
const OUT = arg('--out', 'docs/frontend/audits/ui-02/host-matrix.json');

const source = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const manifest = JSON.parse(readFileSync(join(ROOT, 'public/spa/.vite/manifest.json'), 'utf8'));
const entry = manifest['index.html'];

const localHost = (account) =>
  account.subdomain === null ? source.domains.local : `${account.subdomain}.${source.domains.local}`;

const results = [];
const failures = [];

function check(condition, label, detail) {
  if (!condition) {
    failures.push(`${label}: ${detail}`);
  }

  return condition;
}

const { hostname: originHost, port: originPort } = new URL(ORIGIN);

/**
 * Request a path with an explicit `Host` header.
 *
 * Uses node:http rather than fetch on purpose: `Host` is a forbidden header name for
 * fetch/undici, which drops it silently — every probe would then be answered by whichever
 * server block owns the origin address, and the whole matrix would be meaningless while
 * appearing to run. Redirects are never followed, so a 301 is observable.
 */
function probe(host, path) {
  return new Promise((resolvePromise, reject) => {
    const request = http.request(
      {
        host: originHost,
        port: originPort || 80,
        path,
        method: 'GET',
        headers: { Host: host },
      },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () => {
          const contentType = response.headers['content-type'] ?? null;
          resolvePromise({
            status: response.statusCode,
            contentType,
            cacheControl: response.headers['cache-control'] ?? null,
            location: response.headers['location'] ?? null,
            body: contentType?.includes('text/html') ? Buffer.concat(chunks).toString('utf8') : null,
          });
        });
      },
    );

    request.on('error', reject);
    request.end();
  });
}

for (const account of source.accounts) {
  const host = localHost(account);
  const label = `${account.account_key} (${host})`;

  const root = await probe(host, '/');
  const js = await probe(host, `/${entry.file}`);
  const css = await probe(host, `/${entry.css[0]}`);
  const favicon = await probe(host, '/assets/brand/favicon.ico');
  const logo = await probe(host, '/assets/brand/Logo.png');
  const legacyLogo = await probe(host, '/assets/brand/Logo.svg');
  const spaCompat = await probe(host, '/spa/');
  const hostHealth = await probe(host, '/health/host');

  const accountKey = root.body?.match(/data-account-key="([^"]+)"/)?.[1] ?? null;

  // UI01-PROV-001 — the deployed root must be Servana, never the Laravel scaffold.
  check(root.status === 200, label, `root status ${root.status}`);
  check(!/<title>Laravel<\/title>/.test(root.body ?? ''), label, 'root served the Laravel scaffold');
  check(/<title>Servana by Citrus<\/title>/.test(root.body ?? ''), label, 'root is not the Servana shell');
  check(!/laravel\.com\/assets/.test(root.body ?? ''), label, 'root references laravel.com assets');
  check(accountKey === account.account_key, label, `root resolved account '${accountKey}'`);
  check(
    (root.cacheControl ?? '').includes('no-cache'),
    label,
    `shell cache-control '${root.cacheControl}' must revalidate`,
  );

  // UI01-PROV-002 — the chunks the shell names must actually load, with the right MIME.
  check(js.status === 200, label, `entry js status ${js.status}`);
  check(
    (js.contentType ?? '').includes('javascript'),
    label,
    `entry js content-type '${js.contentType}'`,
  );
  check(
    (js.cacheControl ?? '').includes('immutable'),
    label,
    `fingerprinted js must be immutable, got '${js.cacheControl}'`,
  );
  check(css.status === 200, label, `entry css status ${css.status}`);
  check((css.contentType ?? '').includes('text/css'), label, `entry css content-type '${css.contentType}'`);

  // UI01-ASSET-005 — approved brand assets resolve at stable, canonical-case URLs.
  check(favicon.status === 200, label, `favicon.ico status ${favicon.status}`);
  check(logo.status === 200, label, `Logo.png status ${logo.status}`);
  check(legacyLogo.status === 404, label, `Logo.svg must stay absent, got ${legacyLogo.status}`);

  // The documented /spa/ compatibility behaviour: one permanent redirect to the account's
  // own root. nginx expands `return 301 /` into an absolute URL preserving the request host,
  // so assert the destination rather than the literal header — and assert it stays on the
  // SAME host, since a redirect that crossed accounts would be a serious defect.
  check(spaCompat.status === 301, label, `/spa/ status ${spaCompat.status}`);
  const redirectTarget = new URL(spaCompat.location ?? '/', `http://${host}`);
  check(redirectTarget.pathname === '/', label, `/spa/ redirected to '${spaCompat.location}'`);
  check(
    redirectTarget.hostname === host,
    label,
    `/spa/ redirected off-host to '${redirectTarget.hostname}'`,
  );

  // UI01-HOST-001 — the edge agrees with the application about which account this is.
  check(hostHealth.status === 200, label, `/health/host status ${hostHealth.status}`);

  results.push({
    account_key: account.account_key,
    host,
    root_status: root.status,
    resolved_account_key: accountKey,
    shell_cache_control: root.cacheControl,
    entry_js: { path: `/${entry.file}`, status: js.status, content_type: js.contentType, cache_control: js.cacheControl },
    entry_css: { path: `/${entry.css[0]}`, status: css.status, content_type: css.contentType },
    favicon_status: favicon.status,
    logo_status: logo.status,
    legacy_logo_svg_status: legacyLogo.status,
    spa_compat: { status: spaCompat.status, location: spaCompat.location },
    host_health_status: hostHealth.status,
  });
}

// Unknown and deceptive hosts must not reach a shell that implies an account context.
const denials = [];
for (const host of ['attacker.test', 'evil-servana.ke', 'servana.ke.attacker.test', 'unknown.servana.test']) {
  let status = null;
  let refused = false;
  let body = null;

  try {
    const response = await probe(host, '/');
    status = response.status;
    body = response.body;
  } catch {
    // nginx `return 444` closes the connection without a response — that IS the denial.
    refused = true;
  }

  check(refused || status === 421, `unknown host ${host}`, `expected connection close or 421, got ${status}`);
  check(!/data-account-key/.test(body ?? ''), `unknown host ${host}`, 'denial implied an account context');

  denials.push({ host, connection_closed: refused, status });
}

// The machine host serves probes only — never the application.
const machineHealth = await probe('localhost', '/health');
const machineRoot = await probe('localhost', '/');
check(machineHealth.status === 200, 'machine host', `/health status ${machineHealth.status}`);
check(machineRoot.status === 404, 'machine host', `/ must not serve the app, got ${machineRoot.status}`);

const report = {
  generated_by: 'scripts/ui02-host-smoke.mjs',
  origin: ORIGIN,
  vite_entry: { js: `/${entry.file}`, css: entry.css.map((c) => `/${c}`) },
  accounts: results,
  unknown_host_denials: denials,
  machine_host: { health_status: machineHealth.status, root_status: machineRoot.status },
  passed: failures.length === 0,
  failures,
};

mkdirSync(dirname(join(ROOT, OUT)), { recursive: true });
writeFileSync(join(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (failures.length > 0) {
  console.error(`Host smoke FAILED (${failures.length}):\n`);
  for (const failure of failures) {
    console.error(`  - ${failure}`);
  }
  console.error(`\nReport: ${OUT}`);
  process.exit(1);
}

console.log(`Host smoke OK — ${results.length} accounts, ${denials.length} denials, origin ${ORIGIN}.`);
for (const row of results) {
  console.log(`  ${row.host.padEnd(26)} root ${row.root_status}  js ${row.entry_js.status}  css ${row.entry_css.status}  favicon ${row.favicon_status}  -> ${row.resolved_account_key}`);
}
console.log(`Report: ${OUT}`);
