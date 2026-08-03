/**
 * Compiled landing-section parser (Phase UI-06; UI/UX plan §8.3, §17.2).
 *
 * UI-05 compiled each account's landing document into sixteen typed regions whose `markdown` is the
 * source body VERBATIM. That body is not pure prose: the approved documents interleave user-facing
 * copy with production annotations written for whoever builds the page — `**CTA:** **GET STARTED**`,
 * `**Navigation Links:** …`, `**Best Hero Image Direction:** …`, `**Supporting line:** …`.
 *
 * Rendering that markdown straight through would publish build instructions as body copy. Rewriting
 * the source to remove them is forbidden (CLAUDE.md §9; the source is product-owner authority). So
 * this module CLASSIFIES lines instead: it never changes a single word, it only decides where each
 * line belongs and which annotations are instructions to the builder rather than copy for a reader.
 *
 * Three properties make that safe rather than lossy:
 *
 *  1. The annotation vocabulary is CLOSED. Every `**Label:**` appearing in any of the eight
 *     compiled documents is classified exactly once below, and `landingSection.spec.ts` fails when
 *     a label appears in the sources that this file does not classify. A future source edit
 *     therefore breaks the build instead of silently rendering an instruction — or silently
 *     swallowing real copy.
 *  2. Nothing is dropped anonymously. Every removed annotation is reported in `droppedAnnotations`,
 *     and the parity artifact records them per account and region.
 *  3. It is pure. Given the compiled markdown it returns a structure; it reads no globals, performs
 *     no I/O, and is exercised directly against the real generated content in its spec.
 */

/** A label whose content is an instruction to the page builder, not copy for a reader. */
const INSTRUCTION_LABELS: readonly string[] = [
  // Calls to action. The real CTA contract is resolved from the account-host registry and live
  // route names (§20), so the source's CTA notes must not also be printed as text.
  'cta',
  'cta behavior',
  'cta behaviour',
  'cta destination logic',
  'cta for each plan',
  'cta microcopy',
  'cta below preview',
  'primary cta',
  'primary cta button',
  'primary cta label',
  'secondary cta',
  'secondary link',
  'secondary text link',
  'optional secondary link',
  'footer cta',
  'login link',
  // Header furniture. The real header renders the approved logo, the generated in-page navigation
  // and the resolved CTAs.
  'logo',
  'navigation links',
  'right side',
  // Art direction and search metadata: instructions to a designer or a crawler, never page copy.
  'best hero image direction',
  'suggested visual content for this section',
  'suggested visual direction for this section',
  'meta title',
  'meta description',
  'audience',
  // Testimonial scaffolding. These sit only inside the non-renderable testimonials regions, which
  // UI-06 never publishes (binding decision §2.1); classified so the vocabulary stays complete.
  'testimonial placeholder',
  'name',
  'business type',
  'note',
  'reason',
];

/**
 * A label that introduces real copy but is itself an editorial note ("Supporting line:").
 * The label is removed; every word after it is kept and rendered.
 */
const COPY_LABELS: readonly string[] = [
  'supporting line',
  'small trust line',
  'small note',
  'short supporting text',
  'showcase caption',
  'outcome-focused line',
  'main value line',
  'dashboard preview copy',
  'section copy',
  'microcopy',
  'microcopy below cta',
  'hero microcopy',
  'closing microcopy',
  'footer line',
  'footer closing line',
  'footer note',
  'trust message',
  'in simple terms',
  // Unbolded editorial lead-ins. The line introduces real copy and is itself a note to whoever
  // builds the page, so the note goes and every word it introduces stays.
  'preview labels',
  'suggested showcase copy beside the product preview',
  'suggested testimonial direction once real customer quotes are available',
];

/**
 * A label that is genuinely part of the page ("Security Highlights:", "Designed for:").
 * Both the label and everything it introduces are rendered.
 */
const CONTENT_LABELS: readonly string[] = [
  'designed for',
  'trust highlights',
  'trust points',
  'security highlights',
  'security messages',
  'trusted workflows for',
  'what you get with servana',
  'you can',
  'pricing can be structured around',
  'common frustrations servana helps reduce',
  'common issues servana helps reduce',
  'servana helps you',
  'servana is made for real service teams',
  'platform access',
  'footer links',
  'legal',
  'company',
  'product',
  'resources',
  'help center',
];

