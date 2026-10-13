#!/usr/bin/env node
// Phase UI-17 — lab Web Vitals profile for the eight public landing pages (UI/UX plan §22.1).
//
// Drives a real Chromium at a SERVED origin (the built nginx + php image pair, never a Vite preview)
// with every canonical account host resolved to that origin, exactly like the UI-16 production-origin
// gate. For each host and profile it takes N cold samples (fresh browser context, empty cache) and
// one warm sample (same context, second navigation), and records:
//
//   TTFB, FCP, LCP (+ the LCP element), CLS (largest session window, Layout Instability spec),
//   an INP SURROGATE (Event Timing duration of a real click on the theme toggle), request count and
//   transferred/decoded bytes by resource type.
//
// HONESTY RULES baked into the output:
//   - These are LAB values under DevTools-applied throttling. They are never labelled field p75.
//   - p75 here is the nearest-rank 75th percentile of N lab samples, recorded as such.
//   - The INP figure is a single scripted interaction, classified `lab_surrogate`; authentic INP
//     needs field data from real users and stays an open gate.
//
// Usage:
//   node scripts/ui17-web-vitals.mjs --origin 127.0.0.1:8117 [--samples 5] [--profile all]
//                                    [--label after] [--out <report.json>] [--hosts production|local]

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
const SAMPLES = Number(arg('--samples', '5'));
const PROFILE_FILTER = arg('--profile', 'all');
const LABEL = arg('--label', 'run');
const OUT = arg('--out', null);
const HOST_SET = arg('--hosts', 'production');

/**
 * Throttling profiles. `mobile-slow-4g` mirrors the Lighthouse mobile preset values (150 ms RTT,
 * 1.6 Mbit/s down, 750 kbit/s up, 4x CPU slowdown) but APPLIED by DevTools per request rather than
 * simulated — recorded in the report so nobody compares the two as if they were the same method.
 */
