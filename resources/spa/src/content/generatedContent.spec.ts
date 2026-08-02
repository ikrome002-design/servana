import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import SvFaq from '@/components/ui/SvFaq.vue';
import SvLegalDocument from '@/components/ui/SvLegalDocument.vue';
import { CONTENT_MANIFEST, CONTENT_VERSION } from '@/content/generated/contentManifest.generated';
import {
  CONTENT_ACCOUNT_KEYS,
  CONTENT_CATEGORIES,
  loadGeneratedFaq,
  loadGeneratedLanding,
  loadGeneratedLegal,
} from '@/content/generated/index.generated';
import type { ContentAccountKey, ContentCategory } from '@/content/generated/contentTypes.generated';
import { LANDING_IMAGES, landingHeroImage, landingImagesFor } from '@/content/generated/landingImages.generated';
import { loadLegalDoc } from '@/content/legalContent';
import { loadFaq, loadLandingSections } from '@/content/roleDocuments';
import { ROLE_IDENTITIES, type RoleIdentity } from '@/types/roles';

/**
 * Phase UI-05 — the generated content and asset contract.
 *
 * These tests exist because the pipeline replaced build-time filesystem discovery with committed
 * artifacts. Committed artifacts can go stale, and a stale artifact looks exactly like a fresh one
 * at runtime — so the interesting properties are provenance (does the generated text still equal
 * the approved source?) and isolation (can one account ever receive another's content?), not
 * whether a string renders.
 */
/**
 * Read an approved source document straight off disk.
 *
 * Deliberately `path.join`, not `new URL(\`…${x}\`, import.meta.url)`: Vite statically rewrites the
 * latter into its asset-URL map when the specifier is a template literal, and an unmatched entry
 * resolves to `undefined` — which would make these provenance assertions read a file that does not
 * exist instead of the source they are supposed to compare against.
 */
const REPO_ROOT = join(dirname(fileURLToPath(import.meta.url)), '../../../..');
const REPO = (relative: string): string => readFileSync(join(REPO_ROOT, relative), 'utf8');

const LEGAL_TYPES = ['terms-of-service', 'privacy-policy', 'data-policy'] as const;
const LEGAL_CATEGORIES = ['terms_of_service', 'privacy_policy', 'data_policy'] as const;

describe('generated role content', () => {
  it('registers exactly eight accounts and five categories', () => {
    expect(CONTENT_ACCOUNT_KEYS).toHaveLength(8);
    expect(CONTENT_CATEGORIES).toHaveLength(5);
    expect([...CONTENT_ACCOUNT_KEYS].sort()).toEqual([...ROLE_IDENTITIES].sort());
    expect(CONTENT_MANIFEST.entries).toHaveLength(40);
    expect(CONTENT_MANIFEST.contentVersion).toBe(CONTENT_VERSION);
  });

  it('maps every account/category pair exactly once, with no duplicate source path', () => {
    const pairs = CONTENT_MANIFEST.entries.map((entry) => `${entry.accountKey}:${entry.category}`);
    const paths = CONTENT_MANIFEST.entries.map((entry) => entry.sourcePath);

    expect(new Set(pairs).size).toBe(40);
    expect(new Set(paths).size).toBe(40);
  });

  it('sources every document from its own account directory', () => {
    for (const entry of CONTENT_MANIFEST.entries) {
      expect(entry.sourcePath, `${entry.accountKey}/${entry.category}`).toContain(entry.accountKey);
      // A repository-relative path only — never an absolute workstation path.
      expect(entry.sourcePath).not.toMatch(/^([A-Za-z]:[\\/]|\/)/);
    }
  });

  it('carries a reproducible source timestamp, never a build clock', () => {
    expect(CONTENT_MANIFEST.sourceTimestamp).toMatch(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/);
    // A generation wall clock would be at or after "now" on every run.
    expect(Date.parse(CONTENT_MANIFEST.sourceTimestamp)).toBeLessThan(Date.now());
  });

  it('fails closed on an unknown account key rather than falling back', async () => {
    const unknown = 'not_a_role' as ContentAccountKey;

    await expect(loadGeneratedFaq(unknown)).rejects.toThrow(/not found/i);
    await expect(loadGeneratedLanding(unknown)).rejects.toThrow(/not found/i);
    await expect(loadGeneratedLegal(unknown, 'data_policy')).rejects.toThrow(/not found/i);
  });

  it('fails closed on an unknown content category', async () => {
    await expect(
      loadGeneratedLegal(
        'merchant_finance',
        'faq_but_not_legal' as unknown as (typeof LEGAL_CATEGORIES)[number],
      ),
    ).rejects.toThrow(/not found/i);
  });

  it('never returns one account the content of another', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const faq = await loadGeneratedFaq(identity);
      const landing = await loadGeneratedLanding(identity);

      expect(faq.meta.accountKey).toBe(identity);
      expect(landing.meta.accountKey).toBe(identity);
      expect(faq.meta.sourcePath).toBe(`docs/support/faq/${identity}_faq.md`);
      expect(landing.meta.sourcePath)
        .toBe(`docs/landing_page/${identity}_landing_page_content.md`);

      for (const item of faq.items) {
        expect(item.id.startsWith('faq-')).toBe(true);
      }
    }
  });
});

