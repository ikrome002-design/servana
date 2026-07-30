#!/usr/bin/env node
// Phase UI-02 — account-host registry generator (ADR-016, ADR-017).
//
// `config/account-hosts.json` is the single authority for the eight Servana account hosts.
// This script derives the consumers that cannot read PHP config at build time:
//
//   resources/spa/src/host/accountHosts.generated.ts   frontend typed registry
//   docker/nginx/account-hosts.generated.conf          nginx server_name allowlist
//
// Output is deterministic and sorted, so a second run produces no diff. It reads only the
// filesystem and never the network.
//
// Usage: node scripts/generate-account-hosts.mjs [--check]

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const CHECK_ONLY = process.argv.includes('--check');

const SOURCE = 'config/account-hosts.json';
const FRONTEND_TARGET = 'resources/spa/src/host/accountHosts.generated.ts';
const NGINX_TARGET = 'docker/nginx/account-hosts.generated.conf';

/** The eight canonical role identities, in the order `resources/spa/src/types/roles.ts` declares. */
const CANONICAL_ACCOUNT_KEYS = [
  'super_administrator',
  'merchant_administrator',
  'merchant_branch',
  'merchant_human_resource',
  'merchant_finance',
  'merchant_front_office',
  'merchant_personnel',
  'merchant_audit',
];

const source = JSON.parse(readFileSync(join(ROOT, SOURCE), 'utf8'));

const hostFor = (subdomain, domain) => (subdomain === null ? domain : `${subdomain}.${domain}`);

function validate() {
  const problems = [];
  const keys = source.accounts.map((account) => account.account_key);

  for (const key of CANONICAL_ACCOUNT_KEYS) {
    if (!keys.includes(key)) {
      problems.push(`missing canonical account key: ${key}`);
    }
  }
  for (const key of keys) {
    if (!CANONICAL_ACCOUNT_KEYS.includes(key)) {
      problems.push(`'${key}' is not a canonical account key`);
    }
  }
  if (new Set(keys).size !== keys.length) {
    problems.push('duplicate account_key');
  }

  // Exactly one apex account, and every subdomain distinct — otherwise two accounts would
  // answer on the same host and the resolver could not be deterministic.
  const apex = source.accounts.filter((account) => account.subdomain === null);
  if (apex.length !== 1) {
    problems.push(`expected exactly one apex account, found ${apex.length}`);
  }
  const subdomains = source.accounts.map((account) => account.subdomain).filter((s) => s !== null);
  if (new Set(subdomains).size !== subdomains.length) {
    problems.push('duplicate subdomain');
  }

  // Only the Super Administrator uses header navigation (ADR-020).
  for (const account of source.accounts) {
    const expected = account.account_key === 'super_administrator' ? 'header' : 'sidebar';
    if (account.navigation_placement !== expected) {
      problems.push(`${account.account_key}: navigation_placement must be '${expected}'`);
    }
    if (account.default_authenticated_route !== '/dashboard') {
      problems.push(`${account.account_key}: default_authenticated_route must be '/dashboard'`);
    }
  }

  return problems;
}

/** Every host an account answers on, across all three environments, deduplicated and sorted. */
function hostsForAccount(account) {
  return [
    hostFor(account.subdomain, source.domains.production),
    hostFor(account.subdomain, `servana.${source.domains.staging_suffix}`),
    hostFor(account.subdomain, source.domains.local),
  ];
}

function orderedAccounts() {
  const byKey = new Map(source.accounts.map((account) => [account.account_key, account]));

  return CANONICAL_ACCOUNT_KEYS.map((key) => byKey.get(key));
}

