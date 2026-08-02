import type { MerchantRole } from './enums';

/**
 * Canonical role mapping (Phase 11, Plan §27.2, §19.1).
 *
 * Backend roles come from the `/me` bootstrap: the seven merchant membership
 * roles (`MerchantRole`) plus the platform `super_admin`, which is not a
 * merchant membership — it is resolved from `is_platform_staff`. These keys are
 * the in-code source of truth and are matched verbatim against the
 * version-controlled fixtures and the permission matrix; do NOT invent aliases
 * or a role priority. This mapping is UX-only routing; the API is the security
 * boundary (Plan §2.1 rule 10).
 */
export type BackendRole = MerchantRole | 'super_admin';

/**
 * Content/layout identity used by docs assets (landing copy, FAQ, legal, images
 * under `docs/landing_page`, `docs/support/faq`, `docs/legal`,
 * `public/assets/landing_page_images`). These are the `{role}` folder names.
 */
export type RoleIdentity =
  | 'super_administrator'
  | 'merchant_administrator'
  | 'merchant_branch'
  | 'merchant_human_resource'
  | 'merchant_finance'
  | 'merchant_front_office'
  | 'merchant_personnel'
  | 'merchant_audit';

/** The single canonical backend-role → content/layout-identity map. */
export const ROLE_IDENTITY: Record<BackendRole, RoleIdentity> = {
  super_admin: 'super_administrator',
  merchant_admin: 'merchant_administrator',
  branch_manager: 'merchant_branch',
  hr: 'merchant_human_resource',
  finance: 'merchant_finance',
  front_office: 'merchant_front_office',
  personnel: 'merchant_personnel',
  audit: 'merchant_audit',
};

/** Primary-navigation placement (mandatory rule). Only Super Admin uses header. */
export type NavPlacement = 'header' | 'sidebar';

export interface RoleEntry {
  identity: RoleIdentity;
  backendRole: BackendRole;
  /** Human label for the role identity (sentence/title case, UX surface). */
  label: string;
  /** Layout component shell that hosts this role's authenticated surfaces. */
  layout: string;
  /** Where the primary dashboard navigation lives for this role. */
  navPlacement: NavPlacement;
  /** Live landing route name (role home after login). */
  landingRouteName: string;
  /** Live guided get-started route name. */
  getStartedRouteName: string;
}

/**
 * Deterministic per-role entry surfaces (Plan §27.2). The landing/get-started
 * route names are real routes registered in `router/routes/roleEntry.ts`.
 */
export const ROLE_ENTRY: Record<RoleIdentity, RoleEntry> = {
  super_administrator: {
    identity: 'super_administrator',
    backendRole: 'super_admin',
    label: 'Super Administrator',
    layout: 'PlatformAdminLayout',
    navPlacement: 'header',
    landingRouteName: 'platform.landing',
    getStartedRouteName: 'platform.get-started',
  },
  merchant_administrator: {
    identity: 'merchant_administrator',
    backendRole: 'merchant_admin',
    label: 'Merchant Administrator',
    layout: 'MerchantLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'merchant.landing',
    getStartedRouteName: 'merchant.get-started',
  },
  merchant_branch: {
    identity: 'merchant_branch',
    backendRole: 'branch_manager',
    label: 'Branch Manager',
    layout: 'BranchLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'branch.landing',
    getStartedRouteName: 'branch.get-started',
  },
  merchant_human_resource: {
    identity: 'merchant_human_resource',
    backendRole: 'hr',
    // Phase UI-04 (UI01-NAV-002): the account is Human Resource. "HR" is an abbreviation, and the
    // shell label is what a user reads to know which account they are operating in.
    label: 'Human Resource',
    // Was BranchLayout — the one account that presented under another account's identity.
    layout: 'HumanResourceLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'hr.landing',
    getStartedRouteName: 'hr.get-started',
  },
  merchant_finance: {
    identity: 'merchant_finance',
    backendRole: 'finance',
    label: 'Finance',
    layout: 'FinanceLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'finance.landing',
    getStartedRouteName: 'finance.get-started',
  },
  merchant_front_office: {
    identity: 'merchant_front_office',
    backendRole: 'front_office',
    label: 'Front Office',
    layout: 'FrontOfficeLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'front-office.landing',
    getStartedRouteName: 'front-office.get-started',
  },
  merchant_personnel: {
    identity: 'merchant_personnel',
    backendRole: 'personnel',
    label: 'Personnel',
    layout: 'PersonnelLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'personnel.landing',
    getStartedRouteName: 'personnel.get-started',
  },
  merchant_audit: {
    identity: 'merchant_audit',
    backendRole: 'audit',
    label: 'Audit',
    layout: 'AuditLayout',
    navPlacement: 'sidebar',
    landingRouteName: 'audit.landing',
    getStartedRouteName: 'audit.get-started',
  },
};

/** All eight role identities, in canonical order. */
export const ROLE_IDENTITIES = Object.keys(ROLE_ENTRY) as RoleIdentity[];

/**
 * Resolve the active role identity from bootstrap state (UX only). A platform
 * staff user maps to `super_administrator`; otherwise the active membership's
 * role maps through `ROLE_IDENTITY`. Returns null when no role can be resolved
 * (unsupported/unknown role → caller renders the unsupported-role boundary).
 */
export function resolveRoleIdentity(input: {
  isPlatformStaff: boolean;
  membershipRole: MerchantRole | null | undefined;
}): RoleIdentity | null {
  if (input.isPlatformStaff) {
    return ROLE_IDENTITY.super_admin;
  }
  if (input.membershipRole && input.membershipRole in identityByMerchantRole) {
    return identityByMerchantRole[input.membershipRole];
  }
  return null;
}

const identityByMerchantRole: Record<MerchantRole, RoleIdentity> = {
  merchant_admin: 'merchant_administrator',
  branch_manager: 'merchant_branch',
  hr: 'merchant_human_resource',
  finance: 'merchant_finance',
  front_office: 'merchant_front_office',
  personnel: 'merchant_personnel',
  audit: 'merchant_audit',
};
