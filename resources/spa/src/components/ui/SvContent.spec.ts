import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SvFaq from '@/components/ui/SvFaq.vue';
import SvLandingSection from '@/components/ui/SvLandingSection.vue';
import SvLegalDocument from '@/components/ui/SvLegalDocument.vue';
import { renderInline } from '@/content/markdown';

/**
 * Phase UI-04 — content STRUCTURE contract (UI/UX plan §8.3, §17).
 *
 * UI-04 owns the shells only. It authors no copy, no legal text and no FAQ; those are compiled by
 * UI-05 and rendered into product routes by UI-06. What is tested here is that the shells are
 * accessible, that they preserve supplied text verbatim, and that they cannot become an HTML
 * injection point.
 */

describe('SvLandingSection', () => {
  it('is a section named by its own heading, so it is a navigable region', () => {
    const wrapper = mount(SvLandingSection, { props: { heading: 'How it works' } });

    expect(wrapper.element.tagName).toBe('SECTION');
    const labelledBy = wrapper.attributes('aria-labelledby');
    expect(labelledBy).toBeTruthy();
    expect(wrapper.get(`#${labelledBy}`).text()).toBe('How it works');
  });

  it('derives a deterministic heading id, so the association is stable across renders', () => {
    const first = mount(SvLandingSection, { props: { heading: 'How it works' } });
    const second = mount(SvLandingSection, { props: { heading: 'How it works' } });

    expect(first.attributes('aria-labelledby')).toBe(second.attributes('aria-labelledby'));
  });

  it('can drop to h3 so a nested section does not break the outline', () => {
    const wrapper = mount(SvLandingSection, { props: { heading: 'Detail', headingLevel: 'h3' } });

    expect(wrapper.find('h2').exists()).toBe(false);
    expect(wrapper.get('h3').text()).toBe('Detail');
  });

  it('renders no image when none is supplied', () => {
    expect(mount(SvLandingSection, { props: { heading: 'X' } }).find('img').exists()).toBe(false);
  });

  it('requires an explicit alt decision for a supplied image', () => {
    const decorative = mount(SvLandingSection, {
      props: { heading: 'X', imageSrc: '/assets/landing_page_images/x.jpg', imageAlt: '' },
    });
    const meaningful = mount(SvLandingSection, {
      props: { heading: 'X', imageSrc: '/assets/landing_page_images/x.jpg', imageAlt: 'A stylist at work' },
    });

    expect(decorative.get('img').attributes('alt')).toBe('');
    expect(meaningful.get('img').attributes('alt')).toBe('A stylist at work');
  });

  it('invents no copy of its own', () => {
    // Everything visible must have come from a prop or a slot; UI-04 authors no landing content.
    const wrapper = mount(SvLandingSection, { props: { heading: 'Only this' } });

    expect(wrapper.text().trim()).toBe('Only this');
  });
});

describe('SvLegalDocument', () => {
  const MARKDOWN = '# Terms\n\nYou agree to the **Servana** terms.\n\n- One\n- Two\n';

  it('renders the supplied document with a real page heading', () => {
    const wrapper = mount(SvLegalDocument, { props: { title: 'Terms of Service', markdown: MARKDOWN } });

    expect(wrapper.element.tagName).toBe('ARTICLE');
    expect(wrapper.get('h1').text()).toBe('Terms of Service');
  });

  it('preserves the supplied text verbatim', () => {
    // Legal copy is never paraphrased, summarised or reworded by the renderer.
    const wrapper = mount(SvLegalDocument, { props: { title: 'Terms', markdown: MARKDOWN } });

    expect(wrapper.text()).toContain('You agree to the Servana terms.');
    expect(wrapper.text()).toContain('One');
    expect(wrapper.text()).toContain('Two');
  });

  it('escapes HTML in the source rather than executing it', () => {
    const wrapper = mount(SvLegalDocument, {
      props: { title: 'T', markdown: 'Hello <script>alert(1)</script> world' },
    });

    expect(wrapper.html()).not.toContain('<script>');
    expect(wrapper.text()).toContain('alert(1)');
  });

  it('renders the optional meta line only when supplied', () => {
    expect(mount(SvLegalDocument, { props: { title: 'T', markdown: 'x' } }).findAll('p')).toHaveLength(1);
    expect(
      mount(SvLegalDocument, { props: { title: 'T', markdown: 'x', meta: 'Effective 1 August 2026' } }).text(),
    ).toContain('Effective 1 August 2026');
  });
});

