/**
 * Minimal, dependency-free markdown helpers for rendering APPROVED role content
 * sourced verbatim from `docs/**` via `?raw` imports (Phase 11, Plan §27.2).
 * Content is trusted (our own version-controlled docs); HTML is still escaped
 * before inline formatting is applied. No external markdown dependency is added
 * (keeps `npm audit` clean and the bundle small).
 */

export interface FaqItem {
  /** Stable id derived from the question (for accordion aria wiring). */
  id: string;
  question: string;
  /** Answer as raw markdown; render with `renderMarkdown`. */
  answer: string;
}

export interface HeroContent {
  title: string;
  body: string[];
}

function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

/** Inline formatting: bold, italic, code, links. Input is escaped first. */
export function renderInline(text: string): string {
  let out = escapeHtml(text);
  out = out.replace(/`([^`]+)`/g, '<code>$1</code>');
  out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  out = out.replace(/\*([^*]+)\*/g, '<em>$1</em>');
  out = out.replace(
    /\[([^\]]+)\]\(([^)]+)\)/g,
    (_m, label: string, href: string) =>
      `<a href="${href.replace(/"/g, '%22')}">${label}</a>`,
  );
  return out;
}

const HEADING_RE = /^(#{1,6})\s+(.*)$/;

/** Slugify a heading/question into a stable id. */
export function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 64);
}

/**
 * Render a block of markdown to a safe HTML string. Supports headings,
 * paragraphs, unordered/ordered lists, blockquotes, horizontal rules, and
 * inline formatting — sufficient for the approved legal/FAQ/landing docs.
 */
export function renderMarkdown(md: string): string {
  const lines = md.replace(/\r\n/g, '\n').split('\n');
  const html: string[] = [];
  let paragraph: string[] = [];
  let listType: 'ul' | 'ol' | null = null;

  const flushParagraph = (): void => {
    if (paragraph.length > 0) {
      html.push(`<p>${renderInline(paragraph.join(' '))}</p>`);
      paragraph = [];
    }
  };
  const closeList = (): void => {
    if (listType) {
      html.push(`</${listType}>`);
      listType = null;
    }
  };

  for (const rawLine of lines) {
    const line = rawLine.trimEnd();
    const trimmed = line.trim();

    if (trimmed === '') {
      flushParagraph();
      closeList();
      continue;
    }
    if (/^(-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
      flushParagraph();
      closeList();
      html.push('<hr>');
      continue;
    }
    const heading = HEADING_RE.exec(trimmed);
    if (heading) {
      flushParagraph();
      closeList();
      const level = Math.min(heading[1].length, 6);
      html.push(`<h${level}>${renderInline(heading[2])}</h${level}>`);
      continue;
    }
    const ulMatch = /^[-*]\s+(.*)$/.exec(trimmed);
    if (ulMatch) {
      flushParagraph();
      if (listType !== 'ul') {
        closeList();
        html.push('<ul>');
        listType = 'ul';
      }
      html.push(`<li>${renderInline(ulMatch[1])}</li>`);
      continue;
    }
    const olMatch = /^\d+\.\s+(.*)$/.exec(trimmed);
    if (olMatch) {
      flushParagraph();
      if (listType !== 'ol') {
        closeList();
        html.push('<ol>');
        listType = 'ol';
      }
      html.push(`<li>${renderInline(olMatch[1])}</li>`);
      continue;
    }
    const quote = /^>\s?(.*)$/.exec(trimmed);
    if (quote) {
      flushParagraph();
      closeList();
      html.push(`<blockquote>${renderInline(quote[1])}</blockquote>`);
      continue;
    }
    paragraph.push(trimmed);
  }
  flushParagraph();
  closeList();
  return html.join('\n');
}

/** True for a numbered section/divider heading like "## 2. Hero Section". */
function isNumberedSectionHeading(line: string): boolean {
  const h = HEADING_RE.exec(line.trim());
  return h !== null && /^\d+(\.\d+)*[.)]?\s/.test(h[2]);
}

/**
 * Extract the raw body of the first numbered section whose heading text matches
 * `titlePattern` (case-insensitive; the leading "N." number is ignored). The
 * section runs until the next numbered section heading — landing docs place an
 * un-numbered `# Headline` inside a numbered `## N. Section`, so we delimit on
 * the numbered section markers, not on heading level. Returns an empty string
 * when no matching section exists.
 */
export function extractSection(md: string, titlePattern: RegExp): string {
  const lines = md.replace(/\r\n/g, '\n').split('\n');
  let start = -1;
  for (let i = 0; i < lines.length; i++) {
    const h = HEADING_RE.exec(lines[i].trim());
    if (!h) continue;
    const text = h[2].replace(/^\d+(\.\d+)*\.?\s*/, '').trim();
    if (titlePattern.test(text)) {
      start = i + 1;
      break;
    }
  }
  if (start === -1) return '';
  const body: string[] = [];
  for (let i = start; i < lines.length; i++) {
    if (isNumberedSectionHeading(lines[i])) break;
    body.push(lines[i]);
  }
  return body.join('\n').trim();
}

/**
 * Parse the role landing markdown's Hero Section into a headline + body
 * paragraphs (verbatim). Falls back gracefully when the section is absent.
 */
export function parseHero(landingMd: string): HeroContent {
  const section = extractSection(landingMd, /^hero section/i);
  if (!section) return { title: '', body: [] };
  const lines = section.split('\n');
  let title = '';
  const body: string[] = [];
  for (const raw of lines) {
    const line = raw.trim();
    if (line === '' || /^-{3,}$/.test(line)) continue;
    const h = HEADING_RE.exec(line);
    if (h) {
      if (!title) title = h[2].trim();
      continue;
    }
    // Skip standalone CTA markers like **GET STARTED** / Secondary link: …
    if (/^\*\*[A-Za-z\s]+\*\*$/.test(line)) continue;
    if (/^secondary link:/i.test(line)) continue;
    body.push(line);
  }
  // Fall back to the first body line when the headline isn't a markdown heading.
  if (!title && body.length > 0) {
    title = body.shift() as string;
  }
  return { title, body };
}

/**
 * Parse a role FAQ help document into question/answer items. A FAQ item is a
 * second-level (`##`) heading carrying dotted section numbering (e.g.
 * "## 1.2 Who is Servana built for?"); its answer is the content up to the next
 * heading or horizontal rule. Front-matter and category dividers are excluded.
 */
export function parseFaq(faqMd: string): FaqItem[] {
  const lines = faqMd.replace(/\r\n/g, '\n').split('\n');
  const items: FaqItem[] = [];
  let current: { question: string; answer: string[] } | null = null;

  const push = (): void => {
    if (current) {
      const answer = current.answer.join('\n').replace(/^-{3,}$/gm, '').trim();
      items.push({ id: slugify(current.question), question: current.question, answer });
      current = null;
    }
  };

  for (const raw of lines) {
    const line = raw.trim();
    const h = HEADING_RE.exec(line);
    if (h) {
      const isFaqQuestion = h[1].length === 2 && /^\d+\.\d+\s+/.test(h[2]);
      if (isFaqQuestion) {
        push();
        current = { question: h[2].replace(/^\d+\.\d+\s+/, '').trim(), answer: [] };
        continue;
      }
      // Any other heading ends the current answer.
      push();
      continue;
    }
    if (current) {
      if (/^-{3,}$/.test(line)) continue;
      current.answer.push(raw);
    }
  }
  push();
  return items;
}
