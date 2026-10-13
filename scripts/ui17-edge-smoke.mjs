#!/usr/bin/env node
// Phase UI-17 — eight-host edge smoke + browser-security matrix (UI/UX plan §4.6, §4.7, §18.5, §18.6).
//
// One probe, three uses:
//   * staging / isolated rehearsal — `--origin http://127.0.0.1:8172`: every request goes to that
//     address with the account host in the `Host` header (plain HTTP; NOT TLS evidence);
//   * production smoke after a deploy — `--live`: real DNS, real HTTPS with SNI on each canonical
//     host, certificate issuer/SAN/expiry recorded;
//   * synthetic monitoring — the same `--live` run on a schedule; a non-zero exit is the alert.
//
// Every request is anonymous and synthetic. No real session is created, no Magic Link is requested,
// and the only "token" ever sent is a fixed, clearly synthetic canary that no server can accept.
//
// Usage:
//   node scripts/ui17-edge-smoke.mjs --origin http://127.0.0.1:8172 [--domain production] [--out <json>]
//   node scripts/ui17-edge-smoke.mjs --live [--out <json>] [--min-cert-days 21]

import http from 'node:http';
import https from 'node:https';
import tls from 'node:tls';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const LIVE = process.argv.includes('--live');
const ORIGIN = LIVE ? null : new URL(arg('--origin', 'http://127.0.0.1:8080'));
const DOMAIN_SET = arg('--domain', 'production');
const OUT = arg('--out', null);
const MIN_CERT_DAYS = Number(arg('--min-cert-days', '21'));
export const SYNTHETIC_TOKEN = 'UI17SYNTHETICCANARY0000000000000000000000';

const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const domain = registry.domains[DOMAIN_SET] ?? registry.domains.production;
const ACCOUNTS = registry.accounts.map((a) => ({ key: a.account_key, host: a.subdomain === null ? domain : `${a.subdomain}.${domain}` }));
const blade = readFileSync(join(ROOT, 'resources/views/spa.blade.php'), 'utf8');
const conf = readFileSync(join(ROOT, 'docker/nginx/default.conf'), 'utf8');
const EXPECTED_CSP = conf.match(/add_header Content-Security-Policy "([^"]+)" always;/)?.[1] ?? null;
void blade;

/** One request; never follows redirects; resolves even on connection close (reported as such). */
function request(host, path, { method = 'GET', headers = {} } = {}) {
  return new Promise((done) => {
    const options = LIVE
      ? { host, servername: host, port: 443, path, method, headers: { Host: host, ...headers }, timeout: 20_000 }
      : { host: ORIGIN.hostname, port: ORIGIN.port || 80, path, method, headers: { Host: host, ...headers }, timeout: 20_000 };
    const req = (LIVE ? https : http).request(options, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => done({ status: res.statusCode, headers: res.headers, raw: res.rawHeaders, body: Buffer.concat(chunks) }));
    });
    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', (error) => done({ status: null, error: error.code ?? error.message, headers: {}, raw: [], body: Buffer.alloc(0) }));
    req.end();
  });
}

/** All values of a header as sent on the wire (node joins duplicates; rawHeaders does not). */
const headerValues = (response, name) => {
  const values = [];
  for (let i = 0; i < response.raw.length; i += 2) if (response.raw[i].toLowerCase() === name) values.push(response.raw[i + 1]);
  return values;
};

function certificate(host) {
  return new Promise((done) => {
    const socket = tls.connect({ host, port: 443, servername: host, timeout: 20_000 }, () => {
      const cert = socket.getPeerCertificate();
      const authorized = socket.authorized;
      socket.end();
      const san = (cert.subjectaltname ?? '').split(',').map((s) => s.trim().replace(/^DNS:/, ''));
      const matches = san.some((name) => name === host || (name.startsWith('*.') && host.endsWith(name.slice(1)) && host.split('.').length === name.split('.').length));
      const days = Math.floor((new Date(cert.valid_to).getTime() - Date.now()) / 86_400_000);
      done({ authorized, authorization_error: socket.authorizationError ?? null, issuer: cert.issuer?.O ?? cert.issuer?.CN ?? null, subject: cert.subject?.CN ?? null, san, matches_host: matches, valid_to: cert.valid_to, days_remaining: days });
    });
    socket.on('error', (error) => done({ error: error.code ?? error.message }));
    socket.on('timeout', () => socket.destroy(new Error('timeout')));
  });
}

