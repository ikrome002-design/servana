/**
 * Account-host context (Phase UI-02; ADR-016, ADR-017).
 *
 * The SERVER decides which account experience a browser is on. Laravel resolves the account
 * host, renders the SPA shell and embeds the resolved context in a
 * `<script id="servana-account-context" type="application/json">` block. This module reads
 * that block, validates it, and cross-checks it against `window.location.hostname`.
 *
 * The browser deliberately does NOT decide its own account context. `window.location` is used
 * only as a consistency check: if the server's answer and the address bar disagree, we fail
 * closed rather than picking one. And none of this is authorization — the context carries no
 * identity, tenant, branch, permission or MFA state, and the API remains the security
 * boundary for every protected call (ADR-017).
 */

import {
  ACCOUNT_HOSTS,
  ACCOUNT_KEYS,
  accountKeyForHost,
  type AccountHostDefinition,
  type NavigationPlacement,
} from '@/host/accountHosts.generated';
import type { RoleIdentity } from '@/types/roles';

export type AccountHostEnvironment = 'production' | 'staging' | 'local' | 'testing';

/** Why the context could not be established. Each maps to a distinct, safe UI boundary. */
export type AccountContextFailure =
  | 'missing' // the shell rendered no context block (served outside Laravel)
  | 'malformed' // the block was not valid JSON, or lacked required fields
  | 'unknown_account' // the account key is not one of the eight
  | 'host_mismatch'; // server context and browser hostname disagree

export interface AccountContext {
  accountKey: RoleIdentity;
  displayName: string;
  host: string;
  environment: AccountHostEnvironment;
  publicContentKey: RoleIdentity;
  legalContentKey: RoleIdentity;
  navigationPlacement: NavigationPlacement;
  routeNamePrefix: string;
  defaultAuthenticatedRoute: string;
  requiresSetup: boolean;
  requiresMfa: boolean;
  roleFamily: string;
  selfRegistration: boolean;
  invitationAcceptance: boolean;
  publicCtaCategory: string;
  /** The static registry row for this account, for metadata later phases need. */
  definition: AccountHostDefinition;
}

export type AccountContextResult =
  | { ok: true; context: AccountContext }
  | { ok: false; failure: AccountContextFailure };

export const ACCOUNT_CONTEXT_ELEMENT_ID = 'servana-account-context';

const ENVIRONMENTS: AccountHostEnvironment[] = ['production', 'staging', 'local', 'testing'];

function isAccountKey(value: unknown): value is RoleIdentity {
  return typeof value === 'string' && (ACCOUNT_KEYS as string[]).includes(value);
}

/**
 * Parse and validate a raw context payload.
 *
 * `hostname` is the browser's current hostname, passed in rather than read here so this
 * function stays pure and testable. Pass null to skip the cross-check (server-side render,
 * unit test, or a non-browser environment).
 */
export function resolveAccountContext(raw: unknown, hostname: string | null): AccountContextResult {
  // `null` means the block was absent; `undefined` means it was present but unparseable.
  // The distinction matters operationally: 'missing' points at the serving model (the page
  // was served without the Laravel shell), 'malformed' points at the payload.
  if (raw === null) {
    return { ok: false, failure: 'missing' };
  }
  if (raw === undefined || typeof raw !== 'object') {
    return { ok: false, failure: 'malformed' };
  }

  const payload = raw as Record<string, unknown>;
  const accountKey = payload['account_key'];

  if (typeof accountKey !== 'string' || accountKey === '') {
    return { ok: false, failure: 'malformed' };
  }
  if (!isAccountKey(accountKey)) {
    // An account key the frontend registry does not know means the two registries have
    // drifted. Failing closed is the only safe answer — guessing would render one
    // account's experience under another account's host.
    return { ok: false, failure: 'unknown_account' };
  }

  const environment = payload['environment'];
  if (typeof environment !== 'string' || !ENVIRONMENTS.includes(environment as AccountHostEnvironment)) {
    return { ok: false, failure: 'malformed' };
  }

  const definition = ACCOUNT_HOSTS[accountKey];

  // Consistency check. The server is the authority; the address bar is the witness. When
  // they disagree we refuse to render an account experience at all.
  if (hostname !== null && hostname !== '') {
    const browserAccount = accountKeyForHost(hostname);
    if (browserAccount !== null && browserAccount !== accountKey) {
      return { ok: false, failure: 'host_mismatch' };
    }
  }

  const displayName = payload['display_name'];
  const host = payload['host'];
  if (typeof displayName !== 'string' || typeof host !== 'string') {
    return { ok: false, failure: 'malformed' };
  }

  return {
    ok: true,
    context: {
      accountKey,
      displayName,
      host,
      environment: environment as AccountHostEnvironment,
      publicContentKey: definition.publicContentKey,
      legalContentKey: definition.legalContentKey,
      navigationPlacement: definition.navigationPlacement,
      routeNamePrefix: definition.routeNamePrefix,
      defaultAuthenticatedRoute: definition.defaultAuthenticatedRoute,
      requiresSetup: definition.requiresSetup,
      requiresMfa: definition.requiresMfa,
      roleFamily: definition.roleFamily,
      selfRegistration: definition.selfRegistration,
      invitationAcceptance: definition.invitationAcceptance,
      publicCtaCategory: definition.publicCtaCategory,
      definition,
    },
  };
}

/** Read the raw context payload the shell embedded, or null when it is absent/unparseable. */
export function readEmbeddedAccountContext(doc: Document = document): unknown {
  const element = doc.getElementById(ACCOUNT_CONTEXT_ELEMENT_ID);
  if (element === null || element.textContent === null || element.textContent.trim() === '') {
    return null;
  }
  try {
    return JSON.parse(element.textContent) as unknown;
  } catch {
    return undefined; // present but unparseable → 'malformed', not 'missing'
  }
}

let resolved: AccountContextResult | null = null;

/**
 * Resolve once, at boot, before the router makes any account-entry decision. Memoised so
 * every consumer sees the same answer for the life of the page.
 */
export function initAccountContext(doc: Document = document, hostname?: string | null): AccountContextResult {
  const browserHost = hostname === undefined ? (globalThis.location?.hostname ?? null) : hostname;

  resolved = resolveAccountContext(readEmbeddedAccountContext(doc), browserHost);

  return resolved;
}

/** The resolved result, or a `missing` failure when boot has not run. Never throws. */
export function accountContextResult(): AccountContextResult {
  return resolved ?? { ok: false, failure: 'missing' };
}

/** The resolved context, or null when it could not be established. */
export function currentAccountContext(): AccountContext | null {
  const result = accountContextResult();

  return result.ok ? result.context : null;
}

/** Test seam — resets the memoised result. */
export function resetAccountContext(): void {
  resolved = null;
}
