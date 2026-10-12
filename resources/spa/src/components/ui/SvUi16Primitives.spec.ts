import { mount } from '@vue/test-utils';
import { computed } from 'vue';
import { describe, expect, it } from 'vitest';
import GatedNavigationEntry from '@/components/navigation/GatedNavigationEntry.vue';
import SvOperationalHero from '@/components/ui/SvOperationalHero.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import SvTonalPageHeader from '@/components/ui/SvTonalPageHeader.vue';
import { COMPONENT_REGISTRY } from '@/design-system/componentRegistry';
import { accountTone, PAGE_TONE_KEY, type PageTone } from '@/design-system/pageTone';

/** Provide an account tone the way AppShell does. */
const toneFromShell = (tone: PageTone) => ({ global: { provide: { [PAGE_TONE_KEY as symbol]: computed(() => tone) } } });

describe('Phase UI-16 shared primitives', () => {
  it('SvOperationalHero owns exactly one h1 and renders its actions and facts slots', () => {
    const wrapper = mount(SvOperationalHero, {
      props: { eyebrow: 'Platform governance', title: 'Platform dashboard', description: 'Every merchant.', context: 'Citrus platform' },
      slots: { actions: '<a href="#">Open</a>', default: '<p data-testid="facts">42</p>' },
    });
    expect(wrapper.findAll('h1')).toHaveLength(1);
    expect(wrapper.get('h1').text()).toBe('Platform dashboard');
    expect(wrapper.text()).toContain('Citrus platform');
    expect(wrapper.get('[data-testid="facts"]').text()).toBe('42');
  });

  it('SvPageHeader renders the tonal surface for a page and keeps its test hooks (UI16-VIS-001)', () => {
    const wrapper = mount(SvPageHeader, {
      props: { title: 'Payout runs', eyebrow: 'Compensation Finance', description: 'Verify submitted runs.' },
      slots: { actions: '<button>Lock</button>' },
    });
    expect(wrapper.findComponent(SvTonalPageHeader).exists()).toBe(true);
    expect(wrapper.findAll('h1')).toHaveLength(1);
    expect(wrapper.get('[data-testid="sv-page-title"]').text()).toBe('Payout runs');
    expect(wrapper.get('[data-testid="sv-page-actions"]').text()).toBe('Lock');
  });

  it('SvPageHeader keeps the plain layout for an in-panel heading', () => {
    const wrapper = mount(SvPageHeader, { props: { title: 'Details', headingLevel: 'h2' } });
    expect(wrapper.findComponent(SvTonalPageHeader).exists()).toBe(false);
    expect(wrapper.findAll('h1')).toHaveLength(0);
    expect(wrapper.get('h2').text()).toBe('Details');
  });

  it('SvPageHeader carries rich description copy verbatim through its slot', () => {
    const wrapper = mount(SvPageHeader, {
      props: { title: 'Plan management' },
      slots: { description: 'Changes apply with <strong>no proration</strong>.' },
    });
    expect(wrapper.get('strong').text()).toBe('no proration');
  });

  it('SvTonalPageHeader takes the shell-provided account tone, and an explicit tone wins', () => {
    const fromShell = mount(SvTonalPageHeader, { props: { title: 'Exports' }, ...toneFromShell('teal') });
    expect(fromShell.get('[data-testid="sv-page-header"]').attributes('data-tone')).toBe('teal');
    const explicit = mount(SvTonalPageHeader, { props: { title: 'Exports', tone: 'green' }, ...toneFromShell('teal') });
    expect(explicit.get('[data-testid="sv-page-header"]').attributes('data-tone')).toBe('green');
    expect(mount(SvTonalPageHeader, { props: { title: 'Alone' } }).attributes('data-tone')).toBe('warm');
  });

  it('gives every account a tone from the closed vocabulary', () => {
    for (const account of ['super_administrator', 'merchant_administrator', 'merchant_branch', 'merchant_human_resource', 'merchant_finance', 'merchant_front_office', 'merchant_personnel', 'merchant_audit'] as const) {
      expect(['warm', 'teal', 'green']).toContain(accountTone(account));
    }
  });

  it('SvStateBoundary applies the caller class in the success state too (UI16-LAYOUT-001)', () => {
    const wrapper = mount(SvStateBoundary, {
      props: { state: 'success' },
      attrs: { class: 'mt-6' },
      slots: { default: '<p>one</p><p>two</p>' },
    });
    expect(wrapper.get('[data-testid="sv-state-success"]').classes()).toContain('mt-6');
    expect(wrapper.findAll('p')).toHaveLength(2);
  });

  it('GatedNavigationEntry keeps the exact gate in the DOM as the description, with no destination (UI16-NAV-001)', () => {
    const wrapper = mount(GatedNavigationEntry, {
      props: { label: 'Billing Recovery', reason: 'External Gate W — Wallet by Citrus collections readiness', testid: 'nav-stacked-gated-x' },
    });
    const entry = wrapper.get('[data-testid="nav-stacked-gated-x"]');
    expect(entry.attributes('aria-disabled')).toBe('true');
    expect(entry.attributes('href')).toBeUndefined();
    expect(entry.attributes('tabindex')).toBe('0');
    const describedBy = entry.attributes('aria-describedby');
    expect(describedBy).toBeTruthy();
    expect(wrapper.get(`#${describedBy}`).text()).toContain('External Gate W');
    // The label is read first, so contract-label comparisons that split on "Unavailable" still work.
    expect(entry.text().split('Unavailable')[0]!.trim()).toBe('Billing Recovery');
  });

  it('registers the promoted UI-13/UI-14 primitives and nothing twice (UI16-DS-001)', () => {
    const names = COMPONENT_REGISTRY.map((entry) => entry.name);
    for (const promoted of ['SvTonalPageHeader', 'SvOperationalHero', 'SvStatTile', 'SvLeadRecord', 'SvMaskedIdentity']) {
      expect(names).toContain(promoted);
    }
    expect(new Set(names).size).toBe(names.length);
  });
});
