import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { beforeEach, describe, expect, it } from 'vitest';
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import LandingHeader from '@/components/landing/LandingHeader.vue';
import LandingHero from '@/components/landing/LandingHero.vue';
import LandingPicture from '@/components/landing/LandingPicture.vue';
import LandingPlanAccess from '@/components/landing/LandingPlanAccess.vue';
import LandingTrustEvidence from '@/components/landing/LandingTrustEvidence.vue';
import { landingHeroImage, landingImagesFor } from '@/content/generated/landingImages.generated';
import type { ResolvedCta } from '@/content/landing/ctaResolver';

/**
 * Phase UI-06 — the shared public landing components.
 *
 * The behaviour asserted here is the behaviour that is invisible until it is broken: the mobile
 * menu's focus contract, the picture element's responsive and priority contract, and the two
 * components that exist specifically to make a content rule structural.
 */

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/faq', name: 'public.faq', component: stub },
      { path: '/auth/login', name: 'auth.login', component: stub },
    ],
  });
}

const CTAS: ResolvedCta[] = [
  {
    key: 'login',
    label: 'Log in',
    kind: 'sign_in',
    emphasis: 'primary',
    href: '/auth/login',
    routeName: 'auth.login',
    eligibilityReason: 'sign-in is available on every host',
    sourceSection: '§1',
  },
  {
    key: 'how-it-works',
    label: 'See how it works',
    kind: 'in_page_anchor',
    emphasis: 'secondary',
    href: '#section-how-it-works',
    routeName: null,
    eligibilityReason: 'same-page anchor',
    sourceSection: '§2',
  },
];

const NAVIGATION = [
  { label: 'Features', region: 'features' as const },
  { label: 'How it works', region: 'how_it_works' as const },
];

async function mountHeader() {
  const router = makeRouter();
  await router.push('/');
  await router.isReady();

  return mount(LandingHeader, {
    props: { accountName: 'Finance', navigation: NAVIGATION, ctas: CTAS },
    global: { plugins: [router] },
    attachTo: document.body,
  });
}

describe('LandingHeader', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('renders the approved logo, in-page navigation and the resolved CTAs', async () => {
    const wrapper = await mountHeader();

    expect(wrapper.get('[data-testid="sv-logo"]').attributes('src')).toBe('/assets/brand/Logo.png');
    expect(wrapper.get('[data-testid="landing-desktop-nav"]').findAll('a')).toHaveLength(2);
    expect(wrapper.get('[data-testid="landing-header-cta-login"]').attributes('href')).toBe('/auth/login');
    wrapper.unmount();
  });

  it('generates anchors only for the regions it was given', async () => {
    const wrapper = await mountHeader();

    const hrefs = wrapper.get('[data-testid="landing-desktop-nav"]').findAll('a')
      .map((link) => link.attributes('href'));

    expect(hrefs).toEqual(['#section-features', '#section-how-it-works']);
    wrapper.unmount();
  });

  it('gives the menu trigger a 44px target and an expanded state', async () => {
    const wrapper = await mountHeader();
    const trigger = wrapper.get('[data-testid="landing-menu-trigger"]');

    expect(trigger.attributes('aria-expanded')).toBe('false');
    expect(trigger.attributes('aria-label')).toBe('Open menu');
    expect(trigger.classes().join(' ')).toContain('h-sv-touch');
    expect(trigger.classes().join(' ')).toContain('w-sv-touch');
    wrapper.unmount();
  });

  it('opens a modal menu, moves focus into it, and restores focus to the trigger on close', async () => {
    const wrapper = await mountHeader();
    const trigger = wrapper.get('[data-testid="landing-menu-trigger"]');
    (trigger.element as HTMLButtonElement).focus();

    await trigger.trigger('click');
    await flushPromises();

    const panel = document.querySelector('[data-testid="landing-mobile-menu"]');
    expect(panel).not.toBeNull();
    expect(panel?.getAttribute('role')).toBe('dialog');
    expect(panel?.getAttribute('aria-modal')).toBe('true');
    // Focus moved inside the panel rather than staying behind it.
    expect(panel?.contains(document.activeElement)).toBe(true);

    await (document.querySelector('[data-testid="landing-menu-close"]') as HTMLElement).click();
    await flushPromises();

    expect(document.querySelector('[data-testid="landing-mobile-menu"]')).toBeNull();
    expect(document.activeElement).toBe(trigger.element);
    wrapper.unmount();
  });

  it('closes the menu on Escape', async () => {
    const wrapper = await mountHeader();
    await wrapper.get('[data-testid="landing-menu-trigger"]').trigger('click');
    await flushPromises();

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await flushPromises();

    expect(document.querySelector('[data-testid="landing-mobile-menu"]')).toBeNull();
    wrapper.unmount();
  });

  it('removes the menu from the DOM when closed, so nothing sits off-screen in the tab order', async () => {
    const wrapper = await mountHeader();

    expect(document.querySelector('[data-testid="landing-mobile-menu"]')).toBeNull();
    wrapper.unmount();
  });

  it('offers every resolved CTA inside the menu', async () => {
    const wrapper = await mountHeader();
    await wrapper.get('[data-testid="landing-menu-trigger"]').trigger('click');
    await flushPromises();

    for (const cta of CTAS) {
      expect(document.querySelector(`[data-testid="landing-menu-cta-${cta.key}"]`)).not.toBeNull();
    }
    wrapper.unmount();
  });
});

