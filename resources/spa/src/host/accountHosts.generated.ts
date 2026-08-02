// GENERATED FILE — do not edit by hand.
//
// Source:     config/account-hosts.json
// Generator:  node scripts/generate-account-hosts.mjs
// Verify:     node scripts/generate-account-hosts.mjs --check
//
// The eight Servana account hosts (ADR-016). This registry is PRESENTATION metadata.
// It never grants identity, membership, tenant, branch, role, permission or MFA state —
// the server re-evaluates all of those on every protected request (ADR-017).
//
// The browser must not decide its own account context from `window.location`. The
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

export const ACCOUNT_HOST_REGISTRY_VERSION = 1;

export const ACCOUNT_HOSTS: Record<RoleIdentity, AccountHostDefinition> = {
  super_administrator: {
    accountKey: 'super_administrator',
    displayName: "Super Administrator",
    hosts: {
      production: 'citrus.servana.ke',
      staging: 'citrus.servana.staging.citruslabs.co.ke',
      local: 'citrus.servana.test',
    },
    publicContentKey: 'super_administrator',
    legalContentKey: 'super_administrator',
    navigationPlacement: 'header',
    routeNamePrefix: 'platform',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: true,
    roleFamily: 'platform',
    selfRegistration: false,
    invitationAcceptance: false,
    publicCtaCategory: 'platform_sign_in',
  },
  merchant_administrator: {
    accountKey: 'merchant_administrator',
    displayName: "Merchant Administrator",
    hosts: {
      production: 'servana.ke',
      staging: 'servana.staging.citruslabs.co.ke',
      local: 'servana.test',
    },
    publicContentKey: 'merchant_administrator',
    legalContentKey: 'merchant_administrator',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'merchant',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: true,
    requiresMfa: true,
    roleFamily: 'merchant_owner',
    selfRegistration: true,
    invitationAcceptance: false,
    publicCtaCategory: 'self_registration',
  },
  merchant_branch: {
    accountKey: 'merchant_branch',
    displayName: "Branch Manager",
    hosts: {
      production: 'branch.servana.ke',
      staging: 'branch.servana.staging.citruslabs.co.ke',
      local: 'branch.servana.test',
    },
    publicContentKey: 'merchant_branch',
    legalContentKey: 'merchant_branch',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'branch',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: false,
    roleFamily: 'merchant_operational',
    selfRegistration: false,
    invitationAcceptance: true,
    publicCtaCategory: 'invitation_sign_in',
  },
  merchant_human_resource: {
    accountKey: 'merchant_human_resource',
    displayName: "Human Resource",
    hosts: {
      production: 'hr.servana.ke',
      staging: 'hr.servana.staging.citruslabs.co.ke',
      local: 'hr.servana.test',
    },
    publicContentKey: 'merchant_human_resource',
    legalContentKey: 'merchant_human_resource',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'hr',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: false,
    roleFamily: 'merchant_operational',
    selfRegistration: false,
    invitationAcceptance: true,
    publicCtaCategory: 'invitation_sign_in',
  },
  merchant_finance: {
    accountKey: 'merchant_finance',
    displayName: "Finance",
    hosts: {
      production: 'finance.servana.ke',
      staging: 'finance.servana.staging.citruslabs.co.ke',
      local: 'finance.servana.test',
    },
    publicContentKey: 'merchant_finance',
    legalContentKey: 'merchant_finance',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'finance',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: true,
    roleFamily: 'merchant_operational',
    selfRegistration: false,
    invitationAcceptance: true,
    publicCtaCategory: 'invitation_sign_in',
  },
  merchant_front_office: {
    accountKey: 'merchant_front_office',
    displayName: "Front Office",
    hosts: {
      production: 'office.servana.ke',
      staging: 'office.servana.staging.citruslabs.co.ke',
      local: 'office.servana.test',
    },
    publicContentKey: 'merchant_front_office',
    legalContentKey: 'merchant_front_office',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'front-office',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: false,
    roleFamily: 'merchant_operational',
    selfRegistration: false,
    invitationAcceptance: true,
    publicCtaCategory: 'invitation_sign_in',
  },
  merchant_personnel: {
    accountKey: 'merchant_personnel',
    displayName: "Personnel",
    hosts: {
      production: 'staff.servana.ke',
      staging: 'staff.servana.staging.citruslabs.co.ke',
      local: 'staff.servana.test',
    },
    publicContentKey: 'merchant_personnel',
    legalContentKey: 'merchant_personnel',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'personnel',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: false,
    roleFamily: 'merchant_own_scope',
    selfRegistration: false,
    invitationAcceptance: true,
    publicCtaCategory: 'invitation_sign_in',
  },
  merchant_audit: {
    accountKey: 'merchant_audit',
    displayName: "Audit",
    hosts: {
      production: 'audit.servana.ke',
      staging: 'audit.servana.staging.citruslabs.co.ke',
      local: 'audit.servana.test',
    },
    publicContentKey: 'merchant_audit',
    legalContentKey: 'merchant_audit',
    navigationPlacement: 'sidebar',
    routeNamePrefix: 'audit',
    defaultAuthenticatedRoute: '/dashboard',
    requiresSetup: false,
    requiresMfa: false,
    roleFamily: 'merchant_read_only',
    selfRegistration: false,
    invitationAcceptance: true,
    publicCtaCategory: 'invitation_sign_in',
  },
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
