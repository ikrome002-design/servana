#!/usr/bin/env node
/**
 * Phase UI-08 Increment 11 — canonical-host proof against the FINAL production images.
 *
 * Usage: node scripts/ui08-production-host-proof.mjs [origin]
 *
 * Probes the disposable production pair (`servana-ui08-php:audit` behind
 * `servana-ui08-nginx:audit`) over the account hosts the edge actually allowlists.
 *
 * `node:http` rather than `fetch`, for the reason UI-02, UI-04 and UI-05 all record: `Host` is a
 * FORBIDDEN header name for fetch, which drops it silently — every request would then arrive as
 * `localhost` and the account-host boundary would be exercised against the wrong value.
 *
 * What this proves is what the EDGE and the SHELL do: which host is served, which document comes
 * back, which assets resolve, and that a path belonging to another account is not answered here.
 * Client-side routing and rendering are proven by the focused Playwright suite; the browser cannot
 * be asked to prove a `Host` header the platform forbids it to set.
 */
import http from 'node:http';
import { readFileSync } from 'node:fs';

const [, , ORIGIN = 'http://127.0.0.1:8099'] = process.argv;
const { hostname: HOST_IP, port: PORT } = new URL(ORIGIN);

const hosts = JSON.parse(readFileSync(new URL('../config/account-hosts.json', import.meta.url), 'utf8'));

/**
 * The canonical PRODUCTION host for an account, composed from the ONE account-host authority:
 * `subdomain` (null for the Merchant Administrator root) plus `domains.production`.
 */
function hostFor(accountKey) {
  const entry = hosts.accounts.find((account) => account.account_key === accountKey);
  if (!entry) throw new Error(`no account-host entry for ${accountKey}`);
  return entry.subdomain === null ? hosts.domains.production : `${entry.subdomain}.${hosts.domains.production}`;
}

function get(path, host, { redirect = false } = {}) {
  return new Promise((resolve) => {
    const request = http.request(
      { host: HOST_IP, port: PORT, path, method: 'GET', headers: { Host: host } },
      (response) => {
        let body = '';
        response.on('data', (chunk) => (body += chunk));
        response.on('end', () =>
          resolve({ status: response.statusCode, headers: response.headers, body, redirect }),
        );
      },
    );
    request.on('error', (error) => resolve({ status: 0, headers: {}, body: String(error) }));
    request.end();
  });
}

const results = [];
let failures = 0;

function check(name, condition, detail = '') {
  const ok = Boolean(condition);
  if (!ok) failures += 1;
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
}

const SUPER = hostFor('super_administrator');
const AUDIT = hostFor('merchant_audit');

const IMPLEMENTED = [
  '/dashboard',
  '/get-started',
  '/billing/settings',
  '/billing/plans',
  '/billing/prices',
  '/billing/promotions',
  '/billing/free-periods',
  '/billing/preferred-personnel-fees',
  '/billing/sms',
  '/merchants/registrations',
  '/merchants',
  '/merchants/01JQ0000000000000000000001',
  '/billing/subscriptions',
  '/audit',
  '/platform-access',
  '/platform/feature-flags',
  '/account',
];

const GATED = [
  '/billing/reconciliation-exceptions',
  '/integrations',
  '/integrations/refer-and-earn/qualifications',
  '/reports',
  '/notifications',
];

const REDIRECTS = [
  ['/platform/get-started', '/get-started'],
  ['/platform/billing-settings', '/billing/settings'],
  ['/platform/promotions', '/billing/promotions'],
  ['/platform/registration-monitoring', '/merchants/registrations'],
];

/** The account context the shell embeds. It is what decides the experience — never the URL. */
function embeddedAccount(body) {
  const match = body.match(/id="servana-account-context"[^>]*>([\s\S]*?)</);
  if (!match) return null;
  try {
    return JSON.parse(match[1]).account_key ?? null;
  } catch {
    return null;
  }
}

/** Every module/stylesheet the shell asks for, so a broken chunk reference cannot pass unnoticed. */
function assetsOf(body) {
  return [...body.matchAll(/(?:src|href)="(\/(?:spa-assets|assets)\/[^"]+)"/g)].map((m) => m[1]);
}

console.log(`Super Administrator host: ${SUPER}`);
console.log(`Merchant Audit host:      ${AUDIT}`);
console.log('');