const failures = [];
const rows = [];
const check = (row, ok, message) => {
  row.checks.push({ ok, message });
  if (!ok) failures.push(`${row.host}: ${message}`);
};

for (const { key, host } of ACCOUNTS) {
  const row = { account_key: key, host, checks: [] };
  const shell = await request(host, '/');
  const html = shell.body.toString('utf8');
  check(row, shell.status === 200, `GET / → ${shell.status ?? shell.error}`);
  check(row, html.includes(`data-account-key="${key}"`), 'shell resolves its own account context');
  check(row, (shell.headers['cache-control'] ?? '').includes('no-cache'), `shell revalidates (cache-control '${shell.headers['cache-control']}')`);
  const csp = headerValues(shell, 'content-security-policy');
  check(row, csp.length === 1 && csp[0] === EXPECTED_CSP, `exactly one CSP equal to the reviewed policy (got ${csp.length})`);
  check(row, headerValues(shell, 'x-frame-options').join() === 'DENY', 'X-Frame-Options DENY');
  check(row, headerValues(shell, 'x-content-type-options').join() === 'nosniff', 'nosniff');
  check(row, headerValues(shell, 'referrer-policy').join() === 'strict-origin-when-cross-origin', 'one Referrer-Policy on the shell');
  check(row, headerValues(shell, 'permissions-policy').length === 1, 'Permissions-Policy present');
  const hsts = headerValues(shell, 'strict-transport-security');
  check(row, LIVE ? hsts.length === 1 : hsts.length === 0, LIVE ? 'HSTS present over HTTPS' : 'no HSTS over plain HTTP');
  check(row, !/\bX-Powered-By\b/i.test(shell.raw.join(' ')), 'no X-Powered-By');
  row.headers = { csp: csp[0] ?? null, hsts: hsts[0] ?? null, cache_control: shell.headers['cache-control'] ?? null };

  const entry = html.match(/<script type="module" src="([^"]+)"/)?.[1] ?? null;
  const style = html.match(/<link rel="stylesheet" href="([^"]+)"/)?.[1] ?? null;
  check(row, entry !== null && style !== null, 'shell names a fingerprinted entry script and stylesheet');
  if (entry) {
    const js = await request(host, entry, { headers: { 'Accept-Encoding': 'gzip, br' } });
    check(row, js.status === 200, `entry js → ${js.status}`);
    check(row, (js.headers['content-type'] ?? '').includes('javascript'), 'entry js MIME');
    check(row, js.headers['content-encoding'] === 'gzip', `entry js compressed (content-encoding '${js.headers['content-encoding'] ?? 'none'}')`);
    check(row, (js.headers['cache-control'] ?? '').includes('immutable'), 'entry js immutable');
    check(row, (js.headers['vary'] ?? '').toLowerCase().includes('accept-encoding'), 'entry js Vary: Accept-Encoding');
    const map = await request(host, `${entry}.map`);
    check(row, map.status === 404, `source map not served (${map.status})`);
    row.entry = { path: entry, status: js.status, encoding: js.headers['content-encoding'] ?? null, wire_bytes: js.body.length };
  }
  if (style) {
    const css = await request(host, style, { headers: { 'Accept-Encoding': 'gzip' } });
    check(row, css.status === 200 && (css.headers['content-type'] ?? '').includes('text/css'), `entry css → ${css.status}`);
    check(row, css.headers['content-encoding'] === 'gzip', 'entry css compressed');
  }

  for (const path of ['/login', '/faq', '/legal/data-policy', '/legal/privacy-policy', '/legal/terms-of-service']) {
    const page = await request(host, path);
    check(row, page.status === 200 && page.body.toString('utf8').includes(`data-account-key="${key}"`), `${path} → ${page.status} in its own account context`);
  }
  const hostHealth = await request(host, '/health/host');
  check(row, hostHealth.status === 200, `/health/host → ${hostHealth.status}`);

  for (const [path, allowed] of [['/spa/.vite/manifest.json', [301]], ['/.env', [403, 404]], ['/.git/config', [403, 404]], ['/storage/../.env', [400, 403, 404]]]) {
    const probe = await request(host, path);
    check(row, allowed.includes(probe.status), `${path} withheld (${probe.status})`);
  }

  const preflight = await request(host, '/api/v1/me', { method: 'OPTIONS', headers: { Origin: 'https://attacker.example', 'Access-Control-Request-Method': 'POST' } });
  check(row, headerValues(preflight, 'access-control-allow-origin').length === 0, 'no CORS approval for a foreign origin');
  const me = await request(host, '/api/v1/me', { headers: { Accept: 'application/json', Origin: 'https://attacker.example' } });
  const meBody = me.body.toString('utf8');
  check(row, me.status === 401, `anonymous /api/v1/me → ${me.status}`);
  check(row, headerValues(me, 'access-control-allow-origin').length === 0, 'API response not readable cross-origin');
  check(row, !/SQLSTATE|Stack trace|vendor\/laravel|#0 \//.test(meBody), 'error envelope carries no stack trace');

  const forged = await request(host, '/', { headers: { 'X-Forwarded-Host': 'attacker.example', 'X-Forwarded-Proto': 'https' } });
  const forgedHtml = forged.body.toString('utf8');
  check(row, forged.status === 200 && forgedHtml.includes(`data-account-key="${key}"`) && !forgedHtml.includes('attacker.example'), 'forged X-Forwarded-Host changes nothing');

  const handoff = await request(host, `/auth/switch?token=${SYNTHETIC_TOKEN}`);
  const referrer = headerValues(handoff, 'referrer-policy');
  check(row, handoff.status === 302, `synthetic handoff fails closed with a redirect (${handoff.status})`);
  check(row, referrer.length === 1 && referrer[0] === 'no-referrer', `handoff keeps exactly 'no-referrer' (got ${JSON.stringify(referrer)})`);
  check(row, (handoff.headers['cache-control'] ?? '').includes('no-store'), 'handoff is no-store');

  if (LIVE) {
    const cert = await certificate(host);
    row.certificate = cert;
    check(row, cert.authorized === true, `certificate trusted (${cert.authorization_error ?? cert.error ?? 'ok'})`);
    check(row, cert.matches_host === true, 'certificate SAN covers the host');
    check(row, (cert.days_remaining ?? -1) >= MIN_CERT_DAYS, `certificate expires in ${cert.days_remaining} days (alert below ${MIN_CERT_DAYS})`);
    const plain = await new Promise((done) => {
      http.get({ host, port: 80, path: '/', timeout: 20_000 }, (res) => done({ status: res.statusCode, location: res.headers.location })).on('error', (e) => done({ error: e.code }));
    });
    check(row, [301, 308].includes(plain.status) && String(plain.location ?? '').startsWith(`https://${host}`), `http://${host}/ redirects to HTTPS (${plain.status ?? plain.error})`);
  }

  row.passed = row.checks.every((c) => c.ok);
  rows.push(row);
}

