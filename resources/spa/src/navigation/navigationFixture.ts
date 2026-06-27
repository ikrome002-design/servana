import { ROLE_NAVIGATION } from './roleNavigation';
import { ROLE_IDENTITIES } from '@/types/roles';

/**
 * Deterministic YAML serialization of the role-navigation registry. The output
 * is the version-controlled fixture `docs/frontend/navigation/role-navigation.yaml`,
 * enforced by `roleNavigation.spec.ts` (vitest file snapshot). The registry is
 * the source of truth; the YAML is generated. Labels and ordering therefore
 * match the fixture exactly by construction, and any drift fails CI.
 */
export function serializeRoleNavigation(): string {
  const lines: string[] = [
    '# Servana role-navigation fixture (Phase 11, Plan §27.2).',
    '# Generated from resources/spa/src/navigation/roleNavigation.ts.',
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
      lines.push(`    phase: ${item.phase}`);
    }
  }

  return `${lines.join('\n')}\n`;
}
