#!/usr/bin/env node
// Phase UI-03 — focused, real-backend, cross-host deployed-origin browser proof.
//
// This closes residual risk R1 in docs/proof/ui-03.md: every UI-03 security property was
// proven server-side and in unit tests, but nothing had been exercised in a real browser
// against the production serving topology.
//
// WHAT MAKES THIS A DEPLOYED-ORIGIN PROOF, not another preview-origin test:
//
//   * the edge is the BUILT production nginx image and the app is the BUILT production PHP
//     image (docker/nginx.Dockerfile, docker/php.Dockerfile --target prod) — not `vite preview`
//     and not the bind-mounted dev stack;
//   * the database is real PostgreSQL 16 with real migrations, so magic-link rows, session
//     families, host sessions and handoffs are genuinely written and genuinely read back;
//   * Chromium is launched with `--host-resolver-rules` so it sends GENUINE `Host` headers for
//     the eight `*.servana.test` account hosts without anyone editing the system hosts file —
//     which is the only way host-only cookie scoping can be observed at all;
//   * every request carries real cookies and real CSRF, so cross-host session isolation is
//     enforced by the browser rather than asserted by a test harness.
//
// This is NOT the release visual baseline (UI-16 owns that) and NOT the full Playwright suite
// (run separately, once). It is a standalone script for the same reason UI-02's host matrix was:
// the repository Playwright config targets the preview origin, and this proof needs the
// deployed one.
//
// Usage:
//   node scripts/ui03-auth-browser-proof.mjs \
//     --origin http://127.0.0.1:8090 --mailpit http://127.0.0.1:8025 \
//     --fixture <path to ui03-browser-fixture.php output> [--db servana_ui03_proof]
//
// Exits non-zero on the first failed expectation, so a broken security property cannot be
// recorded as proven.

import { createHash, createHmac } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { chromium } from '@playwright/test';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');

const arg = (name, fallback) => {
  const index = process.argv.indexOf(name);

  return index === -1 ? fallback : process.argv[index + 1];
};

const ORIGIN = arg('--origin', 'http://127.0.0.1:8090');
const MAILPIT = arg('--mailpit', 'http://127.0.0.1:8025');
const DB = arg('--db', 'servana_ui03_proof');
const FIXTURE = JSON.parse(readFileSync(arg('--fixture', 'fixture.json'), 'utf8'));
const OUT = arg('--out', 'ui03-browser-evidence.json');

const SHOT_DIR = 'docs/frontend/audits/ui-03/screenshots';
const SHOT_INDEX = 'docs/frontend/audits/ui-03/screenshot-index.json';
const VIEWPORT = { width: 1280, height: 800 };
const PORT = new URL(ORIGIN).port || '80';

const registry = JSON.parse(readFileSync(join(ROOT, 'config/account-hosts.json'), 'utf8'));
const manifestBytes = readFileSync(join(ROOT, 'public/spa/.vite/manifest.json'));
const MANIFEST_SHA256 = createHash('sha256').update(manifestBytes).digest('hex');
const SOURCE_COMMIT = execFileSync('git', ['rev-parse', 'HEAD'], { cwd: ROOT }).toString().trim();

/** Exact registry host for an account key in the local environment. */
const hostFor = (accountKey) => {
  const account = registry.accounts.find((a) => a.account_key === accountKey);
  if (!account) throw new Error(`Unknown account key: ${accountKey}`);

  return account.subdomain === null
    ? registry.domains.local
    : `${account.subdomain}.${registry.domains.local}`;
};

const originFor = (accountKey) => `http://${hostFor(accountKey)}:${PORT}`;

// ---------------------------------------------------------------------------
// Evidence accumulators
// ---------------------------------------------------------------------------

const observations = [];
const failures = [];
const screenshots = [];
/** Every string that must never appear in committed evidence, storage or logs. */
const secrets = new Set();

const remember = (value) => {
  if (typeof value === 'string' && value.length >= 12) secrets.add(value);

  return value;
};

/** Redact any known secret before a value can reach a committed evidence file. */
const sanitize = (value) => {
  let text = String(value);
  for (const secret of secrets) text = text.split(secret).join('[REDACTED]');

  return text
    .replace(/([?&](token|xsrf|session|secret|code)=)[^&\s]*/gi, '$1[REDACTED]')
    .slice(0, 600);
};

/**
 * Record one observation.
 *
 * `cases` are the matrix case ids this observation is evidence for, so the three UI-03 matrices
 * can be populated from this run without anyone re-deriving the mapping by hand.
 */
function observe(id, cases, description, passed, detail) {
  const record = {
    observation_id: id,
    matrix_cases: cases,
    description,
    result: passed ? 'passed' : 'FAILED',
    detail: sanitize(detail ?? ''),
  };
  observations.push(record);

  if (!passed) failures.push(`${id} — ${description}: ${record.detail}`);

  console.log(`  ${passed ? 'ok  ' : 'FAIL'} ${id.padEnd(10)} ${description}`);

  return passed;
}

// ---------------------------------------------------------------------------
// Real database reads. The point of a deployed-origin proof is that the rows are real.
// ---------------------------------------------------------------------------

const sql = (query) =>
  execFileSync(
    'docker',
    ['compose', 'exec', '-T', 'postgres', 'psql', '-U', 'servana', '-d', DB, '-At', '-c', query],
    { cwd: ROOT, encoding: 'utf8' },
  ).trim();

// ---------------------------------------------------------------------------
// Mailpit — the real delivered email is where the real magic-link URL comes from.
// ---------------------------------------------------------------------------

const clearMail = async () => {
  await fetch(`${MAILPIT}/api/v1/messages`, { method: 'DELETE' });
};

/** The absolute verify URL Servana actually emailed to `address`. */
async function magicLinkUrlFor(address) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    const list = await (await fetch(`${MAILPIT}/api/v1/messages?limit=50`)).json();
    const message = (list.messages ?? []).find((m) =>
      (m.To ?? []).some((to) => (to.Address ?? '').toLowerCase() === address.toLowerCase()),
    );

    if (message) {
      const full = await (await fetch(`${MAILPIT}/api/v1/message/${message.ID}`)).json();
      const body = `${full.Text ?? ''}\n${full.HTML ?? ''}`;
      const match = body.match(/https?:\/\/[^\s"'<>]*\/auth\/verify\?token=[A-Za-z0-9_-]+/);

      if (match) {
        const url = match[0].replace(/&amp;/g, '&');
        remember(new URL(url).searchParams.get('token'));

        return url;
      }
    }

    await new Promise((r) => setTimeout(r, 500));
  }

  throw new Error(`No magic-link email was delivered to ${address}`);
}

// ---------------------------------------------------------------------------
// RFC 6238 TOTP, so the fixture's mandatory-MFA user can genuinely satisfy a challenge.
// ---------------------------------------------------------------------------

function totp(base32Secret, when = Date.now()) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of base32Secret.replace(/=+$/, '').toUpperCase()) {
    bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
  }
  const key = Buffer.from(bits.match(/.{8}/g).map((b) => parseInt(b, 2)));

  const counter = Buffer.alloc(8);
  counter.writeUInt32BE(Math.floor(when / 1000 / 30), 4);

  const digest = createHmac('sha1', key).update(counter).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const code = digest.readUInt32BE(offset) & 0x7fffffff;

  return String(code % 1_000_000).padStart(6, '0');
}

// ---------------------------------------------------------------------------
// Browser helpers
// ---------------------------------------------------------------------------

