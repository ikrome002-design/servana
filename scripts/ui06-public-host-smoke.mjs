#!/usr/bin/env node
// Phase UI-06 — public-surface smoke against the BUILT production pair (nginx + PHP-FPM).
//
// The Playwright suite runs against a Vite preview origin with no Laravel behind it, so the account
// context it exercises is installed by the harness (UI01-PROV-003, owned by UI-16). This script
// closes the other half: it probes the real edge with genuine account `Host` headers, so the
// context is resolved by the SERVER exactly as it is in production, and the eight public surfaces
// are proven to be served rather than merely built.
//
// It only READS over HTTP and creates nothing.
//
// Usage: node scripts/ui06-public-host-smoke.mjs [--origin http://127.0.0.1:8080] [--out <path>]

import http from 'node:http';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function arg(name, fallback) {
  const index = process.argv.indexOf(name);

  return index === -1 ? fallback : process.argv[index + 1];
}

const ORIGIN = arg('--origin', 'http://127.0.0.1:8080');
const OUT = arg('--out', 'docs/frontend/audits/ui-06/production-host-proof.json');

const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const imageMatrix = JSON.parse(
  readFileSync(join(ROOT, 'docs/frontend/audits/ui-06/image-render-matrix.json'), 'utf8'),
);
const routeMatrix = JSON.parse(
  readFileSync(join(ROOT, 'docs/frontend/audits/ui-06/public-route-matrix.json'), 'utf8'),
);

const localHost = (account) =>
  account.subdomain === null ? registry.domains.local : `${account.subdomain}.${registry.domains.local}`;

const { hostname: originHost, port: originPort } = new URL(ORIGIN);

/**
 * Request a path with an explicit `Host` header.
 *
 * node:http rather than fetch, for the same reason UI-02, UI-04 and UI-05 use it: `Host` is a
 * forbidden header name for fetch/undici, which drops it silently — every probe would then be
 * answered by whichever server block owns the origin address, and the whole matrix would be
 * meaningless while appearing to run.
 */
function probe(host, path) {
  return new Promise((resolvePromise, reject) => {
    const request = http.request(
      { host: originHost, port: originPort || 80, path: encodeURI(path), method: 'GET', headers: { Host: host } },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () => {
          const contentType = response.headers['content-type'] ?? null;
          resolvePromise({
            status: response.statusCode ?? 0,
            contentType,
            cacheControl: response.headers['cache-control'] ?? null,
            body: Buffer.concat(chunks),
          });
        });
      },
    );
    request.on('error', reject);
    request.end();
  });
}

const failures = [];
const rows = [];

function check(condition, label, detail) {
  if (!condition) {
    failures.push(`${label}: ${detail}`);
  }

  return condition;
}

/** Every public path the plan requires on every host. */
const PUBLIC_PATHS = [
  '/', '/login', '/auth/magic-link/request', '/auth/magic-link/consume', '/faq',
  '/legal/data-policy', '/legal/privacy-policy', '/legal/terms-of-service',
];

