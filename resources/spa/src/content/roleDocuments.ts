import type { RoleIdentity } from '@/types/roles';
import { parseHeroBody, type FaqItem, type HeroContent } from './markdown';
import { loadGeneratedFaq, loadGeneratedLanding } from '@/content/generated/index.generated';
import type {
  GeneratedFaqDocument,
  GeneratedLandingDocument,
  GeneratedLandingSection,
} from '@/content/generated/contentTypes.generated';

/**
 * Lazily-loaded role landing + FAQ content.
 *
 * Phase 24 (PH24-BUNDLE-001) made these lazy so a signed-in role loads only its own two documents.
 * Phase UI-05 kept that property and changed the SOURCE: instead of `import.meta.glob(…?raw)`
 * discovering repository Markdown at build time, each document is a generated module produced by
 * `scripts/generate-role-content.mjs` from the same `docs/**` files, with its source hash recorded
 * and checked in CI (UI/UX plan §8.8).
 *
 * Two things improved as a consequence:
 *   * the FAQ is parsed ONCE at generation time rather than on every view, and
 *   * the parser is no longer level-sensitive, so Merchant Administrator's sixty `###` questions
 *     are compiled instead of silently dropped (UI05-FAQ-001).
 */

const heroCache = new Map<RoleIdentity, HeroContent>();
const faqCache = new Map<RoleIdentity, FaqItem[]>();

/** The role's compiled landing document: every plan region, present or recorded as missing. */
export async function loadLandingDocument(identity: RoleIdentity): Promise<GeneratedLandingDocument> {
  return loadGeneratedLanding(identity);
}

/** The role's compiled landing regions, in UI/UX plan §8.3 order. */
export async function loadLandingSections(
  identity: RoleIdentity,
): Promise<readonly GeneratedLandingSection[]> {
  return (await loadGeneratedLanding(identity)).sections;
}

/** Verbatim hero (headline + body) from the role's own landing document. */
export async function loadLandingHero(identity: RoleIdentity): Promise<HeroContent> {
  const cached = heroCache.get(identity);
  if (cached) return cached;

  const document = await loadGeneratedLanding(identity);
  const hero = document.sections.find((section) => section.region === 'hero');
  const content = parseHeroBody(hero?.markdown ?? '');
  heroCache.set(identity, content);
  return content;
}

/** The role's compiled FAQ document, with source provenance. */
export async function loadFaqDocument(identity: RoleIdentity): Promise<GeneratedFaqDocument> {
  return loadGeneratedFaq(identity);
}

/**
 * Verbatim FAQ items for the role, in source order, shaped for `SvFaq`.
 *
 * This is the adapter UI-04's structural component consumes; it adds no accordion of its own and
 * changes no wording. The richer compiled record (number, category, source lines) stays available
 * through `loadFaqDocument` for the final UI-06 route.
 */
export async function loadFaq(identity: RoleIdentity): Promise<FaqItem[]> {
  const cached = faqCache.get(identity);
  if (cached) return cached;

  const document = await loadGeneratedFaq(identity);
  const items = document.items.map((item) => ({
    id: item.id,
    question: item.question,
    answer: item.answer,
  }));
  faqCache.set(identity, items);
  return items;
}