/**
 * The legal corpus is ~3 MB of string literals. Loading all twenty-four documents here would make
 * every worker transform it, which starves the rest of the suite — so this spec exercises three
 * representative accounts (one platform, one financial, one own-scope) across all three categories.
 *
 * That is not thinner coverage: `tests/Feature/Content/LegalContentVerbatimTest.php` proves all
 * TWENTY-FOUR documents byte for byte, and does it more rigorously — it decodes the generated
 * literal with PHP's own JSON decoder rather than through the same JavaScript module system that
 * produced it. The exhaustive proof lives in the suite where it costs nothing; what is worth
 * proving *here* is that the runtime loader returns the right bytes to a browser consumer.
 */
const LEGAL_SAMPLE = ['super_administrator', 'merchant_finance', 'merchant_personnel'] as const;

describe('legal content preservation', () => {
  it('reproduces a representative legal document byte for byte', async () => {
    for (const identity of LEGAL_SAMPLE) {
      for (let i = 0; i < LEGAL_TYPES.length; i += 1) {
        const category = LEGAL_CATEGORIES[i];
        const document = await loadGeneratedLegal(identity, category);
        const source = REPO(document.meta.sourcePath);

        expect(document.markdown, `${identity}/${category}`).toBe(source);
        expect(document.meta.sourceBytes).toBe(Buffer.byteLength(source, 'utf8'));
        expect(document.meta.accountKey).toBe(identity);
      }
    }
  });

  it('resolves the route document type to that role\'s own file', async () => {
    for (const identity of LEGAL_SAMPLE) {
      for (let i = 0; i < LEGAL_TYPES.length; i += 1) {
        const markdown = await loadLegalDoc(identity, LEGAL_TYPES[i]);
        const folder = LEGAL_CATEGORIES[i];
        expect(markdown).toBe(REPO(`docs/legal/${folder}/${identity}_${folder}.md`));
      }
    }
  });

  it('records all twenty-four legal documents in the manifest, whatever this spec loads', () => {
    // The sample above is a performance decision, not a coverage one — the CONTRACT is still that
    // every account has all three legal documents, and that is asserted from the manifest here.
    const legal = CONTENT_MANIFEST.entries.filter(
      (entry) => (LEGAL_CATEGORIES as readonly string[]).includes(entry.category),
    );

    expect(legal).toHaveLength(24);
    for (const identity of ROLE_IDENTITIES) {
      expect(legal.filter((entry) => entry.accountKey === identity)).toHaveLength(3);
    }
  });

  it('renders a legal document through the audited renderer without raw HTML or unsafe links', async () => {
    const document = await loadGeneratedLegal('merchant_finance', 'privacy_policy');
    const wrapper = mount(SvLegalDocument, {
      props: { title: 'Privacy Policy', markdown: document.markdown },
    });
    const html = wrapper.get('[data-testid="sv-legal-document"]').html();

    expect(wrapper.get('h1').text()).toBe('Privacy Policy');
    expect(html).not.toMatch(/href="javascript:/i);
    expect(html).not.toMatch(/<(script|iframe|object|embed)/i);
    expect(html).not.toMatch(/\son[a-z]+="/i);
    for (const href of html.match(/href="([^"]*)"/g) ?? []) {
      expect(href).toMatch(/^href="(https?:\/\/|mailto:|\/|#)/);
    }
  });
});

describe('compiled FAQ', () => {
  it('compiles every account\'s FAQ with stable, unique, source-derived ids', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const items = await loadFaq(identity);

      expect(items.length, identity).toBeGreaterThan(100);
      expect(new Set(items.map((item) => item.id)).size).toBe(items.length);
      for (const item of items) {
        expect(item.question.length).toBeGreaterThan(0);
        expect(item.answer.length).toBeGreaterThan(0);
      }
    }
  });

  it('compiles questions written at any heading level (UI05-FAQ-001)', async () => {
    // Merchant Administrator writes sixty of its questions at `###`. The previous `##`-only
    // runtime parser dropped every one of them.
    const document = await loadGeneratedFaq('merchant_administrator');
    const source = REPO(document.meta.sourcePath);
    const levelThree = source.match(/^###\s+\d+\.\d+\s+/gm) ?? [];

    expect(levelThree.length).toBe(60);
    expect(document.items.length).toBe(196);
    for (const item of document.items) {
      expect(source).toContain(item.question);
    }
  });

  it('preserves source order and wording verbatim', async () => {
    const document = await loadGeneratedFaq('merchant_audit');
    const source = REPO(document.meta.sourcePath);

    let cursor = -1;
    for (const item of document.items) {
      const at = source.indexOf(item.question, cursor);
      expect(at, item.question).toBeGreaterThan(cursor);
      cursor = at;
    }
  });

  it('renders through SvFaq with one disclosure per compiled item', async () => {
    const items = (await loadFaq('merchant_personnel')).slice(0, 12);
    const wrapper = mount(SvFaq, { props: { items, label: 'Personnel questions' } });

    expect(wrapper.findAll('details')).toHaveLength(12);
    expect(wrapper.findAll('summary')).toHaveLength(12);
    expect(wrapper.get('section').attributes('aria-label')).toBe('Personnel questions');
    expect(wrapper.findAll('details').map((node) => node.attributes('id')))
      .toEqual(items.map((item) => item.id));
  });
});

describe('compiled landing content', () => {
  it('records all sixteen plan regions for every account, present or missing', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const sections = await loadLandingSections(identity);

      expect(sections, identity).toHaveLength(16);
      expect(new Set(sections.map((section) => section.region)).size).toBe(16);
    }
  });

  it('never fabricates a section the source does not supply', async () => {
    for (const identity of ROLE_IDENTITIES) {
      for (const section of await loadLandingSections(identity)) {
        if (section.presence === 'missing_from_source') {
          expect(section.markdown).toBe('');
          expect(section.renderPermitted).toBe(false);
          expect(section.contentRestriction).toBe('product_owner_content_decision_required');
        } else {
          expect(section.markdown.length).toBeGreaterThan(0);
          expect(section.sourceHeading).toBeTruthy();
        }
      }
    }
  });

  it('withholds unverified customer evidence from rendering', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const sections = await loadLandingSections(identity);
      const testimonials = sections.find((section) => section.region === 'testimonials');

      // Either the section is absent, or it is present and carries a decision flag unless it is a
      // factual statement making no customer claim.
      expect(testimonials).toBeDefined();
      if (testimonials?.renderPermitted === true) {
        expect(testimonials.markdown).not.toMatch(/^>\s*\S/m);
      }
    }
  });

  it('carries every section body verbatim from its own source file', async () => {
    for (const identity of ROLE_IDENTITIES) {
      const source = REPO(`docs/landing_page/${identity}_landing_page_content.md`);
      for (const section of await loadLandingSections(identity)) {
        if (section.presence === 'present_in_source') {
          expect(source, `${identity}/${section.region}`).toContain(section.markdown);
        }
      }
    }
  });
});