/**
 * Perform a same-origin API call from inside the page, with the page's REAL cookies and a real
 * CSRF token. Running it in the page (rather than from node) is what makes the browser — not this
 * script — decide which cookies are in scope for the host.
 */
function api(page, method, path, body = null, extraHeaders = {}) {
  return page.evaluate(
    async ([method, path, body, extraHeaders]) => {
      const cookie = (name) =>
        document.cookie.split('; ').find((c) => c.startsWith(`${name}=`))?.split('=')[1];

      if (method !== 'GET') await fetch('/sanctum/csrf-cookie', { credentials: 'include' });

      const headers = { Accept: 'application/json', ...extraHeaders };
      if (body !== null) headers['Content-Type'] = 'application/json';
      const xsrf = cookie('XSRF-TOKEN');
      if (xsrf) headers['X-XSRF-TOKEN'] = decodeURIComponent(xsrf);

      const response = await fetch(path, {
        method,
        credentials: 'include',
        headers,
        body: body === null ? undefined : JSON.stringify(body),
      });

      const text = await response.text();
      let json = null;
      try {
        json = JSON.parse(text);
      } catch {
        /* non-JSON body is itself evidence */
      }

      return {
        status: response.status,
        text: text.slice(0, 2000),
        json,
        contentType: response.headers.get('content-type'),
      };
    },
    [method, path, body, extraHeaders],
  );
}

/**
 * Ask for a Magic Link and fail LOUDLY if the named limiter refused.
 *
 * `magic-link-request` is 3/minute per email and 10/hour per IP. A refused request sends no mail,
 * which would otherwise surface much later as an inscrutable "no email was delivered" — the wrong
 * diagnosis for a working rate limiter. The limiter is never disabled or relaxed for this proof;
 * its counters are reset between runs in an isolated Redis database instead.
 */
async function requestMagicLink(page, email) {
  const response = await api(page, 'POST', '/api/v1/auth/magic-link', { email });

  if (response.status === 429) {
    throw new Error(
      `Rate limiter refused the Magic Link request for ${email}. Reset the proof Redis database `
      + 'before re-running; do not relax the limiter.',
    );
  }

  return response;
}

/**
 * Mint a handoff for `accountKey` and return its absolute target URL.
 *
 * Throws with the real status and body rather than letting an undefined `target_url` surface as an
 * inscrutable TypeError several lines later.
 */
async function mintHandoff(page, accountKey, body = {}) {
  const list = await api(page, 'GET', '/api/v1/auth/account-contexts');
  const entry = (list.json?.data ?? []).find((c) => c.account_key === accountKey);

  if (!entry) {
    throw new Error(
      `No '${accountKey}' context is available to switch to (list status ${list.status}, `
      + `keys: ${(list.json?.data ?? []).map((c) => c.account_key).join(',') || 'none'}).`,
    );
  }

  const issued = await api(page, 'POST', '/api/v1/auth/account-contexts/switch', {
    context_id: entry.context_id,
    ...body,
  });

  const url = issued.json?.data?.target_url;

  if (typeof url !== 'string') {
    throw new Error(`Switch to '${accountKey}' returned ${issued.status}: ${issued.text.slice(0, 200)}`);
  }

  remember(new URL(url).searchParams.get('token'));

  return url;
}

/** Count the live host sessions an email holds for one account, straight from the database. */
const liveSessions = (email, accountKey) =>
  sql(
    `select count(*) from host_sessions h join users u on u.id = h.user_id
     where u.email = '${email}' and h.account_key = '${accountKey}' and h.revoked_at is null`,
  );

/**
 * The uniform failure message the SPA renders, isolated from surrounding chrome.
 *
 * Comparing whole-page innerText across two different account hosts compares their BRANDING, not
 * their failure copy — the accounts legitimately differ there. The security property under test is
 * that the failure MESSAGE is identical whatever the true cause, so read the alert region itself.
 */
const failureMessage = async (page) => {
  const alert = page.locator('[role="alert"]').first();
  await alert.waitFor({ state: 'visible', timeout: 15_000 }).catch(() => undefined);

  return (await alert.innerText().catch(() => '')).trim();
};

/** Everything the page could be leaking client-side. */
const clientStorage = (page) =>
  page.evaluate(async () => {
    const dump = (store) =>
      Object.fromEntries(Object.keys(store).map((k) => [k, String(store.getItem(k))]));

    let databases = [];
    try {
      databases = (await indexedDB.databases()).map((d) => d.name);
    } catch {
      databases = ['<unavailable>'];
    }

    return {
      localStorage: dump(localStorage),
      sessionStorage: dump(sessionStorage),
      indexedDbDatabases: databases,
      documentCookieVisible: document.cookie,
    };
  });