export type AnnotationClass = 'instruction' | 'copy' | 'content';

/** How a `**Label:**` annotation is treated, or null when the label is not one we know. */
export function classifyAnnotation(label: string): AnnotationClass | null {
  const key = label.trim().toLowerCase();

  if (INSTRUCTION_LABELS.includes(key)) {
    return 'instruction';
  }
  if (COPY_LABELS.includes(key)) {
    return 'copy';
  }
  if (CONTENT_LABELS.includes(key)) {
    return 'content';
  }

  return null;
}

/** Every label the vocabulary knows, for the completeness contract in the spec. */
export const KNOWN_ANNOTATION_LABELS: readonly string[] = Object.freeze([
  ...INSTRUCTION_LABELS,
  ...COPY_LABELS,
  ...CONTENT_LABELS,
]);

export interface LandingDefinition {
  readonly term: string;
  readonly description: string;
}

export type LandingBlock =
  | { readonly kind: 'paragraph'; readonly markdown: string }
  | { readonly kind: 'list'; readonly items: readonly string[] }
  | { readonly kind: 'definitions'; readonly entries: readonly LandingDefinition[] }
  | { readonly kind: 'labelled'; readonly label: string; readonly blocks: readonly LandingBlock[] };

export interface LandingSectionItem {
  /** Stable, deterministic id so an item can be keyed and deep-linked. */
  readonly id: string;
  readonly title: string;
  readonly blocks: readonly LandingBlock[];
}

export interface ParsedLandingSection {
  /** The section's own headline, plain text. Null when the source supplies none. */
  readonly headline: string | null;
  /** Everything before the first sub-heading. */
  readonly lead: readonly LandingBlock[];
  /** One entry per sub-heading — features, steps, benefits, use cases, FAQ questions. */
  readonly items: readonly LandingSectionItem[];
  /** Instruction labels removed from the rendered output, in source order. */
  readonly droppedAnnotations: readonly string[];
}

const HEADING = /^(#{1,6})\s+(.*)$/;
const BOLD_ANNOTATION = /^\*\*([^*]{1,60}?):\*\*\s*(.*)$/;
/**
 * The unbolded annotation form. Deliberately a CLOSED list rather than a shape: the sources also
 * contain ordinary prose lines that end in a colon ("Open Servana and see what matters:"), and a
 * shape-based rule would eat them.
 */
const BARE_ANNOTATION =
  /^(Secondary link|CTA behaviou?r|CTA below preview|Preview labels|Reason|Suggested [^:]{1,80})\s*:\s*(.*)$/i;
const BULLET = /^[-*]\s+(.*)$/;
const BOLD_TITLE = /^\*\*([^*]+)\*\*$/;
const HORIZONTAL_RULE = /^(-{3,}|\*{3,}|_{3,})$/;

/** Remove inline emphasis markers so a heading reads as text. Wording is never altered. */
export function plainText(markdown: string): string {
  return markdown
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/\*([^*]+)\*/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .trim();
}

/** Deterministic id from a heading or question. Matches the shared `slugify` shape. */
function slug(text: string): string {
  return plainText(text)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64);
}

/**
 * A short line with no terminal punctuation reads as a list entry rather than a sentence. The
 * sources use bare runs of these for "Designed for:" and the security highlight lists.
 */
function looksLikeListEntry(line: string): boolean {
  return line.length <= 60 && !/[.!?:]$/.test(line);
}

interface Line {
  readonly raw: string;
  readonly text: string;
}