describe('curated landing images', () => {
  it('selects two to four images per account, each from its own directory', () => {
    for (const identity of ROLE_IDENTITIES) {
      const images = landingImagesFor(identity);

      expect(images.length, identity).toBeGreaterThanOrEqual(2);
      expect(images.length, identity).toBeLessThanOrEqual(4);
      for (const image of images) {
        expect(image.sourcePublicPath)
          .toMatch(new RegExp(`^/assets/landing_page_images/${identity}/[^/]+$`));
      }
    }
  });

  it('never selects the same file twice or maps two images to one region', () => {
    expect(new Set(LANDING_IMAGES.map((image) => image.sourcePublicPath)).size)
      .toBe(LANDING_IMAGES.length);
    expect(new Set(LANDING_IMAGES.map((image) => `${image.accountKey}:${image.landingSection}`)).size)
      .toBe(LANDING_IMAGES.length);
  });

  it('describes every non-decorative image without using its file name', () => {
    for (const image of LANDING_IMAGES) {
      const filename = image.sourcePublicPath.split('/').pop() ?? '';

      if (image.decorative) {
        expect(image.alternativeText).toBe('');
      } else {
        expect(image.alternativeText.length).toBeGreaterThan(20);
        expect(image.alternativeText.toLowerCase()).not.toContain(filename.toLowerCase());
        expect(image.alternativeText.toLowerCase()).not.toContain('.png');
      }
    }
  });

  it('builds responsive candidates that never exceed the source', () => {
    for (const image of LANDING_IMAGES) {
      expect(image.derivatives.length).toBeGreaterThan(0);
      for (const derivative of image.derivatives) {
        expect(derivative.width).toBeLessThanOrEqual(image.intrinsicWidth);
        expect(derivative.height).toBeLessThanOrEqual(image.intrinsicHeight);
        expect(derivative.publicPath).toMatch(/^\/assets\/landing_page_images\/generated\//);
        expect(derivative.publicPath).toContain(`/${image.accountKey}/`);
        expect(derivative.publicPath.endsWith(`.${derivative.format}`)).toBe(true);
      }
      const formats = new Set(image.derivatives.map((derivative) => derivative.format));
      expect([...formats].sort()).toEqual(['avif', 'webp']);
    }
  });

  it('gives each account exactly one eager, high-priority hero and lazy-loads the rest', () => {
    for (const identity of ROLE_IDENTITIES) {
      const hero = landingHeroImage(identity);

      expect(hero, identity).not.toBeNull();
      expect(hero?.loading).toBe('eager');
      expect(hero?.fetchPriority).toBe('high');

      for (const image of landingImagesFor(identity)) {
        if (image.landingSection !== 'hero') {
          expect(image.loading).toBe('lazy');
          expect(image.fetchPriority).toBe('auto');
        }
      }
    }
  });

  it('claims no release approval before UI-06 reviews the pages', () => {
    for (const image of LANDING_IMAGES) {
      expect(image.releaseStatus).toBe('pending_ui06_visual_review');
    }
  });

  it('targets only regions the account\'s own landing content supplies', async () => {
    for (const identity of ROLE_IDENTITIES as RoleIdentity[]) {
      const sections = await loadLandingSections(identity);
      for (const image of landingImagesFor(identity)) {
        const section = sections.find((candidate) => candidate.region === image.landingSection);
        expect(section, `${identity}/${image.landingSection}`).toBeDefined();
        expect(section?.presence).toBe('present_in_source');
        expect(section?.imageCapable).toBe(true);
      }
    }
  });
});

describe('content categories', () => {
  it('names exactly the five canonical categories', () => {
    expect([...CONTENT_CATEGORIES]).toEqual([
      'landing', 'data_policy', 'privacy_policy', 'terms_of_service', 'faq',
    ] satisfies ContentCategory[]);
  });
});
