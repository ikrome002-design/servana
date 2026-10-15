/**
 * LOCAL-UAT-001 shared harness: the eight local account hosts, the seeded identities, real Magic Link
 * delivery through Mailpit, RFC 6238 TOTP for the automation identities, and a page-health watcher.
 *
 * Nothing here mocks the API. Every request goes through nginx to the real Laravel application.
 */
import { createHmac } from 'node:crypto';
import { appendFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';

import { expect, type APIRequestContext, type Page } from '@playwright/test';

export const PORT = Number(process.env['LOCAL_UAT_PORT'] ?? 8080);
export const MAILPIT = process.env['LOCAL_UAT_MAILPIT'] ?? 'http://localhost:8025';
export const OUT_DIR = path.resolve('test-results/local-uat');
const FIXTURE_DIR = path.resolve('storage/app/local-uat');

export type AccountKey =
  | 'super_administrator'
  | 'merchant_administrator'
  | 'merchant_branch'
  | 'merchant_finance'
  | 'merchant_human_resource'
  | 'merchant_front_office'
  | 'merchant_personnel'
  | 'merchant_audit';

interface AccountFixture {
  host: string;
  /** The AUTOMATION identity. The owner identities are never signed in by this suite. */
  automation: string;
  /** Mandatory MFA (config/account-hosts.json `requires_mfa`). */
  mfa: boolean;
}

export const ACCOUNTS: Record<AccountKey, AccountFixture> = {
  super_administrator: { host: 'citrus.servana.test', automation: 'uat.admin@citrus.servana.test', mfa: true },
  merchant_administrator: { host: 'servana.test', automation: 'uat.owner@acacia.servana.test', mfa: true },
  merchant_branch: { host: 'branch.servana.test', automation: 'uat.branch@acacia.servana.test', mfa: false },
  merchant_finance: { host: 'finance.servana.test', automation: 'uat.finance@acacia.servana.test', mfa: true },
  merchant_human_resource: { host: 'hr.servana.test', automation: 'uat.hr@acacia.servana.test', mfa: false },
  merchant_front_office: { host: 'office.servana.test', automation: 'uat.frontoffice@acacia.servana.test', mfa: false },
  merchant_personnel: { host: 'staff.servana.test', automation: 'uat.stylist@acacia.servana.test', mfa: false },
  merchant_audit: { host: 'audit.servana.test', automation: 'uat.audit@acacia.servana.test', mfa: false },
};

export const ACCOUNT_KEYS = Object.keys(ACCOUNTS) as AccountKey[];

export function origin(account: AccountKey): string {
  return `http://${ACCOUNTS[account].host}${PORT === 80 ? '' : `:${PORT}`}`;
}

export function statePath(account: AccountKey): string {
  return path.join(OUT_DIR, 'state', `${account}.json`);
}

// ---------------------------------------------------------------------------------------------
// Seeded fixture (written by LocalUatDatasetSeeder into git-ignored storage/app/local-uat/)
// ---------------------------------------------------------------------------------------------

export interface FixtureIdentity {
  email: string;
  name: string;
  automation: boolean;
  role: string | null;
  merchant_ulid: string | null;
  staff_profile_ulid: string | null;
}

export interface FixtureManifest {
  merchants: Record<'acacia' | 'baobab', { ulid: string; name: string }>;
  branches: Record<string, { ulid: string; name: string }>;
  subscription_invoice_ulid: string;
  westlands_client_ulid: string;
  kilimani_client_ulid: string;
  baobab_client_ulid: string;
  identities: FixtureIdentity[];
}

export function fixtureManifest(): FixtureManifest {
  const file = path.join(FIXTURE_DIR, 'fixture-manifest.json');
  if (!existsSync(file)) {
    throw new Error('storage/app/local-uat/fixture-manifest.json is missing. Seed first: scripts/dev/servana-local.ps1 seed');
  }

  return JSON.parse(readFileSync(file, 'utf8')) as FixtureManifest;
}

export function identity(email: string): FixtureIdentity {
  const found = fixtureManifest().identities.find((i) => i.email === email);
  if (found === undefined) throw new Error(`Seeded identity ${email} is missing from the fixture manifest.`);

  return found;
}

/** Values discovered by the workflow spec and reused by later specs (detail-route ULIDs). */
export function readRuntime(): Record<string, string> {
  const file = path.join(OUT_DIR, 'runtime.json');

  return existsSync(file) ? (JSON.parse(readFileSync(file, 'utf8')) as Record<string, string>) : {};
}

export function writeRuntime(values: Record<string, string>): void {
  mkdirSync(OUT_DIR, { recursive: true });
  writeFileSync(path.join(OUT_DIR, 'runtime.json'), `${JSON.stringify({ ...readRuntime(), ...values }, null, 2)}\n`);
}

/**
 * Append one result line to test-results/local-uat/results/<name>.jsonl. Append-only, so a worker restart
 * after a failing test loses nothing; scripts/dev/servana-local.ps1 uat clears the folder before a run.
 */
export function appendResult(name: string, value: unknown): void {
  mkdirSync(path.join(OUT_DIR, 'results'), { recursive: true });
  appendFileSync(path.join(OUT_DIR, 'results', `${name}.jsonl`), `${JSON.stringify(value)}\n`);
}

export function writeResult(name: string, value: unknown): void {
  mkdirSync(OUT_DIR, { recursive: true });
  writeFileSync(path.join(OUT_DIR, name), `${JSON.stringify(value, null, 2)}\n`);
}

// ---------------------------------------------------------------------------------------------
// TOTP (RFC 6238: SHA-1, 30-second step, 6 digits — what Google2FA verifies server-side)
// ---------------------------------------------------------------------------------------------

function base32Decode(input: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of input.replace(/=+$/, '').toUpperCase()) {
    const value = alphabet.indexOf(char);
    if (value < 0) throw new Error('Invalid base32 secret.');
    bits += value.toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));

  return Buffer.from(bytes);
}

