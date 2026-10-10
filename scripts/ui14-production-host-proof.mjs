#!/usr/bin/env node
/** Phase UI-14 canonical Personnel host proof against the final production image pair. */
import http from 'node:http';
import { readFileSync } from 'node:fs';

const [, , origin = 'http://127.0.0.1:8114'] = process.argv;
const { hostname, port } = new URL(origin);
const fromRoot = (path) => JSON.parse(readFileSync(new URL(`../${path}`, import.meta.url), 'utf8'));
const registry = fromRoot('config/account-hosts.json');
const activation = fromRoot('docs/frontend/audits/ui-14/route-activation.json');
const gates = fromRoot('docs/frontend/audits/ui-14/gate-disposition.json');
const hostFor = (key) => {
  const entry = registry.accounts.find((account) => account.account_key === key);
  if (!entry) throw new Error(`unknown account ${key}`);
  return entry.subdomain === null ? registry.domains.production : `${entry.subdomain}.${registry.domains.production}`;
};
const request = (path, host) => new Promise((resolve) => {
  const req = http.request({ host: hostname, port, path, method: 'GET', headers: { Host: host, Accept: path.startsWith('/api/') ? 'application/json' : 'text/html' } }, (response) => {
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
const assetsOf = (body) => [...body.matchAll(/(?:src|href)="(\/(?:spa-assets|assets)\/[^"]+)"/g)].map((match) => match[1]);
let failed = 0;
let passed = 0;
const check = (name, condition, detail = '') => {
  if (condition) passed += 1; else failed += 1;
  console.log(`${condition ? 'ok  ' : 'FAIL'}  ${name}${detail ? ` — ${detail}` : ''}`);
};

const staffHost = hostFor('merchant_personnel');
const implemented = activation.implemented_routes.map(({ route_path: path }) => path);
const gated = gates.entries.map(({ route_path: path }) => path);
const compatibility = activation.compatibility_redirects.map((entry) => entry.split(' -> ')[0]);
console.log(`Personnel host: ${staffHost}`);
check('host registry resolves Personnel to staff.servana.ke', staffHost === 'staff.servana.ke', staffHost);

let sharedAssets = [];
for (const path of implemented) {
  const response = await request(path, staffHost);
  const account = embeddedAccount(response.body);
  check(`implemented ${path}`, response.status === 200 && String(response.headers['content-type']).includes('text/html') && account === 'merchant_personnel', `status ${response.status}, account ${account}`);
  if (path === '/dashboard') sharedAssets = assetsOf(response.body);
}
check('fingerprinted SPA chunk referenced', sharedAssets.some((asset) => asset.startsWith('/spa-assets/')), sharedAssets.join(' '));
const mime = { '.js': 'javascript', '.css': 'text/css', '.ico': 'image', '.png': 'image', '.woff2': 'font' };
for (const asset of sharedAssets) {
  const response = await request(asset, staffHost);
  const extension = asset.slice(asset.lastIndexOf('.'));
  const expected = mime[extension];
  const actual = String(response.headers['content-type'] ?? '');
  check(`asset ${asset}`, response.status === 200 && (expected === undefined || actual.includes(expected)), `status ${response.status}, type ${actual}`);
}
const script = sharedAssets.find((asset) => asset.endsWith('.js'));
if (script) {
  const sourceMap = await request(`${script}.map`, staffHost);
  check('no production JavaScript source map', sourceMap.status === 404, `status ${sourceMap.status}`);
}
for (const path of gated) {
  const response = await request(path, staffHost);
  check(
    `gated ${path} has only the ordinary Personnel shell`,
    response.status === 200 && embeddedAccount(response.body) === 'merchant_personnel'
      && !/notification inbox|unread notifications|notification preferences|mark all read/i.test(response.body),
    `status ${response.status}`,
  );
}
for (const path of compatibility) {
  const response = await request(path, staffHost);
  check(`compatibility ${path} stays on the Personnel host`, response.status === 200 && embeddedAccount(response.body) === 'merchant_personnel', `status ${response.status}`);
}
// Colliding paths on other hosts must keep their own account context — never the Personnel shell.
for (const [accountKey, path] of [['merchant_administrator', '/dashboard'], ['merchant_front_office', '/account'], ['merchant_finance', '/notifications'], ['merchant_human_resource', '/dashboard']]) {
  const response = await request(path, hostFor(accountKey));
  const account = embeddedAccount(response.body);
  check(`${path} on ${accountKey} host never serves Personnel context`, response.status === 200 && account === accountKey, `account ${account}`);
}
const api = await request('/api/v1/personnel/me/workspace', staffHost);
check('unauthenticated own-scope API is refused on the production edge', api.status === 401, `status ${api.status}`);
const unknown = await request('/dashboard', 'not-a-servana-host.example');
check('unknown host refused without account fallback', unknown.status === 0 || unknown.status === 444 || unknown.body === '', `status ${unknown.status}, bytes ${unknown.body.length}`);
console.log(`\n${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