describe('LandingPicture', () => {
  const image = landingHeroImage('merchant_finance');

  it('renders AVIF and WebP candidates before the untouched original', () => {
    const wrapper = mount(LandingPicture, { props: { image: image! } });
    const sources = wrapper.findAll('source');

    expect(sources.map((source) => source.attributes('type'))).toEqual(['image/avif', 'image/webp']);
    expect(sources[0].attributes('srcset')).toContain('640w');
    expect(sources[0].attributes('srcset')).toContain('1440w');
    expect(wrapper.get('img').attributes('src')).toBe(image!.sourcePublicPath);
  });

  it('declares the file\'s real intrinsic dimensions and reserves the box', () => {
    // UI-05 found the previous surface declaring 800×600 for a 1672×941 file, which mis-reserves
    // the space and IS the layout shift.
    const wrapper = mount(LandingPicture, { props: { image: image! } });
    const img = wrapper.get('img');

    expect(img.attributes('width')).toBe(String(image!.intrinsicWidth));
    expect(img.attributes('height')).toBe(String(image!.intrinsicHeight));
    expect(img.attributes('style')).toContain('aspect-ratio');
  });

  it('carries the curated alternative text, never a generated one', () => {
    const wrapper = mount(LandingPicture, { props: { image: image! } });

    expect(wrapper.get('img').attributes('alt')).toBe(image!.alternativeText);
    expect(wrapper.get('img').attributes('alt')).not.toContain('merchant_finance');
  });

  it('applies the manifest\'s loading strategy and fetch priority verbatim', () => {
    const hero = mount(LandingPicture, { props: { image: image! } });
    expect(hero.get('img').attributes('loading')).toBe('eager');
    expect(hero.get('img').attributes('fetchpriority')).toBe('high');

    const belowFold = landingImagesFor('merchant_finance').find((entry) => entry.landingSection === 'problem');
    const lazy = mount(LandingPicture, { props: { image: belowFold! } });
    expect(lazy.get('img').attributes('loading')).toBe('lazy');
    expect(lazy.get('img').attributes('fetchpriority')).toBe('auto');
  });

  it('tags the account and section it belongs to, so a cross-role render is observable', () => {
    const wrapper = mount(LandingPicture, { props: { image: image! } });

    expect(wrapper.get('picture').attributes('data-landing-image-account')).toBe('merchant_finance');
    expect(wrapper.get('picture').attributes('data-landing-image-section')).toBe('hero');
  });
});

describe('LandingHero', () => {
  it('renders exactly one h1 and one high-priority image', () => {
    const wrapper = mount(LandingHero, {
      props: {
        eyebrow: 'For finance teams',
        headline: 'Keep every payment record clear',
        blocks: [{ kind: 'paragraph', markdown: 'One calm place.' }],
        image: landingHeroImage('merchant_finance'),
        ctas: CTAS,
      },
      global: { plugins: [makeRouter()] },
    });

    expect(wrapper.findAll('h1')).toHaveLength(1);
    expect(wrapper.get('h1').text()).toBe('Keep every payment record clear');
    expect(wrapper.findAll('img[fetchpriority="high"]')).toHaveLength(1);
  });
});