// ── The seventeen implemented canonical routes ────────────────────────────────────────────────
let sharedAssets = [];
for (const path of IMPLEMENTED) {
  const response = await get(path, SUPER);
  const account = embeddedAccount(response.body);
  check(
    `implemented ${path}`,
    response.status === 200
      && String(response.headers['content-type']).includes('text/html')
      && account === 'super_administrator',
    `status ${response.status}, account ${account}`,
  );
  if (path === '/dashboard') sharedAssets = assetsOf(response.body);
}

// ── Assets, chunks and MIME ───────────────────────────────────────────────────────────────────
check('the shell references at least one fingerprinted SPA chunk', sharedAssets.some((a) => a.startsWith('/spa-assets/')), sharedAssets.join(' '));

const MIME = { '.js': 'javascript', '.css': 'text/css', '.ico': 'image', '.png': 'image', '.svg': 'image', '.woff2': 'font' };
for (const asset of sharedAssets) {
  const response = await get(asset, SUPER);
  const extension = asset.slice(asset.lastIndexOf('.'));
  const expected = MIME[extension];
  const type = String(response.headers['content-type'] ?? '');
  check(
    `asset ${asset}`,
    response.status === 200 && (expected === undefined || type.includes(expected)),
    `status ${response.status}, type ${type}`,
  );
}

// A source map must not be served in production.
const sourceMap = sharedAssets.find((a) => a.endsWith('.js'));
if (sourceMap) {
  const map = await get(`${sourceMap}.map`, SUPER);
  check('no JavaScript source map is served in production', map.status === 404, `status ${map.status}`);
}

// ── The five gated contract paths ─────────────────────────────────────────────────────────────
for (const path of GATED) {
  const response = await get(path, SUPER);
  const account = embeddedAccount(response.body);
  // The edge serves the SPA shell for any in-app address; what must be true is that the shell is
  // the ordinary Super Administrator shell and carries no server-rendered page for a gated
  // contract entry. The client router resolves it to not-found (proven by the focused E2E).
  check(
    `gated ${path} exposes no server-rendered page`,
    response.status === 200 && account === 'super_administrator' && !/reconciliation exception|integration health|qualification decision|platform report|notification inbox/i.test(response.body),
    `status ${response.status}`,
  );
}

// ── Compatibility redirects ───────────────────────────────────────────────────────────────────
for (const [from] of REDIRECTS) {
  const response = await get(from, SUPER);
  // The redirect is a CLIENT router record (Increment 7B), so the edge serves the same shell and
  // the router rewrites the address. What the edge must prove is that the legacy path still
  // resolves on this account host and is not a 404 or a cross-account document.
  check(
    `compatibility ${from} is served on the Super Administrator host`,
    response.status === 200 && embeddedAccount(response.body) === 'super_administrator',
    `status ${response.status}`,
  );
}

const dashboardLegacy = await get('/platform/dashboard', SUPER);
check(
  '/platform/dashboard is not a server redirect',
  dashboardLegacy.status === 200 && dashboardLegacy.headers.location === undefined,
  `status ${dashboardLegacy.status}, location ${dashboardLegacy.headers.location ?? 'none'}`,
);

const roleLanding = await get('/platform', SUPER);
check('/platform remains served as the role landing', roleLanding.status === 200 && roleLanding.headers.location === undefined, `status ${roleLanding.status}`);

// ── Multi-host collision ──────────────────────────────────────────────────────────────────────
const auditOnSuper = await get('/audit', SUPER);
const auditOnAudit = await get('/audit', AUDIT);
check('/audit on the Super Administrator host serves the Super Administrator account', embeddedAccount(auditOnSuper.body) === 'super_administrator');
check('/audit on the Merchant Audit host serves the Merchant Audit account', embeddedAccount(auditOnAudit.body) === 'merchant_audit', `account ${embeddedAccount(auditOnAudit.body)}`);

// ── Wrong account host ────────────────────────────────────────────────────────────────────────
for (const path of ['/dashboard', '/platform-access']) {
  const response = await get(path, AUDIT);
  const account = embeddedAccount(response.body);
  check(
    `${path} on the Merchant Audit host does not serve the Super Administrator account`,
    account === 'merchant_audit',
    `account ${account}`,
  );
}

// ── Unknown host ──────────────────────────────────────────────────────────────────────────────
const unknown = await get('/dashboard', 'not-a-servana-host.example');
check(
  'an unknown host is refused without a body and without falling back to an account',
  unknown.status === 0 || unknown.status === 444 || unknown.body === '',
  `status ${unknown.status}, ${unknown.body.length} bytes`,
);

console.log('');
console.log(`${results.filter((r) => r.ok).length} passed, ${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