describe('markdown link safety', () => {
  it('allows the schemes an approved document legitimately uses', () => {
    expect(renderInline('[site](https://citruslabs.co.ke/)')).toContain('href="https://citruslabs.co.ke/"');
    expect(renderInline('[mail](mailto:support@citruslabs.co.ke)')).toContain('href="mailto:support@citruslabs.co.ke"');
    expect(renderInline('[terms](/legal/merchant_audit/terms-of-service)')).toContain('href="/legal/');
    expect(renderInline('[jump](#section)')).toContain('href="#section"');
  });

  it('neutralises a script URL rather than emitting an executable link', () => {
    // Escaping the surrounding text is not enough: a markdown link target goes straight into an
    // href. The content is reviewed and version-controlled, so this is defence in depth — but a
    // renderer feeding v-html should not be able to emit a script URL at all.
    const html = renderInline('[click](javascript:alert(1))');

    expect(html).not.toContain('javascript:');
    expect(html).toContain('href="#"');
  });

  it('neutralises a data URL', () => {
    expect(renderInline('[x](data:text/html;base64,PHNjcmlwdD4=)')).not.toContain('data:');
  });
});

describe('SvFaq', () => {
  const ITEMS = [
    { id: 'how-do-i-join', question: 'How do I join?', answer: 'Ask your **administrator**.' },
    { id: 'what-does-it-cost', question: 'What does it cost?', answer: 'See the pricing page.' },
  ];

  it('is a named region built on native disclosure elements', () => {
    const wrapper = mount(SvFaq, { props: { items: ITEMS } });

    expect(wrapper.attributes('aria-label')).toBe('Frequently asked questions');
    expect(wrapper.findAll('details')).toHaveLength(2);
    expect(wrapper.findAll('summary')).toHaveLength(2);
  });

  it('gives every item a deterministic id so an answer can be deep-linked', () => {
    const wrapper = mount(SvFaq, { props: { items: ITEMS } });

    expect(wrapper.findAll('details').map((d) => d.attributes('id'))).toEqual([
      'how-do-i-join',
      'what-does-it-cost',
    ]);
  });

  it('derives an id when the source item carries none', () => {
    const wrapper = mount(SvFaq, { props: { items: [{ id: '', question: 'Is it safe?', answer: 'Yes.' }] } });

    expect(wrapper.get('details').attributes('id')).toBe('is-it-safe');
  });

  it('keeps every item collapsed by default so the list stays scannable', () => {
    const wrapper = mount(SvFaq, { props: { items: ITEMS } });

    expect(wrapper.findAll('details').filter((d) => d.attributes('open') !== undefined)).toHaveLength(0);
  });

  it('opens the first item on request', () => {
    const wrapper = mount(SvFaq, { props: { items: ITEMS, openFirst: true } });

    expect(wrapper.findAll('details')[0].attributes('open')).toBeDefined();
    expect(wrapper.findAll('details')[1].attributes('open')).toBeUndefined();
  });

  it('hides the chevron from assistive technology, because summary already announces state', () => {
    const wrapper = mount(SvFaq, { props: { items: ITEMS } });

    expect(wrapper.get('summary svg').attributes('aria-hidden')).toBe('true');
  });

  it('renders answers verbatim and fabricates nothing', () => {
    const wrapper = mount(SvFaq, { props: { items: ITEMS } });

    expect(wrapper.text()).toContain('How do I join?');
    expect(wrapper.text()).toContain('Ask your administrator.');
    expect(wrapper.findAll('details')).toHaveLength(ITEMS.length);
  });

  it('escapes HTML in an answer', () => {
    const wrapper = mount(SvFaq, {
      props: { items: [{ id: 'x', question: 'Q', answer: '<img src=x onerror=alert(1)>' }] },
    });

    // The property that matters is that no ELEMENT was created — the payload survives only as
    // inert escaped text, which is exactly what a document renderer should do with it.
    expect(wrapper.find('img').exists()).toBe(false);
    expect(wrapper.html()).toContain('&lt;img');
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>');
  });
});