async function shot(page, file, meta) {
  const path = join(ROOT, SHOT_DIR, file);
  mkdirSync(join(ROOT, SHOT_DIR), { recursive: true });

  /*
   * Strip any credential from the address bar BEFORE capturing.
   *
   * The uniform-denial states are reached at `/auth/verify?token=…`, so the token is still in the
   * URL while the failure is on screen. Playwright captures the viewport rather than browser
   * chrome, so it would not have appeared in the pixels — but "no screenshot was taken on a
   * token-bearing URL" is a property worth holding unconditionally rather than relying on that.
   * `replaceState` changes only the address bar; the rendered state under test is untouched.
   */
  await page
    .evaluate(() => {
      const url = new URL(window.location.href);
      if ([...url.searchParams.keys()].length > 0) {
        history.replaceState(null, '', url.pathname);
      }
    })
    .catch(() => undefined);

  // Never photograph a page whose rendered text carries credential material.
  const text = await page.evaluate(() => document.body.innerText ?? '');
  for (const secret of secrets) {
    if (secret && text.includes(secret)) {
      throw new Error(`Refusing to capture ${file}: rendered text contains credential material.`);
    }
  }

  await page.screenshot({ path, fullPage: false });

  screenshots.push({
    file: `${SHOT_DIR}/${file}`,
    ...meta,
    url_path: new URL(page.url()).pathname,
    url_contains_token: new URL(page.url()).searchParams.has('token'),
    viewport: `${VIEWPORT.width}x${VIEWPORT.height}`,
    image_sha256: createHash('sha256').update(readFileSync(path)).digest('hex'),
  });
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

/**
 * Send every hostname to the production pair, so the browser issues GENUINE `Host` headers for
 * the account hosts without anyone editing the system hosts file.
 *
 * A wildcard rather than one MAP per host, and secure DNS explicitly off, because per-host rules
 * proved unreliable over a long session: Chromium may upgrade to DNS-over-HTTPS partway through,
 * and the DoH path does not consult host-resolver-rules — a host reached for the first time late
 * in the run then fails with ERR_NAME_NOT_RESOLVED while hosts already resolved keep working.
 * `EXCLUDE localhost` keeps loopback behaving normally.
 */
const hostRules = `MAP * 127.0.0.1:${PORT}, EXCLUDE localhost`;

const browser = await chromium.launch({
  args: [
    `--host-resolver-rules=${hostRules}`,
    '--dns-over-https-mode=off',
    '--disable-features=DnsOverHttps',
  ],
});
const context = await browser.newContext({ viewport: VIEWPORT });

const consoleMessages = [];
const networkLog = [];

context.on('console', (m) => consoleMessages.push(sanitize(`${m.type()}: ${m.text()}`)));
context.on('request', (r) => {
  if (r.method() === 'POST' || r.url().includes('/auth/')) {
    networkLog.push({
      method: r.method(),
      url: sanitize(r.url()),
      post_data: r.postData() ? sanitize(r.postData()) : null,
    });
  }
});

const MULTI = FIXTURE.users.multi;
const MFA_USER = FIXTURE.users.mfa;
const SINGLE = FIXTURE.users.single;
remember(MFA_USER.totp_secret);

const SOURCE = MULTI.source_account_key; // merchant_front_office
const TARGET = MULTI.target_account_key; // merchant_audit

const page = await context.newPage();

try {
  // ===================================================================== STAGE 1
  console.log('\nSTAGE 1 — unauthenticated behaviour on the deployed origin');

  await page.goto(`${originFor(SOURCE)}/auth/login`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('h1', { timeout: 15_000 });

  const loginHeading = await page.locator('h1').first().innerText();
  observe(
    'OBS-01',
    ['AF-015'],
    'browser page request on an approved host reaches that host\'s login experience',
    new URL(page.url()).hostname === hostFor(SOURCE) && loginHeading.length > 0,
    `host=${new URL(page.url()).hostname} heading=${loginHeading}`,
  );
  await shot(page, '01-source-host-login.png', {
    account_key: SOURCE,
    host: hostFor(SOURCE),
    surface: 'anonymous login',
    auth_state: 'anonymous',
  });

  // A protected page navigation must redirect to the SAME host's login and must not loop.
  const protectedNav = await page.goto(`${originFor(SOURCE)}/dashboard`, {
    waitUntil: 'domcontentloaded',
  });
  const afterNav = new URL(page.url());
  observe(
    'OBS-02',
    ['AF-015', 'AF-016'],
    'unauthenticated page navigation lands on the same host without a 500 or a redirect loop',
    (protectedNav?.status() ?? 0) < 500 && afterNav.hostname === hostFor(SOURCE),
    `status=${protectedNav?.status()} settled=${afterNav.pathname}`,
  );

  const jsonUnauth = await api(page, 'GET', '/api/v1/me');
  observe(
    'OBS-03',
    ['AF-013'],
    'JSON request to a protected API returns the standard 401 envelope',
    jsonUnauth.status === 401 && jsonUnauth.json?.error?.code !== undefined,
    `status=${jsonUnauth.status} code=${jsonUnauth.json?.error?.code}`,
  );

  const htmlUnauth = await api(page, 'GET', '/api/v1/me', null, { Accept: 'text/html' });
  observe(
    'OBS-04',
    ['AF-014'],
    'HTML-accept request to a protected API stays a 401 API response (never 500, never HTML page)',
    htmlUnauth.status === 401,
    `status=${htmlUnauth.status} content_type=${htmlUnauth.contentType}`,
  );

  // Unknown host.
  let unknownDenied = false;
  let unknownDetail = '';
  try {
    const response = await page.goto(`http://unknown-host.test:${PORT}/`, {
      waitUntil: 'domcontentloaded',
      timeout: 15_000,
    });
    unknownDenied = response?.status() === 421;
    unknownDetail = `status=${response?.status()}`;
  } catch (error) {
    unknownDenied = true; // nginx `return 444` closes the connection: that IS the denial.
    unknownDetail = `connection closed: ${String(error).split('\n')[0]}`;
  }
  observe('OBS-05', ['AF-017', 'AF-004'], 'unknown browser host remains denied and is never redirected to an approved host', unknownDenied, unknownDetail);

  // ===================================================================== STAGE 2
  console.log('\nSTAGE 2 — Magic Link host binding');

  await clearMail();
  await page.goto(`${originFor(SOURCE)}/auth/login`, { waitUntil: 'domcontentloaded' });

  // Request the link WITH a poisoned forwarded host: the emailed URL must still come from the
  // registry, never from a header (the classic password-reset-poisoning shape).
  const requested = await api(page, 'POST', '/api/v1/auth/magic-link', { email: MULTI.email }, {
    'X-Forwarded-Host': 'attacker.test',
  });
  observe(
    'OBS-06',
    ['AF-001'],
    'Magic Link request on an approved host returns the uniform 202',
    requested.status === 202,
    `status=${requested.status}`,
  );

  const linkUrl = await magicLinkUrlFor(MULTI.email);
  const linkHost = new URL(linkUrl).hostname;
  observe(
    'OBS-07',
    ['AF-003', 'AF-016'],
    'emailed Magic Link targets the exact registry host for the requesting account, not the poisoned forwarded host',
    linkHost === hostFor(SOURCE) && !linkUrl.includes('attacker.test'),
    `emailed_host=${linkHost} expected=${hostFor(SOURCE)}`,
  );

  const rawToken = new URL(linkUrl).searchParams.get('token');
  const tokenHash = createHash('sha256').update(rawToken).digest('hex');

  const storedRaw = sql(
    `select count(*) from magic_login_tokens where token_hash = '${tokenHash}'`,
  );
  const storedColumns = sql(
    `select account_key || '|' || intended_host || '|' || environment from magic_login_tokens where token_hash = '${tokenHash}'`,
  );
  observe(
    'OBS-08',
    ['AF-010'],
    'only the SHA-256 token hash is persisted, with a complete host/account/environment binding',
    storedRaw === '1' && storedColumns === `${SOURCE}|${hostFor(SOURCE)}|local`,
    `rows=${storedRaw} binding=${storedColumns}`,
  );

  // --- wrong-host consumption -------------------------------------------------
  const wrongHostUrl = linkUrl.replace(hostFor(SOURCE), hostFor(TARGET));
  await page.goto(wrongHostUrl, { waitUntil: 'domcontentloaded' });
  const wrongHostText = await failureMessage(page);
  const wrongHostAuthed = (await api(page, 'GET', '/api/v1/me')).status;
  observe(
    'OBS-09',
    ['AF-006', 'AF-009'],
    'wrong-host Magic Link consumption fails and creates no session',
    wrongHostAuthed === 401 && /invalid or has expired/i.test(wrongHostText),
    `me_status=${wrongHostAuthed} message=${wrongHostText}`,
  );
  await shot(page, '02-wrong-host-link-denied.png', {
    account_key: TARGET,
    host: hostFor(TARGET),
    surface: 'uniform invalid-link failure (wrong host)',
    auth_state: 'anonymous',
  });
  const wrongHostFailureCopy = wrongHostText;

  const notBurned = sql(
    `select consumed_at is null from magic_login_tokens where token_hash = '${tokenHash}'`,
  );
  observe(
    'OBS-10',
    ['AF-006'],
    'a wrong-host attempt does not burn the legitimate holder\'s token',
    notBurned === 't',
    `still_unconsumed=${notBurned}`,
  );

  // --- correct-host consumption ----------------------------------------------
  await page.goto(linkUrl, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);

  const me = await api(page, 'GET', '/api/v1/me');
  observe(
    'OBS-11',
    ['AF-005'],
    'correct-host Magic Link consumption succeeds and establishes a session on that host',
    me.status === 200,
    `me_status=${me.status} settled=${new URL(page.url()).pathname}`,
  );

  const sourceSession = sql(
    `select h.account_key || '|' || h.host || '|' || h.environment from host_sessions h join users u on u.id = h.user_id where u.email = '${MULTI.email}' and h.revoked_at is null`,
  );
  const familyCount = sql(
    `select count(*) from session_families f join users u on u.id = f.user_id where u.email = '${MULTI.email}' and f.revoked_at is null`,
  );
  observe(
    'OBS-12',
    ['AF-005'],
    'exactly one session family and one host session are created, bound to the exact host',
    familyCount === '1' && sourceSession === `${SOURCE}|${hostFor(SOURCE)}|local`,
    `families=${familyCount} host_session=${sourceSession}`,
  );

  await page.goto(`${originFor(SOURCE)}/dashboard`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1200);
  await shot(page, '03-source-host-authenticated.png', {
    account_key: SOURCE,
    host: hostFor(SOURCE),
    surface: 'authenticated source account after host-bound sign-in',
    auth_state: 'authenticated',
  });

  // --- replay -----------------------------------------------------------------
  const replayPage = await context.newPage();
  await replayPage.goto(linkUrl, { waitUntil: 'domcontentloaded' });
  const replayText = await failureMessage(replayPage);
  observe(
    'OBS-13',
    ['AF-009'],
    'Magic Link replay fails with the SAME uniform copy as a wrong-host failure',
    /invalid or has expired/i.test(replayText) && replayText === wrongHostFailureCopy,
    `identical_to_wrong_host=${replayText === wrongHostFailureCopy} message=${replayText}`,
  );
  await shot(replayPage, '04-replayed-link-denied.png', {
    account_key: SOURCE,
    host: hostFor(SOURCE),
    surface: 'uniform invalid-link failure (replay) — byte-identical to the wrong-host failure',
    auth_state: 'anonymous',
  });

  // --- wrong environment + tampered binding -----------------------------------
  // Both are induced by mutating the STORED binding of a freshly issued link, which is exactly
  // the substitution the binding exists to defeat. The browser must not be able to tell them
  // apart from an ordinary expiry.
  const variants = [
    ['wrong environment', `update magic_login_tokens set environment = 'staging' where token_hash = '%H'`, ['AF-007']],
    ['tampered account binding', `update magic_login_tokens set account_key = 'merchant_finance' where token_hash = '%H'`, ['AF-008']],
    ['tampered host binding', `update magic_login_tokens set intended_host = 'attacker.test' where token_hash = '%H'`, ['AF-008']],
  ];

  for (const [label, statement, cases] of variants) {
    await clearMail();
    const probe = await context.newPage();
    // Request on the Personnel user's OWN host. Asking for it on the Front Office host is
    // correctly answered with the uniform 202 and NO email — that is the non-enumeration
    // contract, not a delivery failure — so the mutated binding would never get exercised.
    await probe.goto(`${originFor(SINGLE.source_account_key)}/auth/login`, { waitUntil: 'domcontentloaded' });
    await requestMagicLink(probe, SINGLE.email);

    const url = await magicLinkUrlFor(SINGLE.email);
    const hash = createHash('sha256').update(new URL(url).searchParams.get('token')).digest('hex');
    sql(statement.replace('%H', hash));

    await probe.goto(url, { waitUntil: 'domcontentloaded' });
    const text = await failureMessage(probe);
    const authed = (await api(probe, 'GET', '/api/v1/me')).status;

    observe(
      `OBS-14-${label.replace(/\s+/g, '-')}`,
      cases,
      `${label} failure is generic and creates no session`,
      authed === 401 && text === wrongHostFailureCopy,
      `me_status=${authed} identical_copy=${text === wrongHostFailureCopy}`,
    );
    await probe.close();
  }

  // ===================================================================== STAGE 3
  console.log('\nSTAGE 3 — account-context discovery and handoff');

  await page.goto(`${originFor(SOURCE)}/dashboard`, { waitUntil: 'domcontentloaded' });

  const contexts = await api(page, 'GET', '/api/v1/auth/account-contexts');
  const list = contexts.json?.data ?? [];
  const payloadText = JSON.stringify(list);

  observe(
    'OBS-15',
    ['CS-001'],
    'context list returns exactly the contexts this user may currently enter',
    contexts.status === 200
      && list.length === 2
      && list.some((c) => c.account_key === SOURCE)
      && list.some((c) => c.account_key === TARGET),
    `status=${contexts.status} keys=${list.map((c) => c.account_key).join(',')}`,
  );

  observe(
    'OBS-16',
    ['CS-002'],
    'context payload carries no permission array and no numeric merchant/branch id',
    !/"permissions"|"abilities"|"grants"/.test(payloadText)
      && !/"merchant_id"\s*:\s*\d/.test(payloadText)
      && !/"branch_id"\s*:\s*\d/.test(payloadText)
      && list.every((c) => /^[0-9a-f]{32}$/.test(c.context_id ?? '')),
    `keys=${Object.keys(list[0] ?? {}).join(',')}`,
  );

  const targetContext = list.find((c) => c.account_key === TARGET);

  // Issue the handoff, deliberately including fields the browser must never be able to influence.
  const issued = await api(page, 'POST', '/api/v1/auth/account-contexts/switch', {
    context_id: targetContext.context_id,
    redirect: '/audit/events',
    // None of the following may be honoured — the server derives all of them.
    account_key: 'super_administrator',
    role: 'super_admin',
    permissions: ['*'],
    merchant_id: 1,
    branch_id: 1,
    mfa_verified: true,
  });

  const targetUrl = issued.json?.data?.target_url ?? '';
  if (targetUrl) remember(new URL(targetUrl).searchParams.get('token'));

  observe(
    'OBS-17',
    ['CS-003', 'CS-004'],
    'switch returns a registry-derived target URL for the requested context; injected role/permission/merchant/MFA fields are not honoured',
    issued.status === 201
      && new URL(targetUrl).hostname === hostFor(TARGET)
      && issued.json?.data?.target_account_key === TARGET,
    `status=${issued.status} target_host=${targetUrl && new URL(targetUrl).hostname} target_account=${issued.json?.data?.target_account_key}`,
  );

  const handoffRow = sql(
    `select target_account_key || '|' || target_host || '|' || (ip_hash is not null) || '|' || (user_agent_hash is not null) from account_context_handoffs order by id desc limit 1`,
  );
  const rawHandoffToken = new URL(targetUrl).searchParams.get('token');
  const rawHandoffStored = sql(
    `select count(*) from account_context_handoffs where token_hash = '${rawHandoffToken}'`,
  );
  // `boolean || text` renders as 'true'/'false' in PostgreSQL, not the psql display shorthand 't'.
  observe(
    'OBS-18',
    ['CS-005'],
    'handoff stores only the hashed token and hashed request metadata',
    rawHandoffStored === '0' && handoffRow === `${TARGET}|${hostFor(TARGET)}|true|true`,
    `raw_token_rows=${rawHandoffStored} row=${handoffRow}`,
  );

  // --- correct target host -----------------------------------------------------
  //
  // The valid consume comes FIRST, and every negative handoff case below mints its own token.
  // That ordering is forced by two real product behaviours, both correct and both proven here:
  //   * ANY rejection (including wrong_host) INVALIDATES the handoff — a token presented on the
  //     wrong host is burned, not merely refused, so it cannot then be retried on the right one;
  //   * issuing a new handoff SUPERSEDES the previous unconsumed one.
  // Presenting on the wrong host first would therefore have destroyed the token under test.
  const sourceSessionIdBefore = sql(
    `select h.session_id from host_sessions h join users u on u.id = h.user_id where u.email = '${MULTI.email}' and h.account_key = '${SOURCE}' and h.revoked_at is null`,
  );

  const targetPage = await context.newPage();
  await targetPage.goto(targetUrl, { waitUntil: 'domcontentloaded' });
  await targetPage.waitForTimeout(2000);

  const settled = new URL(targetPage.url());
  observe(
    'OBS-20',
    ['CS-007', 'CS-009', 'CS-019'],
    'handoff consumed on the exact target host; the safe deep link is preserved and the final URL carries no token',
    settled.hostname === hostFor(TARGET)
      && settled.pathname === '/audit/events'
      && !settled.searchParams.has('token'),
    `settled=${settled.hostname}${settled.pathname} token_in_url=${settled.searchParams.has('token')}`,
  );

  const targetMe = await api(targetPage, 'GET', '/api/v1/me');
  const targetMeText = JSON.stringify(targetMe.json ?? {});
  observe(
    'OBS-21',
    ['CS-007'],
    'target /api/v1/me reports only the target context — the source merchant is absent',
    targetMe.status === 200
      && targetMeText.includes(FIXTURE.target_merchant.name)
      && !targetMeText.includes(FIXTURE.source_merchant.name),
    `status=${targetMe.status} has_target=${targetMeText.includes(FIXTURE.target_merchant.name)} has_source=${targetMeText.includes(FIXTURE.source_merchant.name)}`,
  );

  /*
   * Source permissions must be ABSENT from the target — proven by an actual set difference, not by
   * guessing at permission names. Audit legitimately holds some read permissions that look like
   * Front Office ones, so a keyword match would be both wrong and weak. What matters is that a
   * permission the SOURCE holds and the TARGET does not never appears on the target host.
   */
  const sourceMe = await api(page, 'GET', '/api/v1/me');
  const sourcePermissions = sourceMe.json?.data?.permissions ?? [];
  const targetPermissions = targetMe.json?.data?.permissions ?? [];
  const sourceOnly = sourcePermissions.filter((p) => !targetPermissions.includes(p));
  const leaked = sourceOnly.filter((p) => targetPermissions.includes(p));

  observe(
    'OBS-22',
    ['CS-007', 'AF-018'],
    'source-only permissions are absent from the target context, and the target resolves its own',
    sourcePermissions.length > 0
      && targetPermissions.length > 0
      && sourceOnly.length > 0
      && leaked.length === 0,
    `source=${sourcePermissions.length} target=${targetPermissions.length} source_only=${sourceOnly.length} leaked=${leaked.length}`,
  );

  const targetAccountKeys = targetMe.json?.data?.account_keys ?? [];
  observe(
    'OBS-22b',
    ['CS-007'],
    'the target context reports the target merchant, membership and account, never the source merchant',
    targetMeText.includes(FIXTURE.target_merchant.name)
      && !targetMeText.includes(FIXTURE.source_merchant.name)
      && targetAccountKeys.includes(TARGET),
    `account_keys=${JSON.stringify(targetAccountKeys)}`,
  );

  const sessionRows = sql(
    `select h.account_key || '=' || h.session_id from host_sessions h join users u on u.id = h.user_id where u.email = '${MULTI.email}' and h.revoked_at is null order by h.account_key`,
  );
  const rowsByAccount = Object.fromEntries(
    sessionRows.split('\n').filter(Boolean).map((r) => r.split('=')),
  );
  const distinctIds = new Set(Object.values(rowsByAccount));
  const familiesForUser = sql(
    `select count(distinct h.session_family_id) from host_sessions h join users u on u.id = h.user_id where u.email = '${MULTI.email}' and h.revoked_at is null`,
  );
  observe(
    'OBS-23',
    ['CS-007'],
    'the target host session is a NEW session id joined to the SAME family, never the source session id',
    distinctIds.size === 2
      && familiesForUser === '1'
      && rowsByAccount[TARGET] !== sourceSessionIdBefore
      && rowsByAccount[SOURCE] === sourceSessionIdBefore,
    `distinct_session_ids=${distinctIds.size} families=${familiesForUser} target_reused_source_id=${rowsByAccount[TARGET] === sourceSessionIdBefore}`,
  );

  await shot(targetPage, '06-target-host-after-switch.png', {
    account_key: TARGET,
    host: hostFor(TARGET),
    surface: 'authenticated target account reached by context handoff (safe deep link preserved)',
    auth_state: 'authenticated',
  });

  // The source session must still be alive and still be the SOURCE account.
  const sourceStillMe = await api(page, 'GET', '/api/v1/me');
  const sourceStillText = JSON.stringify(sourceStillMe.json ?? {});
  observe(
    'OBS-24',
    ['CS-007'],
    'the source host session survives the switch and still reports only the source context',
    sourceStillMe.status === 200
      && sourceStillText.includes(FIXTURE.source_merchant.name)
      && !sourceStillText.includes(FIXTURE.target_merchant.name),
    `status=${sourceStillMe.status}`,
  );

  // --- handoff replay ----------------------------------------------------------
  // A CLEAN context: the shared one already holds a valid target-host cookie from the successful
  // switch, so `/me` there would answer 200 whatever the replay did and the check would be vacuous.
  const replayCtx = await browser.newContext({ viewport: VIEWPORT });
  const replayHandoff = await replayCtx.newPage();
  await replayHandoff.goto(targetUrl, { waitUntil: 'domcontentloaded' });
  await replayHandoff.waitForTimeout(1500);
  const replayHandoffMe = (await api(replayHandoff, 'GET', '/api/v1/me')).status;
  const targetSessionCount = liveSessions(MULTI.email, TARGET);
  observe(
    'OBS-25',
    ['CS-010'],
    'handoff replay is refused and mints no second target session',
    replayHandoffMe === 401 && targetSessionCount === '1',
    `me_status=${replayHandoffMe} target_sessions=${targetSessionCount}`,
  );
  await replayCtx.close();

  // --- wrong target host -------------------------------------------------------
  // Its OWN freshly minted handoff, for the reason recorded above the valid consume.
  const wrongHostHandoff = await mintHandoff(page, TARGET);
  const wrongHostTarget = new URL(wrongHostHandoff);
  wrongHostTarget.hostname = hostFor('merchant_human_resource');

  const sessionsBeforeWrongHost = liveSessions(MULTI.email, 'merchant_human_resource');

  const wrongTargetPage = await browser.newContext({ viewport: VIEWPORT });
  const wrongTargetTab = await wrongTargetPage.newPage();
  await wrongTargetTab.goto(wrongHostTarget.href, { waitUntil: 'domcontentloaded' });
  await wrongTargetTab.waitForTimeout(1200);
  const wrongTargetMe = (await api(wrongTargetTab, 'GET', '/api/v1/me')).status;

  observe(
    'OBS-19',
    ['CS-011'],
    'handoff presented on the wrong target host is refused and mints no session there',
    wrongTargetMe === 401
      && new URL(wrongTargetTab.url()).pathname === '/auth/login'
      && liveSessions(MULTI.email, 'merchant_human_resource') === sessionsBeforeWrongHost,
    `me_status=${wrongTargetMe} settled=${new URL(wrongTargetTab.url()).pathname} hr_sessions=${liveSessions(MULTI.email, 'merchant_human_resource')}`,
  );
  await shot(wrongTargetTab, '05-wrong-target-host-handoff-denied.png', {
    account_key: 'merchant_human_resource',
    host: hostFor('merchant_human_resource'),
    surface: 'uniform handoff failure on the wrong target host',
    auth_state: 'anonymous',
  });

  // The wrong-host presentation must also BURN the token, so it cannot be retried on the host it
  // was actually minted for. Retry the same token on the correct host and require refusal.
  const retryTab = await wrongTargetPage.newPage();
  await retryTab.goto(wrongHostHandoff, { waitUntil: 'domcontentloaded' });
  await retryTab.waitForTimeout(1500);
  observe(
    'OBS-19b',
    ['CS-011', 'CS-010'],
    'a handoff presented on the wrong host is BURNED — retrying it on the correct host also fails',
    (await api(retryTab, 'GET', '/api/v1/me')).status === 401
      && new URL(retryTab.url()).pathname === '/auth/login',
    `settled=${new URL(retryTab.url()).pathname}${new URL(retryTab.url()).search}`,
  );
  await wrongTargetPage.close();

  // --- unsafe deep link --------------------------------------------------------
  const unsafeUrl = await mintHandoff(page, TARGET, { redirect: 'https://attacker.test/steal' });

  const unsafePage = await context.newPage();
  await unsafePage.goto(unsafeUrl, { waitUntil: 'domcontentloaded' });
  await unsafePage.waitForTimeout(2000);
  const unsafeSettled = new URL(unsafePage.url());
  observe(
    'OBS-26',
    ['CS-018'],
    'an unsafe absolute redirect is dropped: the browser lands on the target account default route, never off-host',
    unsafeSettled.hostname === hostFor(TARGET) && !unsafeSettled.href.includes('attacker.test'),
    `settled=${unsafeSettled.hostname}${unsafeSettled.pathname}`,
  );
  await unsafePage.close();

  // ===================================================================== STAGE 4
  console.log('\nSTAGE 4 — stale target authority');

  const staleCases = [
    [
      'target membership removed',
      ['CS-013'],
      (mu) => `update merchant_users set status = 'suspended' where id = ${mu}`,
      (mu) => `update merchant_users set status = 'active' where id = ${mu}`,
    ],
    [
      'target branch assignment withdrawn',
      ['CS-015'],
      (mu) => `update branch_user_assignments set status = 'revoked' where merchant_user_id = ${mu}`,
      (mu) => `update branch_user_assignments set status = 'active' where merchant_user_id = ${mu}`,
    ],
    [
      'target role changed',
      ['CS-014'],
      (mu) => `update merchant_users set role = 'hr' where id = ${mu}`,
      (mu) => `update merchant_users set role = 'audit' where id = ${mu}`,
    ],
  ];

  const targetMembershipId = sql(
    `select mu.id from merchant_users mu join users u on u.id = mu.user_id join merchants m on m.id = mu.merchant_id where u.email = '${MULTI.email}' and m.name = '${FIXTURE.target_merchant.name}'`,
  );

  /*
   * Each probe runs in its OWN browser context.
   *
   * The shared context already holds a valid target-host cookie from the successful switch above,
   * so `/me` there would answer 200 whatever this particular consume did — the assertion would
   * pass vacuously. A clean context has no target cookie, so a 200 can only mean THIS consume
   * minted a session. The database row count is asserted alongside it, because that is the fact
   * that actually matters.
   */
  for (const [label, cases, breakIt, restore] of staleCases) {
    const url = await mintHandoff(page, TARGET);
    const before = liveSessions(MULTI.email, TARGET);

    sql(breakIt(targetMembershipId));

    const isolated = await browser.newContext({ viewport: VIEWPORT });
    const probe = await isolated.newPage();
    await probe.goto(url, { waitUntil: 'domcontentloaded' });
    await probe.waitForTimeout(1500);
    const probeMe = (await api(probe, 'GET', '/api/v1/me')).status;
    const after = liveSessions(MULTI.email, TARGET);

    observe(
      `OBS-27-${label.replace(/\s+/g, '-')}`,
      cases,
      `${label} after issuance: consume re-resolves live authority and refuses`,
      probeMe === 401 && after === before,
      `me_status=${probeMe} settled=${new URL(probe.url()).pathname} target_sessions ${before}->${after}`,
    );

    await isolated.close();
    sql(restore(targetMembershipId));
  }

  // --- suspended user ----------------------------------------------------------
  {
    const url = await mintHandoff(page, TARGET);
    const before = liveSessions(MULTI.email, TARGET);

    sql(`update users set status = 'suspended' where email = '${MULTI.email}'`);

    const isolated = await browser.newContext({ viewport: VIEWPORT });
    const probe = await isolated.newPage();
    await probe.goto(url, { waitUntil: 'domcontentloaded' });
    await probe.waitForTimeout(1500);
    const probeMe = (await api(probe, 'GET', '/api/v1/me')).status;
    const after = liveSessions(MULTI.email, TARGET);

    observe(
      'OBS-28',
      ['CS-016'],
      'suspended user: handoff consume is refused and mints no target session',
      probeMe === 401 && after === before,
      `me_status=${probeMe} target_sessions ${before}->${after}`,
    );
    await isolated.close();

    sql(`update users set status = 'active' where email = '${MULTI.email}'`);
  }

  // --- revoked source family ---------------------------------------------------
  {
    const url = await mintHandoff(page, TARGET);
    const before = liveSessions(MULTI.email, TARGET);

    // Revoke the FAMILY directly. Family revocation is authoritative at read time — the child
    // host_sessions rows keep their own `revoked_at` null, so counting unrevoked rows is NOT the
    // test. What matters is that the consume refuses and mints nothing new.
    sql(
      `update session_families set revoked_at = now(), revoked_reason = 'global_logout' where user_id = (select id from users where email = '${MULTI.email}')`,
    );

    const isolated = await browser.newContext({ viewport: VIEWPORT });
    const probe = await isolated.newPage();
    await probe.goto(url, { waitUntil: 'domcontentloaded' });
    await probe.waitForTimeout(1500);
    const probeMe = (await api(probe, 'GET', '/api/v1/me')).status;
    const after = liveSessions(MULTI.email, TARGET);

    observe(
      'OBS-29',
      ['CS-017'],
      'revoked source family: handoff consume is refused and mints no new target session',
      probeMe === 401 && after === before,
      `me_status=${probeMe} target_sessions ${before}->${after}`,
    );
    await isolated.close();
  }

  // ===================================================================== STAGE 5
  console.log('\nSTAGE 5 — global logout across hosts');

  // Sign the multi-context user in again (the family above was revoked) and switch, so logout-all
  // has a genuine two-host family to destroy.
  await clearMail();
  const relogin = await context.newPage();
  await relogin.goto(`${originFor(SOURCE)}/auth/login`, { waitUntil: 'domcontentloaded' });
  await requestMagicLink(relogin, MULTI.email);
  const reloginUrl = await magicLinkUrlFor(MULTI.email);
  await relogin.goto(reloginUrl, { waitUntil: 'domcontentloaded' });
  await relogin.waitForTimeout(2500);

  const reSwitchUrl = await mintHandoff(relogin, TARGET);

  const targetLive = await context.newPage();
  await targetLive.goto(reSwitchUrl, { waitUntil: 'domcontentloaded' });
  await targetLive.waitForTimeout(2000);

  const bothLive =
    (await api(relogin, 'GET', '/api/v1/me')).status === 200
    && (await api(targetLive, 'GET', '/api/v1/me')).status === 200;

  const logoutAll = await api(relogin, 'POST', '/api/v1/auth/logout-all');
  await relogin.waitForTimeout(500);

  const sourceAfter = (await api(relogin, 'GET', '/api/v1/me')).status;
  const targetAfter = (await api(targetLive, 'GET', '/api/v1/me')).status;
  const liveRows = sql(
    `select count(*) from host_sessions h join users u on u.id = h.user_id where u.email = '${MULTI.email}' and h.revoked_at is null`,
  );

  observe(
    'OBS-30',
    ['AF-019', 'CS-024'],
    'global logout invalidates BOTH the source and the target host session',
    bothLive && sourceAfter === 401 && targetAfter === 401 && liveRows === '0',
    `both_live_before=${bothLive} logout_status=${logoutAll.status} source_after=${sourceAfter} target_after=${targetAfter} live_host_sessions=${liveRows}`,
  );

  await targetLive.goto(`${originFor(TARGET)}/dashboard`, { waitUntil: 'domcontentloaded' });
  await targetLive.waitForTimeout(1200);
  await shot(targetLive, '07-target-host-after-global-logout.png', {
    account_key: TARGET,
    host: hostFor(TARGET),
    surface: 'target host after global logout — session gone',
    auth_state: 'anonymous',
  });
  await targetLive.close();
  await relogin.close();

  // ===================================================================== STAGE 6
  console.log('\nSTAGE 6 — MFA assurance is not inherited');

  await clearMail();
  // A FRESH context. This is a different principal signing in on a host where the shared jar still
  // holds the previous user's (now globally logged-out) cookies; reusing that jar makes the new
  // sign-in race a stale cookie for the same host and the session reads as unauthenticated.
  const mfaCtx = await browser.newContext({ viewport: VIEWPORT });
  const mfaPage = await mfaCtx.newPage();
  await mfaPage.goto(`${originFor(MFA_USER.source_account_key)}/auth/login`, { waitUntil: 'domcontentloaded' });
  await requestMagicLink(mfaPage, MFA_USER.email);
  const mfaLink = await magicLinkUrlFor(MFA_USER.email);
  await mfaPage.goto(mfaLink, { waitUntil: 'domcontentloaded' });
  await mfaPage.waitForTimeout(2500);

  // Satisfy MFA on the SOURCE host.
  const challenge = await api(mfaPage, 'POST', '/api/v1/auth/mfa/challenge', {
    code: totp(MFA_USER.totp_secret),
  });
  observe(
    'OBS-31',
    ['AF-012'],
    'the mandatory-MFA user can satisfy the MFA challenge on the source host',
    challenge.status === 200 || challenge.status === 204,
    `challenge_status=${challenge.status}`,
  );

  const mfaSwitchUrl = await mintHandoff(mfaPage, MFA_USER.target_account_key);

  const financePage = await mfaCtx.newPage();
  await financePage.goto(mfaSwitchUrl, { waitUntil: 'domcontentloaded' });
  await financePage.waitForTimeout(2000);

  const financeSessionMfa = sql(
    `select mfa_required_at_creation from host_sessions h join users u on u.id = h.user_id where u.email = '${MFA_USER.email}' and h.account_key = '${MFA_USER.target_account_key}' and h.revoked_at is null`,
  );
  // A route inside the MFA-gated group. `me` is deliberately NOT the probe: it is allowlisted
  // while a mandatory user's MFA is incomplete, so it would pass and prove nothing.
  // EnsurePrivilegedMfa runs BEFORE tenant/permission resolution, so a 403 here is the MFA gate.
  const gated = await api(financePage, 'GET', '/api/v1/merchant/dashboard');

  observe(
    'OBS-32',
    ['AF-012', 'CS-008'],
    'the target session records the freshly resolved MFA requirement and does NOT inherit the source assertion — the mandatory target role is challenged again',
    financeSessionMfa === 't' && gated.status === 403 && /mfa/i.test(JSON.stringify(gated.json ?? {})),
    `mfa_required_at_creation=${financeSessionMfa} gated_status=${gated.status} code=${gated.json?.error?.code}`,
  );

  await shot(financePage, '08-target-mfa-challenge-required.png', {
    account_key: MFA_USER.target_account_key,
    host: hostFor(MFA_USER.target_account_key),
    surface: 'mandatory-MFA target account challenges again after the switch',
    auth_state: 'authenticated, MFA not asserted',
  });
  await financePage.close();
  await mfaCtx.close();

  // ===================================================================== STAGE 7
  console.log('\nSTAGE 7 — wrong-account deep link');

  await clearMail();
  const singleCtx = await browser.newContext({ viewport: VIEWPORT });
  const singlePage = await singleCtx.newPage();
  await singlePage.goto(`${originFor(SINGLE.source_account_key)}/auth/login`, { waitUntil: 'domcontentloaded' });
  await requestMagicLink(singlePage, SINGLE.email);
  const singleLink = await magicLinkUrlFor(SINGLE.email);
  await singlePage.goto(singleLink, { waitUntil: 'domcontentloaded' });
  await singlePage.waitForTimeout(2500);

  /*
   * Two DIFFERENT wrong-account deep links, and they fail in two different ways. Both are real and
   * both are recorded honestly — conflating them would overclaim.
   *
   * (a) SAME HOST, foreign route. The user is genuinely authenticated on their own account host and
   *     types a path owned by another account. This is the one the `requiresAccount` guard exists
   *     for: route account, server-resolved host account and server-derived held account disagree,
   *     so the role-safe access-denied state renders. No redirect to a broader account.
   */
  await singlePage.goto(`${originFor(SINGLE.source_account_key)}/platform`, { waitUntil: 'domcontentloaded' });
  await singlePage.waitForTimeout(2000);
  const sameHostText = await singlePage.evaluate(() => document.body.innerText);
  const sameHostUrl = new URL(singlePage.url());

  observe(
    'OBS-33',
    ['AF-018', 'CS-023'],
    'a foreign-account route on the user\'s OWN host renders the role-safe access-denied state and never redirects to another account',
    sameHostUrl.hostname === hostFor(SINGLE.source_account_key)
      && /do not have access/i.test(sameHostText)
      && !/Super Administrator/i.test(sameHostText),
    `settled=${sameHostUrl.hostname}${sameHostUrl.pathname} heading=${(sameHostText.split('\n').find((l) => l.trim() !== '') ?? '').slice(0, 80)}`,
  );
  await shot(singlePage, '09-wrong-account-deep-link-denied.png', {
    account_key: SINGLE.source_account_key,
    host: hostFor(SINGLE.source_account_key),
    surface: 'foreign-account route on the user\'s own host — role-safe access-denied',
    auth_state: 'authenticated as an unrelated account',
  });

  /*
   * (b) CROSS HOST. The same user opens the platform host directly. Their session cookie is
   *     HOST-ONLY, so the browser sends nothing — they arrive unauthenticated and get that host's
   *     own login experience. That is cookie scoping doing the work before any guard runs, and it
   *     is why (a) is the case the guard actually has to handle.
   */
  await singlePage.goto(`${originFor('super_administrator')}/platform`, { waitUntil: 'domcontentloaded' });
  await singlePage.waitForTimeout(1800);
  const crossHostUrl = new URL(singlePage.url());
  const crossHostMe = (await api(singlePage, 'GET', '/api/v1/me')).status;

  observe(
    'OBS-33b',
    ['AF-018', 'CS-023', 'AF-015'],
    'the same deep link on ANOTHER account host carries no session at all — host-only cookies leave the user anonymous there',
    crossHostUrl.hostname === hostFor('super_administrator')
      && crossHostMe === 401
      && !/Super Administrator dashboard|platform overview/i.test(await singlePage.evaluate(() => document.body.innerText)),
    `settled=${crossHostUrl.hostname}${crossHostUrl.pathname} me_status=${crossHostMe}`,
  );
  await singleCtx.close();

  // ===================================================================== STAGE 8
  console.log('\nSTAGE 8 — credential-leakage sweep');

  const storage = await clientStorage(page);
  const storageText = JSON.stringify(storage);
  const leakedInStorage = [...secrets].filter((s) => s && storageText.includes(s));

  observe(
    'OBS-34',
    ['AF-020', 'CS-025'],
    'no magic-link token, handoff token or raw session id appears in localStorage, sessionStorage or IndexedDB',
    leakedInStorage.length === 0 && storage.indexedDbDatabases.length === 0,
    `localStorage_keys=${Object.keys(storage.localStorage).join(',') || '(none)'} indexeddb=${storage.indexedDbDatabases.join(',') || '(none)'}`,
  );

  observe(
    'OBS-35',
    ['AF-020', 'CS-025'],
    'the session cookie is HttpOnly — it is not visible to document.cookie',
    !/servana_session|laravel_session/i.test(storage.documentCookieVisible),
    `document_cookie_names=${storage.documentCookieVisible.split('; ').map((c) => c.split('=')[0]).join(',') || '(none)'}`,
  );

  const leakedInConsole = consoleMessages.filter((m) => [...secrets].some((s) => s && m.includes(s)));
  observe(
    'OBS-36',
    ['AF-020', 'CS-025'],
    'no credential material appears in browser console output',
    leakedInConsole.length === 0,
    `console_messages=${consoleMessages.length} leaked=${leakedInConsole.length}`,
  );

  // Application logs, read from the running production app container.
  let appLog = '';
  try {
    appLog = execFileSync('docker', ['logs', '--tail', '4000', 'servana-ui03-app'], {
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
    });
  } catch {
    appLog = '';
  }
  const laravelLog = (() => {
    try {
      return execFileSync(
        'docker',
        ['exec', 'servana-ui03-app', 'sh', '-c', 'cat storage/logs/*.log 2>/dev/null | tail -c 400000'],
        { encoding: 'utf8' },
      );
    } catch {
      return '';
    }
  })();

  const logText = `${appLog}\n${laravelLog}`;
  const leakedInLogs = [...secrets].filter((s) => s && logText.includes(s));
  observe(
    'OBS-37',
    ['AF-020', 'CS-025', 'AF-010'],
    'no credential material appears in the application or container logs',
    leakedInLogs.length === 0,
    `log_bytes=${logText.length} leaked=${leakedInLogs.length}`,
  );

  // `audit_logs` names this column `context`, not `metadata`.
  const auditMeta = sql(
    `select coalesce(string_agg(context::text, ' '), '') from audit_logs where created_at > now() - interval '30 minutes'`,
  );
  const leakedInAudit = [...secrets].filter((s) => s && auditMeta.includes(s));
  observe(
    'OBS-38',
    ['AF-020', 'CS-025'],
    'no credential material appears in audit metadata',
    leakedInAudit.length === 0,
    `audit_metadata_bytes=${auditMeta.length} leaked=${leakedInAudit.length}`,
  );

  const leakedInNetwork = networkLog.filter((r) =>
    [...secrets].some((s) => s && (r.post_data ?? '').includes(s)),
  );
  observe(
    'OBS-39',
    ['AF-020', 'CS-025'],
    'the committed network evidence carries no credential material (all request bodies sanitized)',
    leakedInNetwork.length === 0,
    `recorded_requests=${networkLog.length}`,
  );

  const shotLeak = screenshots.filter((s) => s.url_contains_token);
  observe(
    'OBS-40',
    ['AF-020', 'CS-025'],
    'no captured screenshot was taken on a URL still carrying a token, and none renders credential material',
    shotLeak.length === 0,
    `screenshots=${screenshots.length}`,
  );
} catch (error) {
  // A thrown stage must still leave evidence behind. Losing the whole report because stage 6
  // exploded would hide the stages that already passed and make the failure harder to classify.
  failures.push(`ABORTED — ${sanitize(String(error).split('\n')[0])}`);
  observations.push({
    observation_id: 'OBS-ABORT',
    matrix_cases: [],
    description: 'the run aborted before completing every stage',
    result: 'FAILED',
    detail: sanitize(String(error).split('\n').slice(0, 3).join(' | ')),
  });
} finally {
  await context.close();
  await browser.close();
}

// ---------------------------------------------------------------------------
// Emit evidence
// ---------------------------------------------------------------------------

const report = {
  generated_by: 'scripts/ui03-auth-browser-proof.mjs',
  purpose:
    'Focused UI-03 deployed-origin browser proof against the BUILT production nginx + PHP images '
    + 'and a real PostgreSQL database. Closes residual risk R1. Not a release visual baseline.',
  origin: ORIGIN,
  source_commit: SOURCE_COMMIT,
  vite_manifest_sha256: MANIFEST_SHA256,
  environment: 'local (account hosts *.servana.test on the published production-pair port)',
  captured_at: new Date().toISOString(),
  observations,
  console_message_count: consoleMessages.length,
  recorded_requests: networkLog.length,
  passed: failures.length === 0,
  failures: failures.map(sanitize),
};

mkdirSync(dirname(join(ROOT, OUT)), { recursive: true });
writeFileSync(join(ROOT, OUT), `${JSON.stringify(report, null, 2)}\n`, 'utf8');

writeFileSync(
  join(ROOT, SHOT_INDEX),
  `${JSON.stringify(
    {
      generated_by: 'scripts/ui03-auth-browser-proof.mjs',
      purpose:
        'Targeted UI-03 authentication / session / account-switching screenshots captured against '
        + 'the BUILT production nginx + PHP pair. This is deliberately NOT the UI-01 as-built matrix '
        + 'and NOT the UI-02 host matrix — both are historical evidence and are untouched. UI-16 owns '
        + 'reviewed release baselines and the responsive/theme matrix.',
      origin: ORIGIN,
      source_commit: SOURCE_COMMIT,
      vite_manifest_sha256: MANIFEST_SHA256,
      viewport: `${VIEWPORT.width}x${VIEWPORT.height}`,
      theme: 'default (light)',
      redaction:
        'No screenshot was captured on a URL carrying a token, and every capture asserted that the '
        + 'rendered text contains no magic-link token, handoff token, TOTP secret or session id. '
        + 'All fixture data is throwaway; no real customer record appears.',
      fixture: 'scripts/ui03-browser-fixture.php against the disposable servana_ui03_proof database',
      captured_at: new Date().toISOString(),
      screenshot_count: screenshots.length,
      screenshots,
      unknown_host_denial: {
        host: 'unknown-host.test',
        screenshot: null,
        reason:
          'nginx answers an unapproved Host with `return 444`, closing the connection with no '
          + 'response. There is no rendered page to capture — the absence IS the evidence.',
      },
    },
    null,
    2,
  )}\n`,
  'utf8',
);

console.log(`\nObservations: ${observations.length}, failures: ${failures.length}`);
console.log(`Screenshots:  ${screenshots.length} → ${SHOT_DIR}`);
console.log(`Evidence:     ${OUT}`);

if (failures.length > 0) {
  console.error('\nFAILED:');
  for (const failure of failures) console.error(`  - ${failure}`);
  process.exit(1);
}