describe('LandingTrustEvidence', () => {
  const trust = {
    heading: 'Operational confidence',
    mode: 'approved_factual_alternative' as const,
    intro: 'Implemented controls, not customer claims.',
    items: [
      {
        title: 'A receipt cannot exist before validation',
        detail: 'Enforced in the database.',
        evidenceType: 'security_control' as const,
        source: 'tests/Feature/Receipts/ReceiptIssuanceTest.php',
        sourceReference: 'receipt-only-after-validation',
        customerClaim: false as const,
        metricClaim: false as const,
      },
    ],
  };

  it('renders no blockquote, quotation mark or attribution', () => {
    // A factual statement dressed as a quotation still reads as a customer saying it.
    const wrapper = mount(LandingTrustEvidence, {
      props: { trust, sourceBlocks: [], sourceHeadline: null },
    });

    expect(wrapper.find('blockquote').exists()).toBe(false);
    expect(wrapper.find('cite').exists()).toBe(false);
    expect(wrapper.text()).not.toMatch(/[“”]/);
  });

  it('exposes the evidence type and source so provenance can be asserted, not trusted', () => {
    const wrapper = mount(LandingTrustEvidence, {
      props: { trust, sourceBlocks: [], sourceHeadline: null },
    });
    const item = wrapper.get('[data-testid="landing-trust-item"]');

    expect(item.attributes('data-evidence-type')).toBe('security_control');
    expect(item.attributes('data-customer-claim')).toBe('false');
    expect(item.attributes('data-metric-claim')).toBe('false');
  });

  it('renders the compiled source section only in compiled_source_section mode', () => {
    const blocks = [{ kind: 'paragraph' as const, markdown: 'Your team deserves structure.' }];

    const alternative = mount(LandingTrustEvidence, {
      props: { trust, sourceBlocks: blocks, sourceHeadline: 'A headline' },
    });
    expect(alternative.find('[data-testid="landing-trust-source"]').exists()).toBe(false);

    const compiled = mount(LandingTrustEvidence, {
      props: { trust: { ...trust, mode: 'compiled_source_section' }, sourceBlocks: blocks, sourceHeadline: 'A headline' },
    });
    expect(compiled.get('[data-testid="landing-trust-source"]').text()).toContain('Your team deserves structure.');
  });
});

describe('LandingPlanAccess', () => {
  const planAccess = {
    heading: 'Start with the plan that fits your business.',
    mode: 'merchant_subscription_plan_access' as const,
    renderCompiledSource: false,
    points: ['Your free period starts when you create your account.'],
    withheld: [{ what: 'The four plan tiers and their amounts.', reason: 'The catalogue is the authority.' }],
    showsAmount: false as const,
    purchaseCta: false as const,
  };

  it('states no amount anywhere in its rendered output', () => {
    const wrapper = mount(LandingPlanAccess, { props: { planAccess, sourceBlocks: [] } });

    expect(wrapper.text()).not.toMatch(/KES\s*[\d,]/i);
    expect(wrapper.attributes('data-shows-amount')).toBe('false');
    expect(wrapper.attributes('data-purchase-cta')).toBe('false');
  });

  it('says plainly that it withholds a price, rather than omitting the section silently', () => {
    const wrapper = mount(LandingPlanAccess, { props: { planAccess, sourceBlocks: [] } });

    expect(wrapper.get('[data-testid="landing-plan-access-withheld"]').text())
      .toContain('no amount is quoted on this page');
  });

  it('renders the compiled source only when the composition permits it', () => {
    const blocks = [{ kind: 'paragraph' as const, markdown: 'Access depends on your merchant setup.' }];

    const withheld = mount(LandingPlanAccess, { props: { planAccess, sourceBlocks: blocks } });
    expect(withheld.find('[data-testid="landing-plan-access-source"]').exists()).toBe(false);

    const rendered = mount(LandingPlanAccess, {
      props: { planAccess: { ...planAccess, renderCompiledSource: true }, sourceBlocks: blocks },
    });
    expect(rendered.get('[data-testid="landing-plan-access-source"]').text())
      .toContain('Access depends on your merchant setup.');
  });
});

describe('LandingBlocks', () => {
  it('renders each block kind through the audited markdown renderer', () => {
    const wrapper = mount(LandingBlocks, {
      props: {
        blocks: [
          { kind: 'paragraph', markdown: 'A **bold** claim.' },
          { kind: 'list', items: ['One', 'Two'] },
          { kind: 'definitions', entries: [{ term: 'Secure login', description: 'Magic Link.' }] },
          { kind: 'labelled', label: 'Trust points', blocks: [{ kind: 'list', items: ['Three'] }] },
        ],
      },
    });

    expect(wrapper.html()).toContain('<strong>bold</strong>');
    expect(wrapper.findAll('li')).toHaveLength(3);
    expect(wrapper.get('dt').text()).toBe('Secure login');
    expect(wrapper.get('h3').text()).toBe('Trust points');
  });

  it('escapes HTML and neutralises an unsafe link scheme', () => {
    const wrapper = mount(LandingBlocks, {
      props: {
        blocks: [{ kind: 'paragraph', markdown: '<script>alert(1)</script> [x](javascript:alert(1))' }],
      },
    });

    expect(wrapper.html()).not.toContain('<script>');
    expect(wrapper.html()).not.toContain('javascript:');
  });
});
