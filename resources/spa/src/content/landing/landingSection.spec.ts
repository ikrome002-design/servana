import { describe, expect, it } from 'vitest';
import { CONTENT_ACCOUNT_KEYS, loadGeneratedLanding } from '@/content/generated/index.generated';
import {
  classifyAnnotation,
  KNOWN_ANNOTATION_LABELS,
  parseLandingSection,
  plainText,
  type LandingBlock,
} from './landingSection';

/**
 * Phase UI-06 — the compiled-section parser.
 *
 * The parser decides which lines of an approved landing document are copy and which are production
 * annotations. Getting that wrong in either direction is a content-integrity defect: publish an
 * instruction and the page prints `**CTA:** **GET STARTED**` at a visitor; misclassify real copy and
 * the page silently loses a paragraph the product owner wrote.
 *
 * So the vocabulary is asserted against the REAL eight documents, not against fixtures. A source
 * edit that introduces an unknown label fails the build here rather than shipping.
 */

const ALL_LABELS = /^\*\*([^*]{1,60}?):\*\*/;

describe('annotation vocabulary', () => {
  it('classifies every label that appears in any compiled landing document', async () => {
    const unknown = new Set<string>();

    for (const account of CONTENT_ACCOUNT_KEYS) {
      const document = await loadGeneratedLanding(account);
      for (const section of document.sections) {
        for (const line of section.markdown.split('\n')) {
          const match = ALL_LABELS.exec(line.trim());
          if (match !== null && classifyAnnotation(match[1]) === null) {
            unknown.add(`${account}/${section.region}: ${match[1]}`);
          }
        }
      }
    }

    expect([...unknown]).toEqual([]);
  });

  it('places no label in two classes', () => {
    // A duplicate silently wins by list order, which is how `Preview labels` was dropped as an
    // instruction while also being declared as copy during this phase.
    const seen = new Map<string, number>();
    for (const label of KNOWN_ANNOTATION_LABELS) {
      seen.set(label, (seen.get(label) ?? 0) + 1);
    }

    expect([...seen.entries()].filter(([, count]) => count > 1)).toEqual([]);
  });

  it('classifies each known label into exactly one of the three classes', () => {
    for (const label of KNOWN_ANNOTATION_LABELS) {
      expect(classifyAnnotation(label)).not.toBeNull();
    }
  });
});

describe('parseLandingSection', () => {
  it('takes the first heading as the headline and every later heading as an item', () => {
    const parsed = parseLandingSection(
      '# Everything you need\n\n### Business overview\n\nSee daily activity.\n\n### Branch management\n\nCreate branches.',
    );

    expect(parsed.headline).toBe('Everything you need');
    expect(parsed.items.map((item) => item.title)).toEqual(['Business overview', 'Branch management']);
    expect(parsed.items[0].blocks).toEqual([{ kind: 'paragraph', markdown: 'See daily activity.' }]);
  });

  it('does not key off heading depth', () => {
    // The eight documents use #, ##, ### and #### for the same structural role. Keying off depth
    // works for some accounts and silently mis-nests others — the class of defect UI05-FAQ-001 was.
    const deep = parseLandingSection('### Section headline\n\n#### One\n\nBody one.\n\n#### Two\n\nBody two.');

    expect(deep.headline).toBe('Section headline');
    expect(deep.items).toHaveLength(2);
  });

  it('removes an instruction annotation and its body, and records it', () => {
    const parsed = parseLandingSection(
      '# Ready?\n\nStart today.\n\n**CTA:** **GET STARTED**\n\n**CTA Behavior:**\nWhen clicked, direct the user to login.',
    );

    expect(parsed.lead).toEqual([{ kind: 'paragraph', markdown: 'Start today.' }]);
    expect(parsed.droppedAnnotations).toContain('CTA');
    expect(parsed.droppedAnnotations).toContain('CTA Behavior');
  });

  it('drops a standalone all-caps CTA marker but keeps ordinary bold emphasis', () => {
    const parsed = parseLandingSection('# Title\n\n**GET STARTED**\n\n**Simple records. Clear roles.**');

    expect(parsed.droppedAnnotations).toContain('GET STARTED');
    expect(parsed.lead).toEqual([{ kind: 'paragraph', markdown: '**Simple records. Clear roles.**' }]);
  });

  it('keeps the copy an editorial label introduces, and drops only the label', () => {
    const parsed = parseLandingSection('# Title\n\n**Supporting line:** Create your account or log in.');

    expect(parsed.lead).toEqual([{ kind: 'paragraph', markdown: 'Create your account or log in.' }]);
    expect(parsed.droppedAnnotations).not.toContain('Supporting line');
  });

  it('keeps a content label and everything it introduces', () => {
    const parsed = parseLandingSection(
      '# Title\n\n**Security Highlights:**\nSecure platform access\nRole-based permissions\nMerchant data separation',
    );

    expect(parsed.lead).toEqual([
      {
        kind: 'labelled',
        label: 'Security Highlights',
        blocks: [
          {
            kind: 'list',
            items: ['Secure platform access', 'Role-based permissions', 'Merchant data separation'],
          },
        ],
      },
    ]);
  });

  it('reads a bold term followed by prose as a description pair', () => {
    const parsed = parseLandingSection(
      '# Title\n\n**Secure login**\nAccess your account through Magic Link.\n\n**Permission-based access**\nSee only what your role allows.',
    );

    expect(parsed.lead).toEqual([
      {
        kind: 'definitions',
        entries: [
          { term: 'Secure login', description: 'Access your account through Magic Link.' },
          { term: 'Permission-based access', description: 'See only what your role allows.' },
        ],
      },
    ]);
  });

  it('keeps a list open across the blank line the sources place inside it', () => {
    // Super Administrator's "Designed for:" run is split by a blank line; requiring three more
    // entries after it split one list into a list plus a paragraph of its own tail.
    const parsed = parseLandingSection(
      '# Title\n\n**Designed for:**\nBarbershops\nSalons\nSpas\nMassage parlours\n\nBeauty parlours\nGrooming studios',
    );
    const labelled = parsed.lead[0];

    expect(labelled.kind).toBe('labelled');
    if (labelled.kind === 'labelled') {
      expect(labelled.blocks[0]).toEqual({
        kind: 'list',
        items: ['Barbershops', 'Salons', 'Spas', 'Massage parlours', 'Beauty parlours', 'Grooming studios'],
      });
    }
  });

  it('strips emphasis markers from a heading without changing its words', () => {
    expect(plainText('**Keep every payment record clear, verified, and easier to trust.**'))
      .toBe('Keep every payment record clear, verified, and easier to trust.');
  });
});