const PROFILES = {
  'mobile-slow-4g': {
    viewport: { width: 360, height: 780 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    network: { latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 },
    cpuSlowdown: 4,
  },
  // Chrome DevTools' "Fast 4G" preset (9 Mbit/s down, 1.5 Mbit/s up, 60 ms RTT) with the same 4x CPU
  // slowdown: a typical urban 4G connection on a mid-range phone. Measured so the product owner can
  // choose the AGREED p75 profile (UI/UX plan §22.1) from evidence rather than from one extreme.
  'mobile-fast-4g': {
    viewport: { width: 360, height: 780 },
    deviceScaleFactor: 2,
    isMobile: true,
    hasTouch: true,
    network: { latency: 60, downloadThroughput: (9 * 1024 * 1024) / 8, uploadThroughput: (1.5 * 1024 * 1024) / 8 },
    cpuSlowdown: 4,
  },
  'desktop-cable': {
    viewport: { width: 1440, height: 900 },
    deviceScaleFactor: 1,
    isMobile: false,
    hasTouch: false,
    network: { latency: 28, downloadThroughput: (5 * 1024 * 1024) / 8, uploadThroughput: (1 * 1024 * 1024) / 8 },
    cpuSlowdown: 1,
  },
};

const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const domain = HOST_SET === 'local' ? registry.domains.local : registry.domains.production;
const HOSTS = registry.accounts.map((account) => ({
  key: account.account_key,
  host: account.subdomain === null ? domain : `${account.subdomain}.${domain}`,
}));

/** Installed before any page script: buffers LCP, layout shifts and event timings. */
const OBSERVER_SCRIPT = `
  window.__svVitals = { lcp: [], shifts: [], events: [] };
  try {
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) {
        const el = e.element;
        window.__svVitals.lcp.push({
          t: e.startTime, size: e.size,
          tag: el ? el.tagName.toLowerCase() : null,
          src: e.url || null,
          text: el && !e.url ? (el.textContent || '').trim().slice(0, 60) : null,
        });
      }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) window.__svVitals.shifts.push({ t: e.startTime, v: e.value, input: e.hadRecentInput });
    }).observe({ type: 'layout-shift', buffered: true });
    new PerformanceObserver((list) => {
      for (const e of list.getEntries()) if (e.interactionId) window.__svVitals.events.push({ name: e.name, d: e.duration, id: e.interactionId });
    }).observe({ type: 'event', buffered: true, durationThreshold: 16 });
  } catch (error) { window.__svVitals.error = String(error); }
`;

/** Largest session window (1 s gap, 5 s cap) — the CLS definition, not a plain sum. */
function cumulativeLayoutShift(shifts) {
  let max = 0;
  let current = 0;
  let windowStart = -Infinity;
  let last = -Infinity;
  for (const shift of shifts.filter((s) => !s.input).sort((a, b) => a.t - b.t)) {
    if (shift.t - last > 1000 || shift.t - windowStart > 5000) {
      current = 0;
      windowStart = shift.t;
    }
    current += shift.v;
    last = shift.t;
    max = Math.max(max, current);
  }
  return Number(max.toFixed(4));
}

const percentile = (values, p) => {
  const sorted = [...values].sort((a, b) => a - b);
  return sorted[Math.max(0, Math.ceil((p / 100) * sorted.length) - 1)];
};
const median = (values) => percentile(values, 50);

async function sample(browser, profile, host, warm) {
  const context = await browser.newContext({
    viewport: profile.viewport,
    deviceScaleFactor: profile.deviceScaleFactor,
    isMobile: profile.isMobile,
    hasTouch: profile.hasTouch,
    reducedMotion: 'no-preference',
  });
  await context.addInitScript(OBSERVER_SCRIPT);
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  await cdp.send('Network.emulateNetworkConditions', { offline: false, ...profile.network });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: profile.cpuSlowdown });

  const errors = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) errors.push(`console: ${message.text()}`);
  });

  const url = `http://${host}/`;
  if (warm) {
    await page.goto(url, { waitUntil: 'networkidle' });
    await page.evaluate(() => {
      window.__svVitals = { lcp: [], shifts: [], events: [] };
    });
  }
  await page.goto(url, { waitUntil: 'networkidle', timeout: 120_000 });
  await page.locator('h1').first().waitFor({ state: 'visible', timeout: 60_000 });
  await page.waitForTimeout(1500);

  // INP surrogate: one real click on the theme toggle (a reflow of the whole page), then back.
  let interaction = null;
  const toggle = page.getByRole('button', { name: /theme|dark|light/i }).first();
  if (await toggle.isVisible().catch(() => false)) {
    await toggle.click();
    await page.waitForTimeout(400);
    await toggle.click();
    await page.waitForTimeout(600);
  }

  const data = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const paint = performance.getEntriesByType('paint').find((p) => p.name === 'first-contentful-paint');
    const resources = performance.getEntriesByType('resource').map((r) => ({
      name: new URL(r.name).pathname,
      type: r.initiatorType,
      transfer: r.transferSize,
      encoded: r.encodedBodySize,
      decoded: r.decodedBodySize,
    }));
    return {
      ttfb: nav ? nav.responseStart : null,
      fcp: paint ? paint.startTime : null,
      domContentLoaded: nav ? nav.domContentLoadedEventEnd : null,
      htmlTransfer: nav ? nav.transferSize : null,
      vitals: window.__svVitals,
      resources,
      dark: document.documentElement.classList.contains('dark'),
    };
  });
  await context.close();

  const lcp = data.vitals.lcp.at(-1) ?? null;
  const byInteraction = new Map();
  for (const event of data.vitals.events) byInteraction.set(event.id, Math.max(byInteraction.get(event.id) ?? 0, event.d));
  interaction = byInteraction.size > 0 ? Math.max(...byInteraction.values()) : null;

  const totals = { requests: data.resources.length + 1, transfer: data.htmlTransfer ?? 0, decoded: 0, js_transfer: 0, css_transfer: 0, img_transfer: 0, font_transfer: 0 };
  for (const r of data.resources) {
    totals.transfer += r.transfer;
    totals.decoded += r.decoded;
    if (r.name.endsWith('.js')) totals.js_transfer += r.transfer;
    else if (r.name.endsWith('.css')) totals.css_transfer += r.transfer;
    else if (/\.(avif|webp|png|jpe?g|svg|ico)$/.test(r.name)) totals.img_transfer += r.transfer;
    else if (/\.(woff2?|ttf)$/.test(r.name)) totals.font_transfer += r.transfer;
  }
  // Image bytes actually fetched for each <img>, to catch a full-size original loaded into a small slot.
  const images = data.resources.filter((r) => /\.(avif|webp|png|jpe?g)$/.test(r.name)).map((r) => ({ path: r.name, transfer: r.transfer, decoded: r.decoded }));

  return {
    ttfb_ms: Math.round(data.ttfb ?? 0),
    fcp_ms: Math.round(data.fcp ?? 0),
    lcp_ms: lcp ? Math.round(lcp.t) : null,
    lcp_element: lcp ? { tag: lcp.tag, src: lcp.src ? new URL(lcp.src).pathname : null, text: lcp.text } : null,
    cls: cumulativeLayoutShift(data.vitals.shifts),
    inp_surrogate_ms: interaction === null ? null : Math.round(interaction),
    totals,
    images,
    light_first_paint: data.dark === false,
    errors,
    observer_error: data.vitals.error ?? null,
  };
}