function totpAt(secret: string, step: number): string {
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hmac = createHmac('sha1', base32Decode(secret)).update(counter).digest();
  const offset = (hmac[hmac.length - 1] ?? 0) & 0x0f;
  const code = (hmac.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;

  return code.toString().padStart(6, '0');
}

/** The current code for a secret shown on the enrolment screen (a brand-new credential). */
export function totpForSecret(secret: string): string {
  return totpAt(secret.replace(/\s+/g, ''), Math.floor(Date.now() / 30_000));
}

const usedSteps = new Map<string, number>();

/**
 * A TOTP code the server will accept: the server refuses a time step it has already seen for the
 * credential (replay protection), so wait for a fresh step when this run already used the current one.
 */
export async function freshTotp(email: string): Promise<string> {
  const file = path.join(FIXTURE_DIR, 'automation-mfa.json');
  const secret = (JSON.parse(readFileSync(file, 'utf8')) as { secrets: Record<string, string> }).secrets[email];
  if (secret === undefined) throw new Error(`No automation TOTP secret for ${email}.`);

  let step = Math.floor(Date.now() / 30_000);
  while (usedSteps.get(email) === step) {
    await new Promise((resolve) => setTimeout(resolve, 1_000));
    step = Math.floor(Date.now() / 30_000);
  }
  usedSteps.set(email, step);

  return totpAt(secret, step);
}

// ---------------------------------------------------------------------------------------------
// Mailpit (the local SMTP sink). Links are returned to the caller only — never logged or written.
// ---------------------------------------------------------------------------------------------

interface MailpitSearch {
  messages: { ID: string }[];
}

export async function latestMessageId(request: APIRequestContext, email: string): Promise<string | null> {
  const res = await request.get(`${MAILPIT}/api/v1/search`, { params: { query: `to:"${email}"`, limit: '1' } });
  expect(res.ok(), 'Mailpit API reachable').toBeTruthy();

  return ((await res.json()) as MailpitSearch).messages[0]?.ID ?? null;
}

export async function waitForSignInLink(request: APIRequestContext, email: string, previousId: string | null): Promise<string> {
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    const id = await latestMessageId(request, email);
    if (id !== null && id !== previousId) {
      const message = (await (await request.get(`${MAILPIT}/api/v1/message/${id}`)).json()) as { Text: string };
      const match = /https?:\/\/[^\s]+\/auth\/verify\?token=[^\s]+/.exec(message.Text);
      if (match === null) throw new Error('The sign-in email carried no /auth/verify link.');

      return match[0];
    }
    await new Promise((resolve) => setTimeout(resolve, 500));
  }
  throw new Error(`No sign-in email reached Mailpit for ${email} within 30 s (is the worker running?).`);
}

// ---------------------------------------------------------------------------------------------
// Real browser sign-in: login form -> Mailpit -> verify link -> (MFA challenge) -> landing
// ---------------------------------------------------------------------------------------------