for (const account of registry.accounts) {
  const host = localHost(account);
  const label = `${account.account_key} (${host})`;
  const paths = {};

  for (const path of PUBLIC_PATHS) {
    const response = await probe(host, path);
    const html = response.body.toString('utf8');

    // Every public path must be answered by the SPA shell, on this host, for this account.
    check(response.status === 200, label, `${path} status ${response.status}`);
    check(
      (response.contentType ?? '').includes('text/html'),
      label,
      `${path} content-type '${response.contentType}'`,
    );
    check(
      html.includes(`data-account-key="${account.account_key}"`),
      label,
      `${path} resolved a different account`,
    );
    // The shell must revalidate: it names fingerprinted chunks.
    check((response.cacheControl ?? '').includes('no-cache'), label, `${path} cache-control '${response.cacheControl}'`);

    paths[path] = { status: response.status, content_type: response.contentType };
  }

  // The merchant host additionally serves registration and setup; every other host must not LINK
  // them, which the browser proof asserts. Serving the SPA shell there is correct and harmless —
  // the API is the boundary — so this records rather than asserts.
  const registerShell = await probe(host, '/register');
  const setupShell = await probe(host, '/setup');

  // This account's own curated images must serve from the edge with the right MIME.
  const images = imageMatrix.accounts.find((entry) => entry.account_key === account.account_key);
  const imageResults = [];
  for (const image of images.images) {
    const original = await probe(host, image.source_public_path);
    check(original.status === 200, label, `${image.source_public_path} status ${original.status}`);
    check(
      (original.contentType ?? '').includes('image/'),
      label,
      `${image.source_public_path} content-type '${original.contentType}'`,
    );

    const derivatives = [];
    for (const path of image.derivative_paths) {
      const response = await probe(host, path);
      const expectedType = path.endsWith('.avif') ? 'image/avif' : 'image/webp';
      check(response.status === 200, label, `${path} status ${response.status}`);
      check(
        (response.contentType ?? '').includes(expectedType),
        label,
        `${path} content-type '${response.contentType}' (expected ${expectedType})`,
      );
      derivatives.push({ path, status: response.status, content_type: response.contentType });
    }

    imageResults.push({
      landing_section: image.landing_section,
      source: { path: image.source_public_path, status: original.status, content_type: original.contentType },
      derivatives,
    });
  }

  // The deleted vector logo and the quarantined brand working files stay unreachable.
  const legacyLogo = await probe(host, '/assets/brand/Logo.svg');
  check(legacyLogo.status === 404, label, `Logo.svg must stay absent, got ${legacyLogo.status}`);
  const quarantined = await probe(host, '/assets/brand/PNG.png');
  check(quarantined.status === 404, label, `a quarantined brand file is served, got ${quarantined.status}`);
  const logo = await probe(host, '/assets/brand/Logo.png');
  check(logo.status === 200, label, `Logo.png status ${logo.status}`);

  rows.push({
    account_key: account.account_key,
    host,
    public_paths: paths,
    register_shell_status: registerShell.status,
    setup_shell_status: setupShell.status,
    images: imageResults,
    logo_status: logo.status,
    legacy_logo_svg_status: legacyLogo.status,
    quarantined_brand_file_status: quarantined.status,
  });
}

// An unapproved host must not reach a shell that implies an account context.
const denials = [];
for (const host of ['attacker.test', 'unknown.servana.test']) {
  let status = null;
  let refused = false;
  let body = null;

  try {
    const response = await probe(host, '/faq');
    status = response.status;
    body = response.body.toString('utf8');
  } catch {
    refused = true; // nginx `return 444` closes the connection — that IS the denial.
  }

  check(refused || status === 421, `unknown host ${host}`, `expected a close or 421, got ${status}`);
  check(!/data-account-key/.test(body ?? ''), `unknown host ${host}`, 'the denial implied an account context');
  denials.push({ host, connection_closed: refused, status });
}

// No service worker is registered anywhere, and none is claimed.
const serviceWorkers = [];
for (const path of ['/sw.js', '/service-worker.js']) {
  const response = await probe(localHost(registry.accounts[0]), path);
  check(!/javascript/.test(response.contentType ?? ''), 'service worker', `${path} served ${response.contentType}`);
  serviceWorkers.push({ path, status: response.status, content_type: response.contentType });
}

const report = {
  generated_by: 'scripts/ui06-public-host-smoke.mjs',
  phase: 'UI-06',
  origin: ORIGIN,
  purpose:
    'The eight public surfaces, probed over HTTP against the BUILT production images with genuine account Host headers, so the account context is resolved by the server exactly as it is in production.',
  required_public_paths: PUBLIC_PATHS,
  route_matrix_sha_reference: routeMatrix.authorities.account_host_registry.sha256,
  accounts: rows,
  unknown_host_denials: denials,
  service_worker_probes: serviceWorkers,
  passed: failures.length === 0,
  failures,
};

mkdirSync(dirname(join(ROOT, OUT)), { recursive: true });
writeFileSync(join(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

if (failures.length > 0) {
  console.error(`UI-06 public host smoke FAILED (${failures.length}):\n`);
  for (const failure of failures) {
    console.error(`  - ${failure}`);
  }
  console.error(`\nReport: ${OUT}`);
  process.exit(1);
}

console.log(`UI-06 public host smoke OK — ${rows.length} accounts × ${PUBLIC_PATHS.length} public paths, ${denials.length} denials.`);
for (const row of rows) {
  console.log(`  ${row.host.padEnd(26)} landing ${row.public_paths['/'].status}  faq ${row.public_paths['/faq'].status}  legal ${row.public_paths['/legal/data-policy'].status}  images ${row.images.length}  Logo.svg ${row.legacy_logo_svg_status}`);
}
console.log(`Report: ${OUT}`);