function buildFrontendRegistry() {
  const entries = orderedAccounts().map((account) => {
    const [production, staging, local] = hostsForAccount(account);

    return `  ${account.account_key}: {
    accountKey: '${account.account_key}',
    displayName: ${JSON.stringify(account.displayName ?? account.display_name)},
    hosts: {
      production: '${production}',
      staging: '${staging}',
      local: '${local}',
    },
    publicContentKey: '${account.public_content_key}',
    legalContentKey: '${account.legal_content_key}',
    navigationPlacement: '${account.navigation_placement}',
    routeNamePrefix: '${account.route_name_prefix}',
    defaultAuthenticatedRoute: '${account.default_authenticated_route}',
    requiresSetup: ${account.requires_setup},
    requiresMfa: ${account.requires_mfa},
    roleFamily: '${account.role_family}',
    selfRegistration: ${account.self_registration},
    invitationAcceptance: ${account.invitation_acceptance},
    publicCtaCategory: '${account.public_cta_category}',
  },`;
  });

  return `// GENERATED FILE — do not edit by hand.
//
// Source:     ${SOURCE}
// Generator:  node scripts/generate-account-hosts.mjs
// Verify:     node scripts/generate-account-hosts.mjs --check
//
// The eight Servana account hosts (ADR-016). This registry is PRESENTATION metadata.
// It never grants identity, membership, tenant, branch, role, permission or MFA state —
// the server re-evaluates all of those on every protected request (ADR-017).
//
// The browser must not decide its own account context from \`window.location\`. The
// backend resolves the context and hands it to the SPA; this registry exists so the SPA
// can VALIDATE that hand-off and fail closed on a mismatch.

import type { RoleIdentity } from '@/types/roles';

export type AccountHostEnvironment = 'production' | 'staging' | 'local';
export type NavigationPlacement = 'header' | 'sidebar';

export interface AccountHostDefinition {
  accountKey: RoleIdentity;
  displayName: string;
  hosts: Record<AccountHostEnvironment, string>;
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
}

export const ACCOUNT_HOST_REGISTRY_VERSION = ${source.version};

export const ACCOUNT_HOSTS: Record<RoleIdentity, AccountHostDefinition> = {
${entries.join('\n')}
};

/** All eight account keys, in canonical order. */
export const ACCOUNT_KEYS = Object.keys(ACCOUNT_HOSTS) as RoleIdentity[];

/** Every hostname any account answers on, across all environments. */
export const ALL_ACCOUNT_HOSTS: readonly string[] = Object.freeze(
  ACCOUNT_KEYS.flatMap((key) => Object.values(ACCOUNT_HOSTS[key].hosts)).sort(),
);

/**
 * Resolve an account key from a hostname. Used ONLY to cross-check the
 * server-provided context — never as the source of truth. Port and case are
 * normalised; anything unrecognised returns null so the caller fails closed.
 */
export function accountKeyForHost(hostname: string): RoleIdentity | null {
  const normalised = hostname.trim().toLowerCase().split(':')[0];
  if (!normalised) {
    return null;
  }
  for (const key of ACCOUNT_KEYS) {
    if (Object.values(ACCOUNT_HOSTS[key].hosts).includes(normalised)) {
      return key;
    }
  }

  return null;
}
`;
}

function buildNginxAllowlist() {
  const hosts = orderedAccounts().flatMap(hostsForAccount).sort();
  const lines = hosts.map((host) => `    ${host}`);

  return `# GENERATED FILE — do not edit by hand.
#
# Source:     ${SOURCE}
# Generator:  node scripts/generate-account-hosts.mjs
# Verify:     node scripts/generate-account-hosts.mjs --check
#
# The approved browser account hosts (ADR-016). Included inside the Servana server block.
# Any host NOT listed here is answered by the catch-all default_server, which closes the
# connection — the host allowlist is an entry-point control, never an authorization
# decision (ADR-017).
#
# ${hosts.length} hosts = 8 accounts x 3 environments (production, staging, local).

server_name
${lines.join('\n')}
    ;
`;
}

function writeArtifact(relPath, contents) {
  const absolute = join(ROOT, relPath);
  const existing = existsSync(absolute) ? readFileSync(absolute, 'utf8') : null;

  if (existing === contents) {
    return { path: relPath, changed: false };
  }
  if (!CHECK_ONLY) {
    mkdirSync(dirname(absolute), { recursive: true });
    writeFileSync(absolute, contents, 'utf8');
  }

  return { path: relPath, changed: true };
}

const problems = validate();
if (problems.length > 0) {
  console.error('Account-host registry FAILED validation:\n');
  for (const problem of problems) {
    console.error(`  - ${problem}`);
  }
  process.exit(1);
}

const written = [
  writeArtifact(FRONTEND_TARGET, buildFrontendRegistry()),
  writeArtifact(NGINX_TARGET, buildNginxAllowlist()),
];
const changed = written.filter((artifact) => artifact.changed);

if (CHECK_ONLY && changed.length > 0) {
  console.error('Account-host artifacts are STALE. Re-run: node scripts/generate-account-hosts.mjs\n');
  for (const artifact of changed) {
    console.error(`  - ${artifact.path}`);
  }
  process.exit(1);
}

const hostCount = orderedAccounts().flatMap(hostsForAccount).length;
console.log(`Account-host registry OK — ${source.accounts.length} accounts, ${hostCount} hosts.`);
for (const account of orderedAccounts()) {
  const [production, staging, local] = hostsForAccount(account);
  console.log(`  ${account.account_key.padEnd(24)} ${production.padEnd(24)} ${local.padEnd(26)} ${staging}`);
}
console.log(CHECK_ONLY ? 'All artifacts up to date.' : `${changed.length} artifact(s) written, ${written.length - changed.length} unchanged.`);
