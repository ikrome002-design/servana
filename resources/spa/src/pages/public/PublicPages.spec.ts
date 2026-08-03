import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PublicFaqPage from '@/pages/public/PublicFaqPage.vue';
import PublicLandingPage from '@/pages/public/PublicLandingPage.vue';
import PublicLegalPage from '@/pages/public/PublicLegalPage.vue';
import PublicNotFound from '@/pages/public/PublicNotFound.vue';
import { ACCOUNT_HOSTS, ACCOUNT_KEYS } from '@/host/accountHosts.generated';
import { initAccountContext, resetAccountContext } from '@/host/accountHostContext';
import { loadGeneratedFaq, loadGeneratedLanding } from '@/content/generated/index.generated';
import type { RoleIdentity } from '@/types/roles';

/**
 * Phase UI-06 — the public pages, mounted against the REAL account-host context.
 *
 * These pages take their account from the SERVER-resolved context. The tests therefore install a
 * real context block rather than stubbing the resolver: the property that matters is that the
 * account arrives from the shell and nothing on the page can override it.
 *
 * The isolation assertions are the point. Eight accounts × the wrong seven each is the check that a
 * page never renders another account's copy, imagery or documents — the defect the whole
 * host-derived contract exists to prevent.
 */

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/faq', name: 'public.faq', component: stub },
      {
        path: '/legal/:doc(data-policy|privacy-policy|terms-of-service)',
        name: 'public.legal',
        component: stub,
      },
      { path: '/auth/login', name: 'auth.login', component: stub },
      { path: '/auth/register', name: 'auth.register', component: stub },
      { path: '/staff/accept', name: 'staff.accept', component: stub },
    ],
  });
}

/** Install the context block exactly as the Laravel shell embeds it. */
function installContext(accountKey: RoleIdentity | string, host?: string): void {
  const definition = (ACCOUNT_HOSTS as Record<string, { displayName: string; hosts: { local: string } } | undefined>)[
    accountKey
  ];
  const script = document.createElement('script');
  script.id = 'servana-account-context';
  script.type = 'application/json';
  script.textContent = JSON.stringify({
    account_key: accountKey,
    display_name: definition?.displayName ?? 'Unknown',
    host: host ?? definition?.hosts.local ?? 'unknown.test',
    environment: 'testing',
  });
  document.head.appendChild(script);
  // The browser hostname is only a cross-check; passing null skips it, which is the non-browser
  // case this environment really is.
  initAccountContext(document, null);
}

function clearContext(): void {
  document.getElementById('servana-account-context')?.remove();
  resetAccountContext();
}

/**
 * Mount a public page and wait for its content to arrive.
 *
 * Each page loads its account's compiled content through a DYNAMIC import, so a fixed number of
 * microtask flushes is a race: the first mount of a given module is cold and takes several more
 * turns than a later one. `waitFor` settles on the observable outcome instead — which is also what
 * makes a genuine load failure show up as a timeout rather than as a silently empty assertion.
 */
async function mountPage(component: unknown, path = '/', ready?: string) {
  const router = makeRouter();
  await router.push(path);
  await router.isReady();

  const wrapper = mount(component as never, { global: { plugins: [router] } });
  await flushPromises();

  if (ready !== undefined) {
    await vi.waitFor(() => {
      expect(wrapper.find(ready).exists()).toBe(true);
    });
  }

  return wrapper;
}

beforeEach(() => {
  setActivePinia(createPinia());
});

afterEach(() => {
  clearContext();
  document.title = '';
});

