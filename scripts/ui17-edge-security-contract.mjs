#!/usr/bin/env node
// Phase UI-17 — static contract for the edge security policy in docker/nginx/default.conf.
//
// The runtime proof (tests/e2e-origin/ui17-edge-security.spec.ts and scripts/ui17-edge-smoke.mjs)
// needs a running image; this check needs nothing but the repository, so CI's Frontend job can fail
// a drift before an image is ever built:
//
//   1. The ONE inline script (the theme bootstrap) is byte-identical in the Laravel shell and the
//      standalone SPA index, and the CSP allows exactly its SHA-256 — so editing the script without
//      updating the policy (or the reverse) fails here instead of silently blanking every page.
//   2. The CSP never grants 'unsafe-inline', 'unsafe-eval', a wildcard source or a remote origin,
//      and keeps the framing, object and base-uri locks.
//   3. Every server block logs with the credential-safe `servana_edge` format, and that format logs
//      neither the request line (query string) nor the referer (UI17-SEC-001).
//   4. The edge never appends a Referrer-Policy over one the application chose (UI17-SEC-002), and
//      text assets are compressed only inside the static-asset locations (UI17-PERF-001).
//
// Usage: node scripts/ui17-edge-security-contract.mjs [--print-hash] [--root <dir>]

import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const ROOT = resolve(arg('--root', resolve(dirname(fileURLToPath(import.meta.url)), '..')));

const failures = [];
const check = (condition, message) => {
  if (!condition) failures.push(message);
};

/** Every classic inline script body (no src, executable type) in an HTML/Blade document. */
function inlineScripts(source) {
  const bodies = [];
  for (const match of source.matchAll(/<script(\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
    const attributes = match[1] ?? '';
    if (/\ssrc=/.test(attributes)) continue;
    const type = attributes.match(/\stype="([^"]+)"/)?.[1] ?? null;
    if (type !== null && type !== 'module' && type !== 'text/javascript') continue; // data blocks never execute
    bodies.push(match[2]);
  }
  return bodies;
}

const sha256 = (text) => `sha256-${createHash('sha256').update(text, 'utf8').digest('base64')}`;

const blade = readFileSync(join(ROOT, 'resources/views/spa.blade.php'), 'utf8');
const index = readFileSync(join(ROOT, 'resources/spa/index.html'), 'utf8');
const conf = readFileSync(join(ROOT, 'docker/nginx/default.conf'), 'utf8');

const bladeScripts = inlineScripts(blade);
const indexScripts = inlineScripts(index);
check(bladeScripts.length === 1, `the Laravel shell must carry exactly ONE inline script, found ${bladeScripts.length}`);
check(indexScripts.length === 1, `resources/spa/index.html must carry exactly ONE inline script, found ${indexScripts.length}`);
check(bladeScripts[0] === indexScripts[0], 'the theme bootstrap differs between spa.blade.php and index.html');
check(!/\{\{|\{!!|@/.test(bladeScripts[0] ?? ''), 'the inline script must stay a fixed literal (no Blade interpolation)');

const hash = sha256(bladeScripts[0] ?? '');
if (process.argv.includes('--print-hash')) {
  console.log(hash);
  process.exit(0);
}

const csp = conf.match(/add_header Content-Security-Policy "([^"]+)" always;/)?.[1] ?? null;
check(csp !== null, 'docker/nginx/default.conf sets no Content-Security-Policy');
if (csp !== null) {
  const directives = Object.fromEntries(
    csp.split(';').map((part) => part.trim()).filter(Boolean).map((part) => {
      const [name, ...values] = part.split(/\s+/);
      return [name, values];
    }),
  );
  check(directives['script-src']?.includes(`'${hash}'`), `script-src does not allow the theme bootstrap hash '${hash}'`);
  const hashes = (directives['script-src'] ?? []).filter((value) => value.startsWith("'sha256-"));
  check(hashes.length === 1, `script-src must allow exactly one inline hash, found ${hashes.length}`);
  for (const [name, values] of Object.entries(directives)) {
    for (const value of values) {
      check(!["'unsafe-inline'", "'unsafe-eval'", "'unsafe-hashes'", '*', 'http:', 'https:'].includes(value), `${name} grants ${value}`);
      check(!/^https?:\/\//.test(value) && !/\.\w+$/.test(value.replace(/'/g, '')) || value.startsWith("'"), `${name} allows a remote source ${value}`);
    }
  }
  check(directives['default-src']?.join(' ') === "'self'", "default-src must be 'self'");
  check(directives['object-src']?.join(' ') === "'none'", "object-src must be 'none'");
  check(directives['base-uri']?.join(' ') === "'none'", "base-uri must be 'none'");
  check(directives['frame-ancestors']?.join(' ') === "'none'", "frame-ancestors must be 'none'");
  check(directives['form-action']?.join(' ') === "'self'", "form-action must be 'self'");
  check(directives['font-src']?.join(' ') === "'self'", "font-src must be 'self' (fonts are never inlined; see vite.config.ts)");
  check(!(directives['script-src'] ?? []).includes('data:') && !(directives['script-src'] ?? []).includes('blob:'), 'script-src must not allow data: or blob:');
}

const serverBlocks = conf.split(/\n(?=server \{)/).filter((block) => block.startsWith('server {'));
check(serverBlocks.length === 3, `expected 3 server blocks, found ${serverBlocks.length}`);
for (const [i, block] of serverBlocks.entries()) {
  check(/access_log \/var\/log\/nginx\/access\.log servana_edge;/.test(block), `server block ${i + 1} does not use the servana_edge log format`);
}
const logFormat = conf.match(/log_format servana_edge[\s\S]*?;\n/)?.[0] ?? '';
check(logFormat !== '', 'the servana_edge log format is missing');
for (const forbidden of ['$request"', '$request ', '$request_uri', '$args', '$query_string', '$http_referer', '$http_cookie', '$http_authorization']) {
  check(!logFormat.includes(forbidden), `the servana_edge log format records ${forbidden.trim()}`);
}

check(/add_header Referrer-Policy \$servana_referrer_policy always;/.test(conf), 'the edge Referrer-Policy must defer to an application-chosen policy');
check(!/add_header Referrer-Policy "/.test(conf), 'a literal edge Referrer-Policy would append over the application value');
check(/add_header X-Frame-Options "DENY" always;/.test(conf), 'X-Frame-Options DENY is missing');
check(/add_header X-Content-Type-Options "nosniff" always;/.test(conf), 'nosniff is missing');
check(/add_header Permissions-Policy "[^"]+" always;/.test(conf), 'Permissions-Policy is missing');
check(/add_header Strict-Transport-Security \$servana_hsts always;/.test(conf), 'HSTS must be emitted only through the HTTPS-gated map');

const gzipOn = [...conf.matchAll(/^\s*gzip on;/gm)].length;
check(gzipOn === 3, `gzip must be enabled in exactly the three static-asset locations, found ${gzipOn}`);
check(!/^gzip on;/m.test(conf), 'gzip must not be enabled at the http level (it would compress HTML/API responses)');
check(!/gzip_types[^;]*application\/json/.test(conf), 'API JSON must not be compressed at the edge');
check(/^gzip_http_version 1\.0;/m.test(conf), 'gzip_http_version must be 1.0 so compression survives an HTTP/1.0 proxy hop (UI17-PERF-006)');

if (failures.length > 0) {
  console.error(`UI-17 edge security contract FAILED (${failures.length}):`);
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}
console.log(`UI-17 edge security contract OK — CSP pins ${hash}; 3 server blocks log credential-safe; referrer/HSTS/gzip rules hold.`);