const browser = await chromium.launch({
  args: [`--host-resolver-rules=MAP ${domain} ${ORIGIN}, MAP *.${domain} ${ORIGIN}`, '--disable-features=HttpsUpgrades'],
});
const version = browser.version();
const results = [];
for (const [profileName, profile] of Object.entries(PROFILES)) {
  if (PROFILE_FILTER !== 'all' && PROFILE_FILTER !== profileName) continue;
  for (const { key, host } of HOSTS) {
    const cold = [];
    for (let i = 0; i < SAMPLES; i += 1) cold.push(await sample(browser, profile, host, false));
    const warm = await sample(browser, profile, host, true);
    const series = (field) => cold.map((s) => s[field]).filter((v) => v !== null);
    const summary = {
      profile: profileName,
      account_key: key,
      host,
      samples: SAMPLES,
      cold: {
        ttfb_ms: { median: median(series('ttfb_ms')), p75_lab: percentile(series('ttfb_ms'), 75) },
        fcp_ms: { median: median(series('fcp_ms')), p75_lab: percentile(series('fcp_ms'), 75) },
        lcp_ms: { median: median(series('lcp_ms')), p75_lab: percentile(series('lcp_ms'), 75), min: Math.min(...series('lcp_ms')), max: Math.max(...series('lcp_ms')) },
        cls: { median: median(series('cls')), p75_lab: percentile(series('cls'), 75), max: Math.max(...series('cls')) },
        inp_surrogate_ms: series('inp_surrogate_ms').length > 0 ? { median: median(series('inp_surrogate_ms')), max: Math.max(...series('inp_surrogate_ms')) } : null,
        lcp_elements: [...new Set(cold.map((s) => JSON.stringify(s.lcp_element)))].map((s) => JSON.parse(s)),
        totals_first_sample: cold[0].totals,
        images_first_sample: cold[0].images,
      },
      warm: { lcp_ms: warm.lcp_ms, cls: warm.cls, transfer: warm.totals.transfer, requests: warm.totals.requests },
      light_first_paint: cold.every((s) => s.light_first_paint),
      errors: [...new Set(cold.flatMap((s) => s.errors).concat(warm.errors))],
    };
    results.push(summary);
    console.log(
      `${profileName.padEnd(15)} ${host.padEnd(20)} LCP med ${String(summary.cold.lcp_ms.median).padStart(5)} p75 ${String(summary.cold.lcp_ms.p75_lab).padStart(5)}  ` +
        `CLS ${summary.cold.cls.max}  INP~ ${summary.cold.inp_surrogate_ms?.max ?? '-'}  ` +
        `xfer ${(summary.cold.totals_first_sample.transfer / 1024).toFixed(0)}KiB/${summary.cold.totals_first_sample.requests}req  ` +
        `LCP el ${summary.cold.lcp_elements.map((e) => e?.tag ?? '-').join('|')}  errors ${summary.errors.length}`,
    );
  }
}
await browser.close();

const report = {
  generated_by: 'scripts/ui17-web-vitals.mjs',
  label: LABEL,
  origin: ORIGIN,
  hosts: HOST_SET,
  chromium: version,
  method: {
    throttling: 'DevTools-applied (Network.emulateNetworkConditions + Emulation.setCPUThrottlingRate), not Lighthouse simulation',
    profiles: PROFILES,
    cold: 'fresh browser context per sample (empty HTTP cache, empty storage)',
    warm: 'second navigation in the same context (HTTP cache primed)',
    p75: 'nearest-rank 75th percentile of the N LAB samples — not field p75',
    cls: 'largest session window (1 s gap, 5 s cap), shifts after recent input excluded',
    inp: 'lab_surrogate: max Event Timing duration of a scripted theme-toggle click; authentic INP requires field data',
  },
  results,
};
if (OUT) {
  mkdirSync(dirname(resolve(ROOT, OUT)), { recursive: true });
  writeFileSync(resolve(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
}