const denials = [];
if (!LIVE) {
  for (const host of ['attacker.test', 'evil-servana.ke', 'servana.ke.attacker.test', 'unknown.servana.ke']) {
    const probe = await request(host, '/');
    const refused = probe.status === null;
    const ok = refused || probe.status === 421;
    if (!ok) failures.push(`unknown host ${host}: expected a closed connection or 421, got ${probe.status}`);
    denials.push({ host, connection_closed: refused, status: probe.status, passed: ok });
  }
}

const report = {
  generated_by: 'scripts/ui17-edge-smoke.mjs',
  mode: LIVE ? 'live (DNS + HTTPS + SNI)' : 'origin override (plain HTTP, Host header) — not TLS evidence',
  origin: ORIGIN?.toString() ?? null,
  domain,
  synthetic_token: SYNTHETIC_TOKEN,
  run_at: new Date().toISOString(),
  accounts: rows,
  unknown_host_denials: denials,
  checks: rows.reduce((n, r) => n + r.checks.length, 0) + denials.length,
  passed: failures.length === 0,
  failures,
};
if (OUT) {
  mkdirSync(dirname(resolve(ROOT, OUT)), { recursive: true });
  writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
}
for (const row of rows) console.log(`${row.passed ? 'ok  ' : 'FAIL'} ${row.host.padEnd(20)} ${row.checks.filter((c) => c.ok).length}/${row.checks.length}`);
for (const d of denials) console.log(`${d.passed ? 'ok  ' : 'FAIL'} unknown ${d.host} ${d.connection_closed ? 'closed' : d.status}`);
if (failures.length > 0) {
  console.error(`\nUI-17 edge smoke FAILED (${failures.length}):`);
  for (const f of failures) console.error(`  - ${f}`);
  process.exit(1);
}
console.log(`\nUI-17 edge smoke OK — ${report.checks} checks, ${report.mode}.`);
