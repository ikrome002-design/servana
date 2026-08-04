/**
 * Landing-composition loader (Phase UI-06; UI/UX plan §6.5, §22.1).
 *
 * ONE static dynamic import per account, exactly like the generated content loader. Static
 * specifiers are what let Vite emit eight separate chunks and let vue-tsc type them; a
 * template-built specifier would defeat both AND would mean a browser-supplied value could
 * influence which module is loaded, which is precisely the cross-role leak §17.1 forbids.
 *
 * Unknown keys throw. There is deliberately no fallback composition: rendering *some* account's
 * page when the requested one cannot be resolved is worse than rendering none.
 */

import type { ContentAccountKey } from '@/content/generated/contentTypes.generated';
import type { LandingComposition } from '@/content/landing/landingContract';

const LOADERS = {
  merchant_administrator: (): Promise<{ default: LandingComposition }> =>
    import('./accounts/merchantAdministrator'),
  merchant_audit: (): Promise<{ default: LandingComposition }> => import('./accounts/merchantAudit'),
  merchant_branch: (): Promise<{ default: LandingComposition }> => import('./accounts/merchantBranch'),
  merchant_finance: (): Promise<{ default: LandingComposition }> => import('./accounts/merchantFinance'),
  merchant_front_office: (): Promise<{ default: LandingComposition }> =>
    import('./accounts/merchantFrontOffice'),
  merchant_human_resource: (): Promise<{ default: LandingComposition }> =>
    import('./accounts/merchantHumanResource'),
  merchant_personnel: (): Promise<{ default: LandingComposition }> => import('./accounts/merchantPersonnel'),
  super_administrator: (): Promise<{ default: LandingComposition }> => import('./accounts/superAdministrator'),
} as const;

/** The eight account keys this loader can serve, in canonical order. */
export const LANDING_COMPOSITION_KEYS = Object.keys(LOADERS) as ContentAccountKey[];

/** Load one account's landing composition. Fails closed — never another account's. */
export async function loadLandingComposition(accountKey: ContentAccountKey): Promise<LandingComposition> {
  const loader = (LOADERS as Record<string, (() => Promise<{ default: LandingComposition }>) | undefined>)[
    accountKey
  ];

  if (loader === undefined) {
    throw new Error(`Landing composition not found — unknown account key: ${String(accountKey)}`);
  }

  const module = await loader();
  const composition = module.default;

  // Defence in depth: a module that declared the wrong key would silently publish one account's
  // page under another account's host. The loader refuses rather than trusting the import table.
  if (composition.accountKey !== accountKey) {
    throw new Error(
      `Landing composition mismatch — ${String(accountKey)} resolved a composition for ${composition.accountKey}`,
    );
  }

  return composition;
}
