import { ROLE_NAVIGATION } from './roleNavigation';
import { ROLE_IDENTITIES } from '@/types/roles';

/**
 * Deterministic YAML serialization of the role-navigation registry. The output
 * is the version-controlled fixture `docs/frontend/navigation/role-navigation.yaml`,
 * enforced by `roleNavigation.spec.ts` (vitest file snapshot).
 *
 * Phase UI-07: the registry is now itself derived from the canonical page contract, so this
 * fixture is a projection of a projection — a readable record of what each account's primary
 * navigation ACTUALLY renders. The contract, not this file, is the authority.
 */
export function serializeRoleNavigation(): string {
  const lines: string[] = [
    '# Servana role-navigation fixture (Phase UI-07; UI/UX plan §7).',
    '# Generated from resources/spa/src/navigation/roleNavigation.ts, which is itself derived',
    '# from docs/frontend/navigation/servana-user-account-navigation-map.yaml.',
    '# Enforced by roleNavigation.spec.ts (vitest file snapshot). Do not hand-edit.',
  ];

  for (const identity of ROLE_IDENTITIES) {
    lines.push(`${identity}:`);
    for (const item of ROLE_NAVIGATION[identity]) {
      lines.push(`  - key: ${item.key}`);
      lines.push(`    label: ${item.label}`);
      lines.push(`    availability: ${item.availability}`);
      if (item.routeName !== undefined) {
        lines.push(`    route: ${item.routeName}`);
      }
      if (item.permission !== undefined) {
        lines.push(`    permission: ${item.permission}`);
      }
      if (item.gate !== undefined) {
        lines.push(`    gate: ${item.gate}`);
      }
      lines.push(`    phase: ${item.phase}`);
    }
  }

  return `${lines.join('\n')}\n`;
}