export async function signInThroughBrowser(page: Page, account: AccountKey, email = ACCOUNTS[account].automation): Promise<void> {
  const before = await latestMessageId(page.request, email);

  // The journey starts on the account's PUBLIC landing page, then follows its own sign-in CTA.
  await page.goto(`${origin(account)}/`);
  const context = JSON.parse((await page.locator('#servana-account-context').textContent()) ?? '{}') as { account_key?: string };
  expect(context.account_key, 'the public landing belongs to this account').toBe(account);
  await page.locator('a[href$="/login"]').first().click();
  await expect(page).toHaveURL(/\/(auth\/)?login$/);
  await page.getByLabel('Email address').fill(email);
  await page.getByRole('button', { name: 'Send sign-in link' }).click();
  // The first PHP request after a long idle can take several seconds on a local machine.
  await expect(page).toHaveURL(/check-email/, { timeout: 20_000 });

  const link = await waitForSignInLink(page.request, email, before);
  // The link is bound to the host the request came from (UI-03).
  expect(new URL(link).host, 'sign-in link is bound to the requesting host').toBe(new URL(origin(account)).host);

  await page.goto(link);

  if (ACCOUNTS[account].mfa) {
    await page.waitForURL(/\/auth\/mfa\/challenge/);
    await page.getByLabel('6-digit code').fill(await freshTotp(email));
    await page.getByRole('button', { name: 'Verify' }).click();
  }

  // The authenticated home is /dashboard for every account (UI/UX plan §4.1; LUAT-D04).
  await page.waitForURL((url) => url.pathname === '/dashboard', { timeout: 30_000 });
}

// ---------------------------------------------------------------------------------------------
// Same-origin API calls made BY THE PAGE (window.fetch), exactly as the SPA makes them: the browser
// resolves the host, attaches the host-only session cookie, and the XSRF header is read from the
// XSRF-TOKEN cookie. (Playwright's page.request resolves DNS in Node, outside the browser.)
// ---------------------------------------------------------------------------------------------

export interface ApiResult {
  status: number;
  body: unknown;
}

export async function api(
  page: Page,
  account: AccountKey,
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  apiPath: string,
  data?: unknown,
  headers: Record<string, string> = {},
): Promise<ApiResult> {
  const base = origin(account);
  if (new URL(page.url()).origin !== base) {
    await page.goto(`${base}/`);
  }

  return page.evaluate(
    async ({ method, apiPath, data, headers }) => {
      const xsrf = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
      const response = await fetch(apiPath, {
        method,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(data === undefined ? {} : { 'Content-Type': 'application/json' }),
          ...(xsrf === undefined ? {} : { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }),
          ...headers,
        },
        body: data === undefined ? undefined : JSON.stringify(data),
      });
      const text = await response.text();
      let body: unknown = text;
      try {
        body = JSON.parse(text);
      } catch {
        // non-JSON body (e.g. 204) — keep the text
      }

      return { status: response.status, body };
    },
    { method, apiPath, data, headers },
  );
}

/** Read a dotted path from an API body, e.g. `get(res.body, 'data.id')`. */
export function get(body: unknown, dotted: string): unknown {
  return dotted.split('.').reduce<unknown>((value, key) => (value !== null && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined), body);
}

export function idempotencyKey(): Record<string, string> {
  return { 'Idempotency-Key': crypto.randomUUID() };
}

// ---------------------------------------------------------------------------------------------
// Page health: page errors, console errors, 5xx and failed requests on a real page load
// ---------------------------------------------------------------------------------------------

export interface PageHealth {
  errors: string[];
  clientErrors: string[];
}

export function watchPage(page: Page): PageHealth {
  const health: PageHealth = { errors: [], clientErrors: [] };

  page.on('pageerror', (error) => health.errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const text = message.text();
    // A 4xx is recorded from the response itself below; the browser's generic console echo of it
    // is not a separate defect.
    if (/Failed to load resource: the server responded with a status of 4\d\d/.test(text)) return;
    health.errors.push(`console: ${text}`);
  });
  page.on('response', async (response) => {
    const url = new URL(response.url());
    // The query string identifies a contract mismatch (e.g. a rejected filter); one-time credentials
    // never appear in an API query, but redact defensively anyway.
    const search = url.search.replace(/(token=)[^&]+/gi, '$1<redacted>');
    if (response.status() >= 500) {
      health.errors.push(`http ${response.status()} ${response.request().method()} ${url.pathname}${search}`);
    } else if (response.status() >= 400) {
      let code = '';
      if (url.pathname.startsWith('/api/')) {
        const body = (await response.json().catch(() => null)) as { error?: { code?: string; fields?: Record<string, unknown> } } | null;
        code = ` ${body?.error?.code ?? ''} ${JSON.stringify(body?.error?.fields ?? {})}`;
      }
      health.clientErrors.push(`http ${response.status()} ${response.request().method()} ${url.pathname}${search}${code}`);
    }
  });
  page.on('requestfailed', (request) => {
    const failure = request.failure()?.errorText ?? 'unknown';
    if (failure.includes('ERR_ABORTED')) return;
    health.errors.push(`requestfailed ${failure} ${new URL(request.url()).pathname}`);
  });

  return health;
}

/** Today's Africa/Nairobi business date (the domain's business day; UTC can be a day behind). */
export function businessDate(offsetDays = 0): string {
  const now = new Date(Date.now() + offsetDays * 86_400_000);

  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Nairobi' }).format(now);
}
