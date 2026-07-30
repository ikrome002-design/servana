import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';

import { ACCOUNT_HOSTS, ACCOUNT_KEYS } from '@/host/accountHosts.generated';
import {
  ACCOUNT_CONTEXT_ELEMENT_ID,
  initAccountContext,
  resetAccountContext,
} from '@/host/accountHostContext';
import Home from '@/pages/Home.vue';

/**
 * Phase UI-02 — the FOUNDATION-ONLY public account surface.
 *
 * Two obligations, both asserted here: each of the eight hosts renders its own account, and
 * the page does NOT pretend to be the finished landing page. UI-06 owns hero copy, features,
 * testimonials, pricing, curated imagery and final CTAs, sourced verbatim from approved role
 * content — inventing any of it here would be fabricating product claims.
 *
 * Before UI-02 this page rendered one account-agnostic surface for every host, which is the
 * "one landing page for all accounts" outcome the UI/UX plan §0 prohibits.
 */

const RouterLinkStub = {
  props: ['to'],
  template: '<a><slot /></a>',
};

function mountWithContext(payload: Record<string, unknown> | null, hostname: string) {
  resetAccountContext();

  const doc = document.implementation.createHTMLDocument('test');
  if (payload !== null) {
    const script = doc.createElement('script');
    script.id = ACCOUNT_CONTEXT_ELEMENT_ID;
    script.type = 'application/json';
    script.textContent = JSON.stringify(payload);
    doc.body.appendChild(script);
  }
  initAccountContext(doc, hostname);

  return mount(Home, {
    global: { plugins: [createPinia()], stubs: { RouterLink: RouterLinkStub } },
  });
}

function contextFor(accountKey: (typeof ACCOUNT_KEYS)[number]) {
  const definition = ACCOUNT_HOSTS[accountKey];

  return {
    payload: {
      account_key: accountKey,
      display_name: definition.displayName,
      host: definition.hosts.local,
      environment: 'local',
    },
    hostname: definition.hosts.local,
  };
}

describe('Home', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    resetAccountContext();
  });

  it('renders the Servana brand name and tagline', () => {
    const { payload, hostname } = contextFor('merchant_administrator');
    const wrapper = mountWithContext(payload, hostname);

    expect(wrapper.text()).toContain('Servana by Citrus');
    expect(wrapper.text()).toContain('Serve Better. Run Smarter. Grow Steadily.');
  });

  it.each(ACCOUNT_KEYS)('renders the correct account context for %s', (accountKey) => {
    const { payload, hostname } = contextFor(accountKey);
    const wrapper = mountWithContext(payload, hostname);

    const surface = wrapper.get('[data-servana-surface="foundation_only"]');
    expect(surface.attributes('data-account-key')).toBe(accountKey);
    expect(wrapper.get('[data-testid="account-display-name"]').text()).toBe(
      ACCOUNT_HOSTS[accountKey].displayName,
    );
  });

  it('marks itself foundation-only and claims no finished landing page', () => {
    const { payload, hostname } = contextFor('merchant_administrator');
    const wrapper = mountWithContext(payload, hostname);

    expect(wrapper.find('[data-servana-surface="foundation_only"]').exists()).toBe(true);

    // None of UI-06's sections may have crept in. Invented pricing, testimonials, ratings
    // and usage statistics are explicitly prohibited (UI/UX plan §1.3).
    const text = wrapper.text().toLowerCase();
    for (const forbidden of [
      'testimonial',
      'pricing',
      'per month',
      'trusted by',
      'customers say',
      'how it works',
      'why servana',
    ]) {
      expect(text, `foundation surface must not contain "${forbidden}"`).not.toContain(forbidden);
    }
  });

  it('shows the approved logo with its canonical filename', () => {
    const { payload, hostname } = contextFor('merchant_audit');
    const wrapper = mountWithContext(payload, hostname);

    expect(wrapper.get('img').attributes('src')).toBe('/assets/brand/Logo.png');
    // Logo.svg was deleted under product-owner authority and must never return.
    expect(wrapper.html()).not.toContain('Logo.svg');
  });

  it('renders a safe boundary — not an account experience — when context is missing', () => {
    const wrapper = mountWithContext(null, 'servana.test');

    expect(wrapper.find('[data-servana-surface="foundation_only"]').exists()).toBe(false);
    const boundary = wrapper.get('[data-servana-surface="account_context_unavailable"]');
    expect(boundary.attributes('data-account-context-failure')).toBe('missing');
    expect(boundary.attributes('role')).toBe('alert');
  });

  it('fails closed when the server context and the address bar disagree', () => {
    const wrapper = mountWithContext(
      {
        account_key: 'merchant_personnel',
        display_name: 'Personnel',
        host: 'staff.servana.test',
        environment: 'local',
      },
      'citrus.servana.test',
    );

    // It must NOT render the Personnel experience under the platform hostname.
    expect(wrapper.find('[data-servana-surface="foundation_only"]').exists()).toBe(false);
    expect(
      wrapper
        .get('[data-servana-surface="account_context_unavailable"]')
        .attributes('data-account-context-failure'),
    ).toBe('host_mismatch');
    expect(wrapper.text()).not.toContain('Personnel');
  });

  it('never names the approved hosts in a denial state', () => {
    const wrapper = mountWithContext(null, 'attacker.test');

    expect(wrapper.text()).not.toContain('servana.ke');
    expect(wrapper.text()).not.toContain('servana.test');
  });
});
