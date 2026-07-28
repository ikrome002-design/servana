import type { RoleIdentity } from '@/types/roles';
import { parseFaq, parseHero, type FaqItem, type HeroContent } from './markdown';

/**
 * Lazily-loaded role landing + FAQ documents (Phase 24, PH24-BUNDLE-001).
 *
 * These were previously sixteen STATIC `?raw` imports (eight landing + eight FAQ) inside
 * `content/roleContent.ts`. Because the imports were static, every consumer of that module pulled
 * ALL EIGHT roles' landing and FAQ text into the authenticated bundle, no matter which single role
 * was signed in — including two components that only ever needed the `LEGAL_DOCS` constant.
 *
 * This mirrors the pattern `content/legalContent.ts` already uses for the ~3 MB of legal text:
 * `import.meta.glob` gives Vite one lazily-fetched chunk per document, so a signed-in role loads
 * exactly its own two documents. The markdown remains the single source of truth in `docs/**` —
 * still never hand-copied into frontend source, and still verbatim (Plan §27.2; CLAUDE.md §3).
 */
const landingLoaders = import.meta.glob<string>('../../../../docs/landing_page/*_landing_page_content.md', {
  query: '?raw',
  import: 'default',
});

const faqLoaders = import.meta.glob<string>('../../../../docs/support/faq/*_faq.md', {
  query: '?raw',
  import: 'default',
});

/**
 * Resolve the one loader whose path ends with `suffix`.
 *
 * The lookup is by suffix rather than by a constructed absolute key because Vite normalises glob
 * keys differently between dev, build and Vitest — the same reason `legalContent.ts` matches on
 * `endsWith`.
 */
function loaderFor(
  loaders: Record<string, () => Promise<string>>,
  suffix: string,
  what: string,
): () => Promise<string> {
  const key = Object.keys(loaders).find((k) => k.endsWith(suffix));
  if (!key) {
    throw new Error(`Role ${what} document not found: ${suffix}`);
  }
  return loaders[key];
}

const heroCache = new Map<RoleIdentity, HeroContent>();
const faqCache = new Map<RoleIdentity, FaqItem[]>();

/** Verbatim hero (headline + body) parsed from the role's own landing document. */
export async function loadLandingHero(identity: RoleIdentity): Promise<HeroContent> {
  const cached = heroCache.get(identity);
  if (cached) return cached;

  const raw = await loaderFor(
    landingLoaders,
    `/${identity}_landing_page_content.md`,
    'landing',
  )();
  const hero = parseHero(raw);
  heroCache.set(identity, hero);
  return hero;
}

/** Verbatim FAQ items parsed from the role's own FAQ document. */
export async function loadFaq(identity: RoleIdentity): Promise<FaqItem[]> {
  const cached = faqCache.get(identity);
  if (cached) return cached;

  const raw = await loaderFor(faqLoaders, `/${identity}_faq.md`, 'FAQ')();
  const items = parseFaq(raw);
  faqCache.set(identity, items);
  return items;
}
