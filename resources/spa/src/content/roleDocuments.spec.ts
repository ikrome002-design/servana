import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { loadFaq, loadLandingHero } from '@/content/roleDocuments';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

/**
 * Phase 24 bundle-ownership guard (PH24-BUNDLE-001).
 *
 * The defect was structural, not cosmetic: `content/roleContent.ts` statically imported all eight
 * roles' landing + FAQ markdown, so every consumer of that module — including two that only wanted
 * the `LEGAL_DOCS` constant — shipped ~484 KB raw / ~145 KB gzip of other roles' copy.
 *
 * These assertions guard the OWNERSHIP invariant rather than an exact byte count, which would be
 * brittle across content edits and bundler upgrades:
 *
 *   1. the constants module must contain no markdown import at all;
 *   2. the documents module must load lazily (`import.meta.glob`), never statically;
 *   3. every role must resolve its own two documents, and only its own.
 */
const source = (relative: string): string =>
  readFileSync(fileURLToPath(new URL(relative, import.meta.url)), 'utf8');

describe('role document bundle ownership', () => {
  it('keeps the constants module free of markdown imports', () => {
    const roleContent = source('./roleContent.ts');

    // A static `?raw` markdown import here is exactly what caused PH24-BUNDLE-001.
    expect(roleContent).not.toMatch(/^\s*import\s+.*\.md\?raw/m);
    expect(roleContent).not.toContain('_landing_page_content.md');
    expect(roleContent).not.toContain('_faq.md');
  });

  it('loads role documents lazily, one document per role', () => {
    const roleDocuments = source('./roleDocuments.ts');

    expect(roleDocuments).toContain('import.meta.glob');
    // Two globs: landing and FAQ. Neither may be eager.
    expect(roleDocuments).not.toContain('eager: true');
    expect(roleDocuments).not.toMatch(/^\s*import\s+.*\.md\?raw/m);
  });

  it('resolves every role its own landing and FAQ document', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const hero = await loadLandingHero(identity);
      const faq = await loadFaq(identity);

      expect(hero.title.length, `${identity} hero`).toBeGreaterThan(0);
      expect(faq.length, `${identity} faq`).toBeGreaterThan(0);
    }
  });

  it('never resolves one role to another role\'s document', async () => {
    const heroes = await Promise.all(
      ROLE_IDENTITIES.map(async (identity) => (await loadLandingHero(identity)).title),
    );

    // Every role's hero headline is distinct, so no loader can be silently returning a sibling's
    // document (which a suffix-matching lookup could otherwise do).
    expect(new Set(heroes).size).toBe(ROLE_IDENTITIES.length);
  });

  it('rejects an unknown role identity instead of falling back', async () => {
    await expect(loadLandingHero('nope' as unknown as RoleIdentity)).rejects.toThrow(/not found/i);
    await expect(loadFaq('nope' as unknown as RoleIdentity)).rejects.toThrow(/not found/i);
  });
});
