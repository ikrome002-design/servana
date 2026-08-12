#!/usr/bin/env node
/** Phase UI-09 canonical-host proof against the final production image pair. */
import http from 'node:http';
import { readFileSync } from 'node:fs';

const [, , origin = 'http://127.0.0.1:8109'] = process.argv;
const { hostname, port } = new URL(origin);
const registry = JSON.parse(readFileSync(new URL('../config/account-hosts.json', import.meta.url), 'utf8'));
const hostFor = (key) => {
  const entry = registry.accounts.find((account) => account.account_key === key);
  if (!entry) throw new Error(`unknown account ${key}`);
  return entry.subdomain === null ? registry.domains.production : `${entry.subdomain}.${registry.domains.production}`;
};
const request = (path, host) => new Promise((resolve) => {
  const req = http.request({ host: hostname, port, path, method: 'GET', headers: { Host: host } }, (response) => {
    let body = '';
    response.on('data', (chunk) => (body += chunk));
    response.on('end', () => resolve({ status: response.statusCode, headers: response.headers, body }));
  });
  req.on('error', (error) => resolve({ status: 0, headers: {}, body: String(error) }));
  req.end();
});
const embeddedAccount = (body) => {
  const match = body.match(/id="servana-account-context"[^>]*>([\s\S]*?)</);
  if (!match) return null;
  try { return JSON.parse(match[1]).account_key ?? null; } catch { return null; }
};
const assetsOf = (body) => [...body.matchAll(/(?:src|href)="(\/(?:spa-assets|assets)\/[^\"]+)"/g)].map((match) => match[1]);
let failed = 0;
let passed = 0;
const check = (name, condition, detail = '') => {
  if (condition) passed += 1; else failed += 1;
  console.log(`${condition ? 'ok  ' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

const merchantHost = hostFor('merchant_administrator');
const branchHost = hostFor('merchant_branch');
const implemented = [
  '/setup', '/dashboard', '/get-started', '/merchant/profile', '/branches',
  '/branches/01JQ0000000000000000000001', '/staff', '/subscription', '/subscription/plan',
  '/subscription/invoices', '/subscription/invoices/01JQ0000000000000000000002',
  '/compensation', '/compensation/payout-approvals', '/finance/period-reopen-approvals', '/account',
];
const gated = [
  '/subscription/payment-attempts', '/subscription/recovery', '/reports', '/reports/branches',
  '/reports/services', '/reports/staff', '/reports/daily', '/notifications',
];
const compatibility = [
  '/onboarding/first-time-setup', '/merchant/dashboard', '/merchant/get-started',
  '/merchant/subscription', '/merchant/plan', '/merchant/subscription-invoices',
  '/merchant/compensation-summary', '/merchant/period-reopen-approvals',
];

console.log(`Merchant Administrator host: ${merchantHost}`);
let sharedAssets = [];
for (const path of implemented) {
  const response = await request(path, merchantHost);
  const account = embeddedAccount(response.body);
  check(`implemented ${path}`, response.status === 200 && String(response.headers['content-type']).includes('text/html') && account === 'merchant_administrator', `status ${response.status}, account ${account}`);
  if (path === '/dashboard') sharedAssets = assetsOf(response.body);
}
check('fingerprinted SPA chunk referenced', sharedAssets.some((asset) => asset.startsWith('/spa-assets/')), sharedAssets.join(' '));
const mime = { '.js': 'javascript', '.css': 'text/css', '.ico': 'image', '.png': 'image', '.woff2': 'font' };
for (const asset of sharedAssets) {
  const response = await request(asset, merchantHost);
  const extension = asset.slice(asset.lastIndexOf('.'));
  const expected = mime[extension];
  const actual = String(response.headers['content-type'] ?? '');
  check(`asset ${asset}`, response.status === 200 && (expected === undefined || actual.includes(expected)), `status ${response.status}, type ${actual}`);
}
const script = sharedAssets.find((asset) => asset.endsWith('.js'));
if (script) {
  const sourceMap = await request(`${script}.map`, merchantHost);
  check('no production JavaScript source map', sourceMap.status === 404, `status ${sourceMap.status}`);
}
for (const path of gated) {
  const response = await request(path, merchantHost);
  check(`gated ${path} has only the ordinary account shell`, response.status === 200 && embeddedAccount(response.body) === 'merchant_administrator' && !/payment attempt history|billing recovery workflow|merchant report results|notification inbox/i.test(response.body), `status ${response.status}`);
}
for (const path of compatibility) {
  const response = await request(path, merchantHost);
  check(`compatibility ${path} stays on the Merchant Administrator host`, response.status === 200 && embeddedAccount(response.body) === 'merchant_administrator', `status ${response.status}`);
}
for (const path of ['/dashboard', '/staff', '/subscription']) {
  const response = await request(path, branchHost);
  check(`${path} on Branch host does not serve Merchant Administrator context`, embeddedAccount(response.body) === 'merchant_branch', `account ${embeddedAccount(response.body)}`);
}
const unknown = await request('/dashboard', 'not-a-servana-host.example');
check('unknown host refused without account fallback', unknown.status === 0 || unknown.status === 444 || unknown.body === '', `status ${unknown.status}, bytes ${unknown.body.length}`);
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