describe('PublicLandingPage — one page per account host', () => {
  for (const accountKey of ACCOUNT_KEYS) {
    it(`renders ${accountKey}'s own landing page`, async () => {
      installContext(accountKey);
      const wrapper = await mountPage(PublicLandingPage, '/', '[data-testid="landing-hero"]');

      const page = wrapper.get('[data-testid="landing-page"]');
      expect(page.attributes('data-landing-account-key')).toBe(accountKey);
      expect(page.attributes('data-content-source'))
        .toBe(`docs/landing_page/${accountKey}_landing_page_content.md`);

      // The compiled hero headline, verbatim from that account's own document.
      const document_ = await loadGeneratedLanding(accountKey);
      const hero = document_.sections.find((section) => section.region === 'hero');
      const headline = (hero?.markdown ?? '').split('\n')[0].replace(/^#+\s*/, '').replace(/\*\*/g, '');
      expect(wrapper.get('h1').text()).toBe(headline);
      wrapper.unmount();
    });

    it(`renders no other account's content on ${accountKey}`, async () => {
      installContext(accountKey);
      const wrapper = await mountPage(PublicLandingPage, '/', '[data-testid="landing-hero"]');
      const html = wrapper.html();

      for (const other of ACCOUNT_KEYS) {
        if (other === accountKey) {
          continue;
        }
        expect(html, `${accountKey} leaked ${other}`).not.toContain(other);
        expect(html, `${accountKey} leaked ${other}'s images`)
          .not.toContain(`/assets/landing_page_images/${other}/`);
      }
      wrapper.unmount();
    });

    it(`renders exactly one high-priority image on ${accountKey}`, async () => {
      installContext(accountKey);
      const wrapper = await mountPage(PublicLandingPage, '/', '[data-testid="landing-hero"]');

      expect(wrapper.findAll('img[fetchpriority="high"]')).toHaveLength(1);
      wrapper.unmount();
    });

    it(`presents all sixteen semantic regions on ${accountKey}`, async () => {
      installContext(accountKey);
      const wrapper = await mountPage(PublicLandingPage, '/', '[data-testid="landing-hero"]');
      const html = wrapper.html();

      // Region 1 is the header and region 16 is the fixed footer; the other fourteen carry a
      // `data-landing-region` marker.
      expect(wrapper.find('[data-testid="landing-header"]').exists()).toBe(true);
      expect(wrapper.find('[data-testid="sv-fixed-footer"]').exists()).toBe(true);

      for (const region of [
        'hero', 'social_proof', 'problem', 'solution', 'features', 'how_it_works', 'benefits',
        'product_showcase', 'use_cases', 'testimonials', 'pricing', 'security', 'faq', 'final_cta',
      ]) {
        expect(html, `${accountKey} is missing region ${region}`)
          .toContain(`data-landing-region="${region}"`);
      }
      wrapper.unmount();
    });

    it(`sets ${accountKey}'s own document title`, async () => {
      installContext(accountKey);
      const wrapper = await mountPage(PublicLandingPage, '/', '[data-testid="landing-hero"]');

      expect(document.title.length).toBeGreaterThan(10);
      expect(document.querySelector('meta[name="description"]')?.getAttribute('content')?.length ?? 0)
        .toBeGreaterThan(20);
      wrapper.unmount();
    });
  }

  it('renders no landing content at all when the context cannot be established', async () => {
    // No context block: the layout's boundary answers, and nothing is loaded.
    resetAccountContext();
    initAccountContext(document, null);
    const wrapper = await mountPage(PublicLandingPage);

    expect(wrapper.get('[data-testid="landing-context-boundary"]').text())
      .toContain('Servana is not available at this address');
    expect(wrapper.find('[data-testid="landing-hero"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('renders no landing content for an account key the frontend does not know', async () => {
    installContext('not_an_account', 'unknown.test');
    const wrapper = await mountPage(PublicLandingPage);

    expect(wrapper.get('[data-testid="landing-context-boundary"]').attributes('data-account-context-failure'))
      .toBe('unknown_account');
    expect(wrapper.find('[data-testid="landing-hero"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('exposes merchant self-registration only on the Merchant Administrator host', async () => {
    for (const accountKey of ACCOUNT_KEYS) {
      installContext(accountKey);
      const wrapper = await mountPage(PublicLandingPage, '/', '[data-testid="landing-hero"]');
      const html = wrapper.html();

      if (accountKey === 'merchant_administrator') {
        expect(html).toContain('data-cta-kind="self_registration"');
        expect(html).toContain('/auth/register');
      } else {
        expect(html, `${accountKey} exposed self-registration`)
          .not.toContain('data-cta-kind="self_registration"');
        expect(html, `${accountKey} linked the registration route`).not.toContain('/auth/register');
      }

      wrapper.unmount();
      clearContext();
    }
  });
});

describe('PublicFaqPage', () => {
  for (const accountKey of ACCOUNT_KEYS) {
    it(`serves ${accountKey}'s own compiled FAQ in full`, async () => {
      installContext(accountKey);
      const wrapper = await mountPage(PublicFaqPage, '/faq', '[data-testid="sv-faq"]');
      const container = wrapper.get('[data-testid="public-faq"]');

      expect(container.attributes('data-faq-account-key')).toBe(accountKey);
      expect(container.attributes('data-content-source')).toBe(`docs/support/faq/${accountKey}_faq.md`);

      // Every compiled question is on the page — none is hidden behind a filter or a page size.
      const document_ = await loadGeneratedFaq(accountKey);
      expect(wrapper.findAll('details')).toHaveLength(document_.items.length);
      wrapper.unmount();
    });
  }

  it('renders nothing when the context cannot be established', async () => {
    resetAccountContext();
    initAccountContext(document, null);
    const wrapper = await mountPage(PublicFaqPage, '/faq');

    expect(wrapper.find('[data-testid="public-faq"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="landing-context-boundary"]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('gives every disclosure a unique id, so a deep link is unambiguous', async () => {
    installContext('merchant_administrator');
    const wrapper = await mountPage(PublicFaqPage, '/faq', '[data-testid="sv-faq"]');
    const ids = wrapper.findAll('details').map((node) => node.attributes('id'));

    expect(new Set(ids).size).toBe(ids.length);
    wrapper.unmount();
  });
});

describe('PublicLegalPage', () => {
  for (const accountKey of ACCOUNT_KEYS) {
    for (const doc of ['data-policy', 'privacy-policy', 'terms-of-service'] as const) {
      it(`serves ${accountKey}'s own ${doc}`, async () => {
        installContext(accountKey);
        const wrapper = await mountPage(PublicLegalPage, `/legal/${doc}`, '[data-testid="sv-legal-document"]');
        const article = wrapper.get('[data-testid="sv-legal-document"]');

        expect(article.attributes('data-legal-account-key')).toBe(accountKey);
        expect(article.attributes('data-content-source')).toContain(`${accountKey}_`);
        expect(article.attributes('data-content-sha256')).toMatch(/^[0-9a-f]{64}$/);

        for (const other of ACCOUNT_KEYS) {
          if (other !== accountKey) {
            expect(article.attributes('data-content-source')).not.toContain(other);
          }
        }
        wrapper.unmount();
      });
    }
  }

  it('renders no document when the context cannot be established', async () => {
    resetAccountContext();
    initAccountContext(document, null);
    const wrapper = await mountPage(PublicLegalPage, '/legal/privacy-policy');

    expect(wrapper.find('[data-testid="sv-legal-document"]').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('PublicNotFound', () => {
  it('says the address does not exist and links only to this host', async () => {
    installContext('merchant_audit');
    const wrapper = await mountPage(PublicNotFound, '/nope');

    expect(wrapper.get('[data-testid="public-not-found"]').text()).toContain("We couldn't find that page");
    for (const href of wrapper.findAll('a').map((link) => link.attributes('href') ?? '')) {
      expect(href.startsWith('/') || href.startsWith('#') || href.startsWith('https://')).toBe(true);
    }
    wrapper.unmount();
  });
});