/** Parse a run of lines into blocks. Used for a section's lead, an item body and a labelled group. */
function parseBlocks(lines: readonly Line[], dropped: string[]): LandingBlock[] {
  const blocks: LandingBlock[] = [];
  let paragraph: string[] = [];
  let list: string[] = [];
  let definitions: LandingDefinition[] = [];

  const flushParagraph = (): void => {
    if (paragraph.length > 0) {
      blocks.push({ kind: 'paragraph', markdown: paragraph.join(' ') });
      paragraph = [];
    }
  };
  const flushList = (): void => {
    if (list.length > 0) {
      blocks.push({ kind: 'list', items: [...list] });
      list = [];
    }
  };
  const flushDefinitions = (): void => {
    if (definitions.length > 0) {
      blocks.push({ kind: 'definitions', entries: [...definitions] });
      definitions = [];
    }
  };
  const flushAll = (): void => {
    flushParagraph();
    flushList();
    flushDefinitions();
  };

  for (let index = 0; index < lines.length; index += 1) {
    const { text } = lines[index];

    if (text === '' || HORIZONTAL_RULE.test(text)) {
      // A blank line ends a paragraph but not a list or definition run: the sources separate list
      // entries and term/description pairs with blank lines.
      flushParagraph();
      continue;
    }

    const annotation = BOLD_ANNOTATION.exec(text) ?? BARE_ANNOTATION.exec(text);
    if (annotation !== null) {
      const label = annotation[1].trim();
      const inline = annotation[2].trim();
      const classification = classifyAnnotation(label);

      // An unknown label is treated as an instruction and reported. Publishing an unrecognised
      // `**Something:**` as body copy is the failure mode worth avoiding; the spec turns the
      // unknown label into a build failure so it is never merely tolerated.
      if (classification === null || classification === 'instruction') {
        flushAll();
        dropped.push(label);
        index = skipAnnotationBody(lines, index, inline);
        continue;
      }

      if (classification === 'copy') {
        flushAll();
        if (inline !== '') {
          blocks.push({ kind: 'paragraph', markdown: inline });
          continue;
        }
        // The label sat alone; its copy is the run that follows.
        const [body, next] = takeAnnotationBody(lines, index);
        blocks.push(...parseBlocks(body, dropped));
        index = next;
        continue;
      }

      flushAll();
      const [body, next] = takeAnnotationBody(lines, index);
      const inlineBlocks: LandingBlock[] = inline === ''
        ? []
        : [{ kind: 'paragraph', markdown: inline }];
      blocks.push({
        kind: 'labelled',
        label,
        blocks: [...inlineBlocks, ...parseBlocks(body, dropped)],
      });
      index = next;
      continue;
    }

    const bullet = BULLET.exec(text);
    if (bullet !== null) {
      flushParagraph();
      flushDefinitions();
      list.push(bullet[1].trim());
      continue;
    }

    const boldTitle = BOLD_TITLE.exec(text);
    if (boldTitle !== null) {
      // A standalone ALL-CAPS bold line is a call-to-action marker the source wrote inline
      // (`**GET STARTED**`), not copy. The real CTA is resolved from the registry and the live
      // routes, so printing the marker as a paragraph would show a button-shaped sentence that
      // does nothing. Recorded, never silently discarded.
      const bold = boldTitle[1].trim();
      if (bold.length <= 30 && bold === bold.toUpperCase() && /[A-Z]/.test(bold)) {
        flushAll();
        dropped.push(bold);
        continue;
      }

      flushParagraph();
      flushList();
      // A bold line followed by prose is a term/description pair; a bold line alone is an emphasis
      // line and stays a paragraph.
      const description = nextProseLine(lines, index);
      if (description === null) {
        flushDefinitions();
        blocks.push({ kind: 'paragraph', markdown: text });
        continue;
      }
      definitions.push({ term: boldTitle[1].trim(), description: description.text });
      index = description.index;
      continue;
    }

    // A run of three or more short, unpunctuated lines is a list the source wrote without markers.
    // Once such a list is open it keeps absorbing list-like lines: several sources place a blank
    // line inside the run, and requiring three MORE entries after it would split one list into a
    // list plus a paragraph of its own tail.
    if (looksLikeListEntry(text) && (list.length > 0 || countListRun(lines, index) >= 3)) {
      flushParagraph();
      flushDefinitions();
      list.push(text);
      continue;
    }

    flushList();
    flushDefinitions();
    paragraph.push(text);
  }

  flushAll();

  return blocks;
}

