#!/usr/bin/env node
// Phase UI-17 — settled screenshots of the eight account hosts for the release record.
//
// Same capture, two origins:
//   --origin 127.0.0.1:8172   built image pair on an isolated local origin (labelled as such — this is
//                             NOT production evidence)
//   --live                    the real production hosts through public DNS and TLS (post-deploy only)
//
// Each host: landing at desktop 1440 light, mobile 360 light and mobile 360 dark, and the sign-in page
// at mobile 360 light. Every frame waits for fonts and network to settle and records its SHA-256 in
// index.json, so a later reviewer can tell exactly which bytes were reviewed.
//
// Usage: node scripts/ui17-host-screenshots.mjs (--origin <host:port> | --live) --out-dir <dir> --label <text>

import { chromium } from '@playwright/test';
import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const LIVE = process.argv.includes('--live');
const ORIGIN = arg('--origin', '127.0.0.1:8080');
const OUT_DIR = resolve(ROOT, arg('--out-dir', 'docs/frontend/audits/ui-17/screenshots/local-origin'));
const LABEL = arg('--label', LIVE ? 'production' : 'built-image local origin (not production)');

const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const domain = registry.domains.production;
const HOSTS = registry.accounts.map((a) => ({ key: a.account_key, host: a.subdomain === null ? domain : `${a.subdomain}.${domain}` }));
const FRAMES = [
  { id: 'landing-desktop-light', path: '/', width: 1440, height: 900, theme: 'light' },
  { id: 'landing-mobile-light', path: '/', width: 360, height: 780, theme: 'light' },
  { id: 'landing-mobile-dark', path: '/', width: 360, height: 780, theme: 'dark' },
  { id: 'login-mobile-light', path: '/login', width: 360, height: 780, theme: 'light' },
];

mkdirSync(OUT_DIR, { recursive: true });
const browser = await chromium.launch({
  args: LIVE ? [] : [`--host-resolver-rules=MAP ${domain} ${ORIGIN}, MAP *.${domain} ${ORIGIN}`, '--disable-features=HttpsUpgrades'],
});
const index = [];
for (const { key, host } of HOSTS) {
  for (const frame of FRAMES) {
    const context = await browser.newContext({ viewport: { width: frame.width, height: frame.height }, deviceScaleFactor: 1, reducedMotion: 'reduce' });
    if (frame.theme === 'dark') await context.addInitScript(() => localStorage.setItem('servana.theme', 'dark'));
    const page = await context.newPage();
    const url = `${LIVE ? 'https' : 'http'}://${host}${frame.path}`;
    const response = await page.goto(url, { waitUntil: 'networkidle', timeout: 90_000 });
    await page.locator('h1').first().waitFor({ state: 'visible' });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(300);
    const file = `${key}--${frame.id}.png`;
    const bytes = await page.screenshot({ path: join(OUT_DIR, file) });
    index.push({
      account_key: key,
      host,
      frame: frame.id,
      url,
      status: response?.status() ?? null,
      dark: await page.evaluate(() => document.documentElement.classList.contains('dark')),
      file,
      sha256: createHash('sha256').update(bytes).digest('hex'),
      bytes: bytes.length,
    });
    await context.close();
  }
  console.log(`captured ${host}`);
}
await browser.close();
writeFileSync(
  join(OUT_DIR, 'index.json'),
  `${JSON.stringify({ generated_by: 'scripts/ui17-host-screenshots.mjs', label: LABEL, origin: LIVE ? 'live' : ORIGIN, captured_at: new Date().toISOString(), frames: index }, null, 2)}\n`,
);
console.log(`${index.length} frames → ${OUT_DIR}`);
