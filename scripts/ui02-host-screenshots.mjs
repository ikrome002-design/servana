#!/usr/bin/env node
// Phase UI-02 — focused host screenshot matrix.
//
// Captures ONE screenshot per approved account host at one representative desktop viewport,
// against the real Nginx + PHP serving topology (not `vite preview`). Chromium is launched
// with `--host-resolver-rules` so the browser sends genuine `Host` headers for
// `*.servana.test` without anyone editing C:\Windows\System32\drivers\etc\hosts.
//
// This is NOT a release visual baseline. UI-16 owns reviewed baselines and the full
// responsive/theme matrix; UI-01's 141-screenshot audit set is historical evidence and is
// left untouched.
//
// Usage: node scripts/ui02-host-screenshots.mjs [--origin http://127.0.0.1:8080]

import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

function arg(name, fallback) {
  const index = process.argv.indexOf(name);

  return index === -1 ? fallback : process.argv[index + 1];
}

const ORIGIN = arg('--origin', 'http://127.0.0.1:8080');
const OUT_DIR = 'docs/frontend/audits/ui-02/screenshots';
const INDEX = 'docs/frontend/audits/ui-02/screenshot-index.json';
const VIEWPORT = { width: 1280, height: 800 };

const source = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const manifest = JSON.parse(readFileSync(join(ROOT, 'public/spa/.vite/manifest.json'), 'utf8'));
const manifestHash = createHash('sha256')
  .update(readFileSync(join(ROOT, 'public/spa/.vite/manifest.json')))
  .digest('hex');

const sourceCommit = execSync('git rev-parse HEAD', { cwd: ROOT }).toString().trim();
const port = new URL(ORIGIN).port || '80';

const localHost = (account) =>
  account.subdomain === null ? source.domains.local : `${account.subdomain}.${source.domains.local}`;

mkdirSync(join(ROOT, OUT_DIR), { recursive: true });

// MAP every account host (and one unknown host) to the origin address, so the browser
// resolves them locally and still sends the real hostname in the Host header.
const hostRules = [...source.accounts.map(localHost), 'unknown-host.test']
  .map((host) => `MAP ${host}:${port} 127.0.0.1:${port}`)
  .join(', ');

const browser = await chromium.launch({ args: [`--host-resolver-rules=${hostRules}`] });
const context = await browser.newContext({ viewport: VIEWPORT });

const entries = [];
const consoleProblems = [];

for (const account of source.accounts) {
  const host = localHost(account);
  const page = await context.newPage();
  const assetErrors = [];
  const benignErrors = [];

  // What matters here is ASSET failure: a missing entry chunk, stylesheet or brand asset is
  // the UI-01 defect being closed. An anonymous visitor's `/me` bootstrap answering 401 is
  // correct behaviour on a public page — Chromium logs every 4xx as a console error, so it
  // is recorded separately rather than treated as a regression.
  page.on('console', (message) => {
    if (message.type() !== 'error') {
      return;
    }
    const text = message.text();
    (/\b401\b|\b419\b/.test(text) ? benignErrors : assetErrors).push(text);
  });
  page.on('requestfailed', (request) => {
    assetErrors.push(`request failed: ${request.url()}`);
  });
  page.on('response', (response) => {
    const url = response.url();
    if (response.status() >= 400 && /\/(spa-assets|assets)\//.test(url)) {
      assetErrors.push(`asset ${response.status()}: ${url}`);
    }
  });

  await page.goto(`http://${host}:${port}/`, { waitUntil: 'networkidle' });

  // The SPA must have mounted — a blank #app is the exact UI-01 defect being closed.
  await page.waitForSelector('[data-servana-surface="foundation_only"]', { timeout: 15_000 });

  const renderedAccountKey = await page.getAttribute(
    '[data-servana-surface="foundation_only"]',
    'data-account-key',
  );
  const title = await page.title();

  if (renderedAccountKey !== account.account_key) {
    throw new Error(`${host} rendered account '${renderedAccountKey}', expected '${account.account_key}'`);
  }
  if (assetErrors.length > 0) {
    consoleProblems.push({ host, errors: assetErrors });
  }

  const file = `${account.account_key}.png`;
  const path = join(ROOT, OUT_DIR, file);
  await page.screenshot({ path, fullPage: false });

  entries.push({
    host,
    account_key: account.account_key,
    rendered_account_key: renderedAccountKey,
    route: '/',
    viewport: `${VIEWPORT.width}x${VIEWPORT.height}`,
    theme: 'default (light)',
    rendered_marker: 'data-servana-surface="foundation_only"',
    title,
    file: `${OUT_DIR}/${file}`,
    image_sha256: createHash('sha256').update(readFileSync(path)).digest('hex'),
    asset_errors: assetErrors.length,
    benign_401_errors: benignErrors.length,
  });

  await page.close();
}

// Unknown-host denial. There is deliberately NO screenshot: nginx answers an unapproved
// Host with `return 444`, closing the connection without a response, so the browser has
// nothing to render. That absence is the evidence — a denial that renders no page at all is
// strictly stronger than one that renders an error page, and it discloses nothing.
const denialPage = await context.newPage();
let denialOutcome;

try {
  const response = await denialPage.goto(`http://unknown-host.test:${port}/`, {
    waitUntil: 'domcontentloaded',
    timeout: 15_000,
  });
  denialOutcome = {
    connection_closed: false,
    status: response?.status() ?? null,
    body_rendered: true,
  };
} catch (error) {
  denialOutcome = {
    connection_closed: true,
    status: null,
    body_rendered: false,
    browser_error: String(error).split('\n')[0],
  };
}

await denialPage.close();

if (!denialOutcome.connection_closed && denialOutcome.status !== 421) {
  throw new Error(`Unknown host was not denied: status ${denialOutcome.status}`);
}

await context.close();
await browser.close();

writeFileSync(
  join(ROOT, INDEX),
  `${JSON.stringify(
    {
      generated_by: 'scripts/ui02-host-screenshots.mjs',
      purpose:
        'Focused UI-02 host-foundation evidence. NOT a release visual baseline — UI-16 owns '
        + 'reviewed baselines and the responsive/theme matrix. UI-01 screenshots are untouched.',
      origin: ORIGIN,
      source_commit: sourceCommit,
      vite_manifest_sha256: manifestHash,
      vite_entry: `/${manifest['index.html'].file}`,
      viewport: `${VIEWPORT.width}x${VIEWPORT.height}`,
      fixture_status: 'anonymous public surface; no seeded or customer data rendered',
      captured_at: new Date().toISOString(),
      screenshots: entries,
      unknown_host_denial: {
        host: 'unknown-host.test',
        screenshot: null,
        reason:
          'nginx returns 444 for an unapproved Host, closing the connection with no response. '
          + 'There is no rendered page to capture — the absence IS the evidence.',
        ...denialOutcome,
      },
      console_problems: consoleProblems,
    },
    null,
    2,
  )}\n`,
  'utf8',
);

if (consoleProblems.length > 0) {
  console.error('Console errors were recorded:');
  for (const problem of consoleProblems) {
    console.error(`  ${problem.host}: ${problem.errors.join(' | ')}`);
  }
  process.exit(1);
}

console.log(`Captured ${entries.length} screenshots to ${OUT_DIR}`);
for (const entry of entries) {
  console.log(`  ${String(entry.host).padEnd(26)} -> ${entry.account_key ?? 'denied'}`);
}
