#!/usr/bin/env node
// Phase UI-17 — layout-shift attribution for the public landing pages (root-cause tool, not a gate).
//
// For each host it loads `/` cold under the mobile profile and records every layout-shift entry with
// its SOURCES (the shifted node, its previous and current rect) alongside the timeline of font loads
// and the landing state, so a CLS figure can be traced to the element and moment that caused it
// instead of being guessed at.
//
// Usage: node scripts/ui17-layout-shift-diagnostics.mjs --origin 127.0.0.1:8171 [--hosts servana.ke,branch.servana.ke]
//                                                     [--out <file>] [--cpu 4]

import { chromium } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const ORIGIN = arg('--origin', '127.0.0.1:8080');
const OUT = arg('--out', null);
const CPU = Number(arg('--cpu', '4'));
const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const domain = registry.domains.production;
const all = registry.accounts.map((a) => (a.subdomain === null ? domain : `${a.subdomain}.${domain}`));
const HOSTS = arg('--hosts', null)?.split(',') ?? all;

const OBSERVER = `
  window.__svShift = { shifts: [], fonts: [], marks: [] };
  const describe = (node) => {
    if (!node || node.nodeType !== 1) return node ? '#text' : null;
    const id = node.id ? '#' + node.id : '';
    const testid = node.getAttribute('data-testid') ? '[data-testid=' + node.getAttribute('data-testid') + ']' : '';
    const cls = (node.getAttribute('class') || '').split(/\\s+/).filter(Boolean).slice(0, 3).join('.');
    return node.tagName.toLowerCase() + id + testid + (cls ? '.' + cls : '');
  };
  new PerformanceObserver((list) => {
    for (const e of list.getEntries()) {
      window.__svShift.shifts.push({
        t: Math.round(e.startTime), v: Number(e.value.toFixed(4)), input: e.hadRecentInput,
        sources: (e.sources || []).map((s) => ({
          node: describe(s.node),
          prev: [Math.round(s.previousRect.x), Math.round(s.previousRect.y), Math.round(s.previousRect.width), Math.round(s.previousRect.height)],
          curr: [Math.round(s.currentRect.x), Math.round(s.currentRect.y), Math.round(s.currentRect.width), Math.round(s.currentRect.height)],
        })),
      });
    }
  }).observe({ type: 'layout-shift', buffered: true });
  if (document.fonts) {
    document.fonts.addEventListener('loadingdone', (event) => {
      for (const face of event.fontfaces) window.__svShift.fonts.push({ t: Math.round(performance.now()), family: face.family, weight: face.weight });
    });
  }
`;

const browser = await chromium.launch({ args: [`--host-resolver-rules=MAP ${domain} ${ORIGIN}, MAP *.${domain} ${ORIGIN}`] });
const report = [];
for (const host of HOSTS) {
  const context = await browser.newContext({ viewport: { width: 360, height: 780 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await context.addInitScript(OBSERVER);
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU });
  await page.goto(`http://${host}/`, { waitUntil: 'networkidle', timeout: 120_000 });
  await page.waitForTimeout(1500);
  const data = await page.evaluate(() => window.__svShift);
  const resources = await page.evaluate(() =>
    performance.getEntriesByType('resource').map((r) => ({ name: new URL(r.name).pathname, start: Math.round(r.startTime), end: Math.round(r.responseEnd) })),
  );
  await context.close();
  const total = data.shifts.filter((s) => !s.input).reduce((sum, s) => sum + s.v, 0);
  report.push({ host, total_unwindowed: Number(total.toFixed(4)), shifts: data.shifts, fonts: data.fonts, resources });
  console.log(`\n${host}  sum ${total.toFixed(4)}`);
  for (const shift of data.shifts) {
    console.log(`  t=${shift.t}ms v=${shift.v}${shift.input ? ' (input)' : ''}`);
    for (const source of shift.sources) console.log(`     ${source.node}  ${source.prev.join(',')} -> ${source.curr.join(',')}`);
  }
  console.log(`  fonts: ${data.fonts.map((f) => `${f.family}/${f.weight}@${f.t}`).join(' ')}`);
}
await browser.close();
if (OUT) {
  mkdirSync(dirname(resolve(ROOT, OUT)), { recursive: true });
  writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
}