/** How many consecutive lines from `start` look like unmarked list entries. */
function countListRun(lines: readonly Line[], start: number): number {
  let count = 0;
  for (let index = start; index < lines.length; index += 1) {
    const { text } = lines[index];
    if (text === '' || !looksLikeListEntry(text) || BOLD_TITLE.test(text)) {
      break;
    }
    count += 1;
  }

  return count;
}

/** The next non-blank line after `index`, when it is ordinary prose. */
function nextProseLine(lines: readonly Line[], index: number): { text: string; index: number } | null {
  for (let cursor = index + 1; cursor < lines.length; cursor += 1) {
    const { text } = lines[cursor];
    if (text === '') {
      continue;
    }
    if (
      HEADING.test(text) || BULLET.test(text) || BOLD_TITLE.test(text)
      || BOLD_ANNOTATION.test(text) || BARE_ANNOTATION.test(text) || HORIZONTAL_RULE.test(text)
    ) {
      return null;
    }

    return { text, index: cursor };
  }

  return null;
}

/**
 * The lines an annotation introduces: its own indented run, allowing the blank line the sources
 * place between a label and a bulleted list. Stops at the next annotation or heading.
 */
function takeAnnotationBody(lines: readonly Line[], index: number): [Line[], number] {
  const body: Line[] = [];
  let cursor = index + 1;
  let started = false;

  while (cursor < lines.length) {
    const { text } = lines[cursor];

    if (text === '' || HORIZONTAL_RULE.test(text)) {
      if (started && !startsContinuation(lines, cursor)) {
        break;
      }
      cursor += 1;
      continue;
    }
    if (HEADING.test(text) || BOLD_ANNOTATION.test(text) || BARE_ANNOTATION.test(text)) {
      break;
    }

    started = true;
    body.push(lines[cursor]);
    cursor += 1;
  }

  return [body, cursor - 1];
}

/** True when the run after a blank line still belongs to the annotation above it. */
function startsContinuation(lines: readonly Line[], cursor: number): boolean {
  for (let index = cursor; index < lines.length; index += 1) {
    const { text } = lines[index];
    if (text === '' || HORIZONTAL_RULE.test(text)) {
      continue;
    }

    return BULLET.test(text) || BOLD_TITLE.test(text) || looksLikeListEntry(text);
  }

  return false;
}

/** Skip an instruction annotation and everything it introduces. Returns the last consumed index. */
function skipAnnotationBody(lines: readonly Line[], index: number, inline: string): number {
  if (inline !== '') {
    return index;
  }

  const [, next] = takeAnnotationBody(lines, index);

  return next;
}

/**
 * Parse one compiled landing region.
 *
 * The FIRST heading in the body is the section's headline; every later heading opens an item. That
 * rule holds across all eight documents, which use `#`, `##`, `###` and `####` inconsistently for
 * the same structural role — keying off heading DEPTH would work for some accounts and silently
 * mis-nest others, which is exactly the class of defect `UI05-FAQ-001` was.
 */
export function parseLandingSection(markdown: string): ParsedLandingSection {
  const lines: Line[] = markdown
    .replace(/\r\n/g, '\n')
    .split('\n')
    .map((raw) => ({ raw, text: raw.trim() }));

  const dropped: string[] = [];
  let headline: string | null = null;
  const leadLines: Line[] = [];
  const items: { title: string; lines: Line[] }[] = [];

  for (const line of lines) {
    const heading = HEADING.exec(line.text);

    if (heading !== null) {
      const title = plainText(heading[2]);
      if (headline === null && items.length === 0) {
        headline = title;
        continue;
      }
      items.push({ title, lines: [] });
      continue;
    }

    if (items.length === 0) {
      leadLines.push(line);
      continue;
    }
    items[items.length - 1].lines.push(line);
  }

  // A section that opens with prose and only later carries a heading (Human Resource's trust
  // statement does) keeps that prose as its lead rather than losing it.
  const lead = parseBlocks(leadLines, dropped);

  return {
    headline,
    lead,
    items: items.map((item) => ({
      id: slug(item.title),
      title: item.title,
      blocks: parseBlocks(item.lines, dropped),
    })),
    droppedAnnotations: dropped,
  };
}