describe('every publishable section of every account', () => {
  it('produces a headline and at least one block or item', async () => {
    // An empty rendered region would be a blank band on the page — the silent-omission failure
    // §15.2 forbids.
    const empty: string[] = [];

    for (const account of CONTENT_ACCOUNT_KEYS) {
      const document = await loadGeneratedLanding(account);
      for (const section of document.sections) {
        if (section.presence !== 'present_in_source' || !section.renderPermitted) {
          continue;
        }
        // Regions 1 and 16 are the real header and the real fixed footer, never rendered as prose.
        if (section.region === 'header_navigation' || section.region === 'footer') {
          continue;
        }

        const parsed = parseLandingSection(section.markdown);
        if (parsed.headline === null || (parsed.lead.length === 0 && parsed.items.length === 0)) {
          empty.push(`${account}/${section.region}`);
        }
      }
    }

    expect(empty).toEqual([]);
  });

  it('never leaves a call-to-action marker or an annotation label in the rendered copy', async () => {
    /*
     * The contract is about STRUCTURE, not vocabulary. A block whose entire text is `GET STARTED`
     * is a button the source drew in markdown and must not be printed as a sentence. A block that
     * MENTIONS the phrase inside real copy is real copy and must be preserved verbatim — Branch
     * asks "What happens when I click GET STARTED?" in its FAQ, and Human Resource's first step
     * says "Use GET STARTED to create a business account or log in." Removing either would be
     * editing approved copy.
     */
    const leaks: string[] = [];

    /** Every string the page would actually render from a parsed block tree. */
    const texts = (blocks: readonly LandingBlock[]): string[] =>
      blocks.flatMap((block) => {
        switch (block.kind) {
          case 'paragraph':
            return [block.markdown];
          case 'list':
            return [...block.items];
          case 'definitions':
            return block.entries.flatMap((entry) => [entry.term, entry.description]);
          case 'labelled':
            return [block.label, ...texts(block.blocks)];
        }
      });

    for (const account of CONTENT_ACCOUNT_KEYS) {
      const document = await loadGeneratedLanding(account);
      for (const section of document.sections) {
        if (section.presence !== 'present_in_source' || !section.renderPermitted) {
          continue;
        }
        if (section.region === 'header_navigation' || section.region === 'footer') {
          continue;
        }

        const parsed = parseLandingSection(section.markdown);
        const rendered = [
          ...texts(parsed.lead),
          ...parsed.items.flatMap((item) => [item.title, ...texts(item.blocks)]),
        ];

        for (const text of rendered) {
          const bare = plainText(text);
          // A standalone button marker.
          if (bare.length <= 30 && bare === bare.toUpperCase() && /[A-Z]/.test(bare)) {
            leaks.push(`${account}/${section.region}: standalone marker "${bare}"`);
          }
          // A surviving `**Label:**` annotation line.
          if (ALL_LABELS.test(text.trim())) {
            leaks.push(`${account}/${section.region}: annotation "${text.trim().slice(0, 40)}"`);
          }
        }
      }
    }

    expect(leaks).toEqual([]);
  });
});
