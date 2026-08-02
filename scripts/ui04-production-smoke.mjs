#!/usr/bin/env node
// Phase UI-04 — production-topology smoke.
//
// Proves the UI-04 deliverables against the BUILT production pair (nginx + PHP-FPM), not against
// the Vite preview origin: the web app manifest, both approved Android icons, the exact-case
// logo, the absence of Logo.svg, the Vite asset MIME, the pre-hydration theme bootstrap in the
// served Blade shell, the fixed footer's presence in the bundle, unknown-host denial, and the
// health probes.
//
// It only READS over HTTP. It creates and removes its own throwaway containers.
//
// Usage: node scripts/ui04-production-smoke.mjs <baseUrl> <accountHost>

import http from 'node:http';

const [, , BASE = 'http://localhost:8099', HOST = 'servana.test'] = process.argv;

/** @type {{name: string, ok: boolean, detail: string}[]} */
const observations = [];

function record(name, ok, detail) {
  observations.push({ name, ok, detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${detail === '' ? '' : ` — ${detail}`}`);
}

const { hostname: ORIGIN_HOST, port: ORIGIN_PORT } = new URL(BASE);

/**
 * Request a path with an EXPLICIT Host header.
 *
 * Uses node:http rather than fetch on purpose: `Host` is a forbidden header name for fetch, which
 * silently drops it — so every request would arrive as `localhost` and the host boundary would be
 * tested against the wrong value. UI-02's host smoke uses the same approach for the same reason.
 */
function get(path, { host = HOST } = {}) {
  return new Promise((resolve, reject) => {
    const request = http.request(
      { host: ORIGIN_HOST, port: ORIGIN_PORT, path, method: 'GET', headers: { Host: host } },
      (response) => {
        const chunks = [];
        response.on('data', (chunk) => chunks.push(chunk));
        response.on('end', () =>
          resolve({
            status: response.statusCode ?? 0,
            headers: { get: (name) => response.headers[name.toLowerCase()] ?? null },
            text: async () => Buffer.concat(chunks).toString('utf8'),
          }),
        );
      },
    );
    request.on('error', reject);
    request.end();
  });
}

async function main() {
  // ---- the SPA shell on a real account host -------------------------------
  const shell = await get('/');
  const shellBody = await shell.text();
  record('shell responds on an approved account host', shell.status === 200, `status ${shell.status}`);
  record(
    'shell links the web app manifest',
    shellBody.includes('href="/assets/brand/site.webmanifest"'),
    '',
  );
  record(
    'shell carries the UI-04 theme bootstrap',
    shellBody.includes("getAttribute('data-sv-theme')") && shellBody.includes("localStorage.getItem('servana.theme')"),
    '',
  );
  record(
    'shell never consults the operating system colour scheme (UI01-THEME-001)',
    !shellBody.includes('prefers-color-scheme') && !shellBody.includes('matchMedia'),
    '',
  );
  record('shell references no deleted Logo.svg', !shellBody.includes('Logo.svg'), '');

  // ---- the web app manifest and its icons (UI01-ASSET-003) ----------------
  const manifest = await get('/assets/brand/site.webmanifest');
  const manifestType = manifest.headers.get('content-type') ?? '';
  record('manifest serves 200', manifest.status === 200, `status ${manifest.status}`);
  record(
    'manifest serves as application/manifest+json',
    manifestType.includes('application/manifest+json'),
    manifestType,
  );

  const manifestBody = manifest.status === 200 ? JSON.parse(await manifest.text()) : { icons: [] };
  for (const icon of manifestBody.icons ?? []) {
    const response = await get(icon.src);
    const type = response.headers.get('content-type') ?? '';
    record(
      `manifest icon ${icon.src}`,
      response.status === 200 && type.includes('image/png'),
      `status ${response.status}, ${type}`,
    );
  }

  // ---- approved brand assets, exact case ---------------------------------
  const logo = await get('/assets/brand/Logo.png');
  record('Logo.png serves at its exact case', logo.status === 200, `status ${logo.status}`);

  const wrongCase = await get('/assets/brand/logo.png');
  record(
    'lowercase logo.png is NOT served (case sensitivity holds at the edge)',
    wrongCase.status === 404,
    `status ${wrongCase.status}`,
  );

  const svg = await get('/assets/brand/Logo.svg');
  record('Logo.svg remains absent', svg.status === 404, `status ${svg.status}`);

  // ---- the built SPA bundle ----------------------------------------------
  const entry = shellBody.match(/src="(\/spa-assets\/[^"]+\.js)"/)?.[1] ?? null;
  if (entry === null) {
    record('shell names a fingerprinted Vite entry', false, 'no /spa-assets/*.js in the shell');
  } else {
    const asset = await get(entry);
    const type = asset.headers.get('content-type') ?? '';
    record(
      `Vite entry ${entry} serves as JavaScript`,
      asset.status === 200 && /javascript/.test(type),
      `status ${asset.status}, ${type}`,
    );
  }

  const stylesheet = shellBody.match(/href="(\/spa-assets\/[^"]+\.css)"/)?.[1] ?? null;
  if (stylesheet !== null) {
    const css = await get(stylesheet);
    const cssBody = css.status === 200 ? await css.text() : '';
    record(
      `stylesheet ${stylesheet} serves as CSS`,
      css.status === 200 && (css.headers.get('content-type') ?? '').includes('text/css'),
      `status ${css.status}`,
    );
    record(
      'the built stylesheet carries the generated design tokens',
      cssBody.includes('--sv-color-brand-primary'),
      '',
    );
    record(
      'the built stylesheet carries the fixed-footer reserve contract',
      cssBody.includes('sv-footer-reserve') && cssBody.includes('--sv-footer-height-mobile'),
      '',
    );
    record(
      'the built stylesheet never consults the operating system colour scheme',
      !cssBody.includes('prefers-color-scheme'),
      '',
    );
  }

  // ---- no service worker --------------------------------------------------
  for (const path of ['/sw.js', '/service-worker.js']) {
    const response = await get(path);
    const type = response.headers.get('content-type') ?? '';
    // These paths fall through to the SPA shell, which returns HTML. HTML cannot register as a
    // service worker, so the property to assert is that no JAVASCRIPT is served there — not that
    // the path 404s.
    record(
      `no service worker script at ${path}`,
      !/javascript/.test(type),
      `status ${response.status}, ${type}`,
    );
  }

  // ---- host boundary (UI-02, unchanged by UI-04) --------------------------
  // The UI-02 default server closes the connection without a response body, so a transport-level
  // reset IS the denial. Treating it as a failure would misreport the boundary as broken.
  let denial = 'connection closed without a response';
  try {
    const unknown = await get('/', { host: 'not-a-servana-host.example' });
    denial = `status ${unknown.status}`;
    record(
      'an unapproved host is denied',
      unknown.status === 421 || unknown.status === 404 || unknown.status === 444,
      denial,
    );
  } catch (error) {
    record('an unapproved host is denied', error.code === 'ECONNRESET', denial);
  }

  // ---- probes -------------------------------------------------------------
  for (const path of ['/health', '/health/host']) {
    const response = await get(path);
    record(`${path} responds`, response.status === 200, `status ${response.status}`);
  }

  const failed = observations.filter((o) => !o.ok);
  console.log(`\n${observations.length} observations, ${failed.length} failures.`);

  if (failed.length > 0) {
    process.exit(1);
  }
}

main().catch((error) => {
  console.error('smoke failed to run:', error);
  process.exit(1);
});
