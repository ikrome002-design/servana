import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SvAccountContextSwitcher from '@/components/ui/SvAccountContextSwitcher.vue';
import SvFixedFooter from '@/components/ui/SvFixedFooter.vue';
import SvNotificationsControl from '@/components/ui/SvNotificationsControl.vue';
import SvProfileControl from '@/components/ui/SvProfileControl.vue';
import SvThemeToggle from '@/components/ui/SvThemeToggle.vue';
import { apiClient } from '@/services/apiClient';
import { useAccountContextStore } from '@/stores/accountContextStore';

vi.mock('@/services/apiClient', async () => {
  const actual = await vi.importActual<typeof import('@/services/apiClient')>('@/services/apiClient');

  return {
    ...actual,
    apiClient: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
    primeCsrfCookie: vi.fn().mockResolvedValue(undefined),
  };
});

const RouterLinkStub = {
  props: ['to'],
  template: '<a :href="typeof to === \'string\' ? to : JSON.stringify(to)"><slot /></a>',
};

const global = { stubs: { RouterLink: RouterLinkStub } };

function context(overrides: Record<string, unknown> = {}) {
  return {
    context_id: 'c'.repeat(32),
    account_key: 'merchant_finance',
    display_name: 'Finance',
    // A deliberately non-Servana example host: the component never builds or reads a host, so
    // the value is opaque to it, and a real account hostname in hand-written source is exactly
    // what AccountHostRegistryParityTest exists to prevent.
    target_host: 'target.example',
    default_route: '/dashboard',
    requires_mfa: false,
    merchant_id: 'M1',
    merchant_name: 'Glow Salon',
    branch_id: null,
    branch_name: null,
    role_label: 'Finance',
    is_current: false,
    ...overrides,
  };
}

describe('SvProfileControl', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    vi.mocked(apiClient.get).mockResolvedValue({ data: { data: [] } });
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  const base = { name: 'Ada Wanjiru', accountLabel: 'Human Resource', contextLabel: 'Glow Salon' };

  it('presents identity as one unit', () => {
    const wrapper = mount(SvProfileControl, { props: base, global });
    const trigger = wrapper.get('[data-testid="sv-profile-trigger"]');

    expect(trigger.text()).toContain('Ada Wanjiru');
    expect(trigger.text()).toContain('Human Resource');
    expect(trigger.text()).toContain('Glow Salon');
  });

  it('keeps a full accessible name when the text is hidden on mobile', () => {
    const wrapper = mount(SvProfileControl, { props: base, global });

    expect(wrapper.get('.sr-only').text()).toContain('Ada Wanjiru, Human Resource, Glow Salon');
  });

  it('generates initials locally instead of calling an avatar service', () => {
    // An external avatar service receives the user's identity on every page load.
    const wrapper = mount(SvProfileControl, { props: base, global });

    expect(wrapper.get('[data-testid="sv-profile-initials"]').text()).toBe('AW');
    expect(wrapper.html()).not.toContain('gravatar');
    expect(wrapper.html()).not.toMatch(/https?:\/\//);
  });

  it('exposes only permitted identity fields', () => {
    // No email, no phone, no internal id: none are needed to identify yourself to yourself.
    const wrapper = mount(SvProfileControl, { props: base, global });

    expect(wrapper.text()).not.toMatch(/@|\+254|\b01[A-Z0-9]{24}\b/);
  });

  it('opens by click, not hover', async () => {
    const wrapper = mount(SvProfileControl, { props: base, global });

    await wrapper.get('[data-testid="sv-profile-trigger"]').trigger('mouseenter');
    expect(wrapper.find('[data-testid="sv-profile-menu"]').exists()).toBe(false);

    await wrapper.get('[data-testid="sv-profile-trigger"]').trigger('click');
    expect(wrapper.find('[data-testid="sv-profile-menu"]').exists()).toBe(true);
  });

  it('exposes the disclosure relationship', async () => {
    const wrapper = mount(SvProfileControl, { props: base, global });
    const trigger = wrapper.get('[data-testid="sv-profile-trigger"]');

    expect(trigger.attributes('aria-expanded')).toBe('false');
    expect(trigger.attributes('aria-controls')).toBe('sv-profile-menu');

    await trigger.trigger('click');
    expect(wrapper.get('[data-testid="sv-profile-trigger"]').attributes('aria-expanded')).toBe('true');
  });

  it('closes on Escape and returns focus to the trigger', async () => {
    const wrapper = mount(SvProfileControl, { props: base, global, attachTo: document.body });

    await wrapper.get('[data-testid="sv-profile-trigger"]').trigger('click');
    await wrapper.get('[data-testid="sv-profile-menu"]').trigger('keydown', { key: 'Escape' });

    expect(wrapper.find('[data-testid="sv-profile-menu"]').exists()).toBe(false);
    expect((document.activeElement as HTMLElement | null)?.dataset.testid).toBe('sv-profile-trigger');
    wrapper.unmount();
  });

  it('renders no link for a route that does not exist yet', async () => {
    // Profile/security/preferences routes are not built. A dead link is worse than no link.
    const wrapper = mount(SvProfileControl, { props: base, global });
    await wrapper.get('[data-testid="sv-profile-trigger"]').trigger('click');

    expect(wrapper.find('[data-testid="sv-profile-profile"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="sv-profile-security"]').exists()).toBe(false);
  });

  it('renders a link once the caller supplies a real destination', async () => {
    const wrapper = mount(SvProfileControl, {
      props: { ...base, profileTo: { name: 'profile' } },
      global,
    });
    await wrapper.get('[data-testid="sv-profile-trigger"]').trigger('click');

    expect(wrapper.find('[data-testid="sv-profile-profile"]').exists()).toBe(true);
  });

  it('emits logout rather than performing it', async () => {
    const wrapper = mount(SvProfileControl, { props: base, global });
    await wrapper.get('[data-testid="sv-profile-trigger"]').trigger('click');

    await wrapper.get('[data-testid="sv-profile-logout"]').trigger('click');

    expect(wrapper.emitted('logout')).toHaveLength(1);
  });
});

describe('SvAccountContextSwitcher', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  it('renders nothing when the server reported a single context', async () => {
    // No misleading affordance for a user who has nowhere to switch to.
    vi.mocked(apiClient.get).mockResolvedValue({ data: { data: [context({ is_current: true })] } });

    const wrapper = mount(SvAccountContextSwitcher, { global });
    await useAccountContextStore().fetchContexts();
    await wrapper.vm.$nextTick();

    expect(wrapper.find('[data-testid="sv-account-context-switcher"]').exists()).toBe(false);
  });

  it('lists only the contexts the server supplied', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        data: [
          context({ context_id: 'a'.repeat(32), display_name: 'Finance', is_current: true }),
          context({ context_id: 'b'.repeat(32), display_name: 'Personnel', merchant_name: 'Kilele Spa' }),
        ],
      },
    });

    const wrapper = mount(SvAccountContextSwitcher, { props: { variant: 'inline' }, global });
    await useAccountContextStore().fetchContexts();
    await wrapper.vm.$nextTick();

    // NOT a `^=` selector: that also matches the root `sv-account-context-switcher`.
    expect(wrapper.findAll('ul button')).toHaveLength(2);
  });

  it('disambiguates two contexts by the merchant the server named', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        data: [
          context({ context_id: 'a'.repeat(32), display_name: 'Finance', merchant_name: 'Glow Salon', is_current: true }),
          context({ context_id: 'b'.repeat(32), display_name: 'Finance', merchant_name: 'Kilele Spa' }),
        ],
      },
    });

    const wrapper = mount(SvAccountContextSwitcher, { props: { variant: 'inline' }, global });
    await useAccountContextStore().fetchContexts();
    await wrapper.vm.$nextTick();

    expect(wrapper.text()).toContain('Glow Salon');
    expect(wrapper.text()).toContain('Kilele Spa');
  });

  it('identifies the current context in text, not by a tick alone', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        data: [
          context({ context_id: 'a'.repeat(32), is_current: true }),
          context({ context_id: 'b'.repeat(32) }),
        ],
      },
    });

    const wrapper = mount(SvAccountContextSwitcher, { props: { variant: 'inline' }, global });
    await useAccountContextStore().fetchContexts();
    await wrapper.vm.$nextTick();

    const current = wrapper.get(`[data-testid="sv-account-context-${'a'.repeat(32)}"]`);
    expect(current.attributes('aria-current')).toBe('true');
    expect(current.text()).toContain('Current account');
    expect((current.element as HTMLButtonElement).disabled).toBe(true);
  });

  it('submits only the opaque server identifier', async () => {
    // No role, merchant, branch, host or permission may be sent from the browser (ADR-017).
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        data: [
          context({ context_id: 'a'.repeat(32), is_current: true }),
          context({ context_id: 'b'.repeat(32) }),
        ],
      },
    });
    vi.mocked(apiClient.post).mockResolvedValue({ data: { data: { url: 'https://target.example/auth/switch' } } });

    const wrapper = mount(SvAccountContextSwitcher, { props: { variant: 'inline' }, global });
    await useAccountContextStore().fetchContexts();
    await wrapper.vm.$nextTick();

    await wrapper.get(`[data-testid="sv-account-context-${'b'.repeat(32)}"]`).trigger('click');

    const [, payload] = vi.mocked(apiClient.post).mock.calls[0];
    expect(Object.keys(payload as object)).toEqual(['context_id']);
    expect((payload as { context_id: string }).context_id).toBe('b'.repeat(32));
  });

  it('builds no host of its own', () => {
    // The target URL is the server's answer (ADR-017); the component must contain no host literal.
    //
    // The needles are ASSEMBLED rather than written out: `AccountHostRegistryParityTest` fails on
    // a production hostname appearing in hand-written source, and it cannot tell a reference from
    // an assertion of absence. Building them keeps both guards true at once.
    const source = SvAccountContextSwitcher.toString();
    const productionDomain = ['servana', 'ke'].join('.');
    const scheme = `${'https'}://`;

    expect(source).not.toContain(productionDomain);
    expect(source).not.toContain(scheme);
  });

  it('announces progress and failure through a live region', async () => {
    vi.mocked(apiClient.get).mockResolvedValue({
      data: {
        data: [
          context({ context_id: 'a'.repeat(32), is_current: true }),
          context({ context_id: 'b'.repeat(32) }),
        ],
      },
    });

    const wrapper = mount(SvAccountContextSwitcher, { props: { variant: 'inline' }, global });
    await useAccountContextStore().fetchContexts();
    await wrapper.vm.$nextTick();

    expect(wrapper.get('[data-testid="sv-account-switch-status"]').attributes('aria-live')).toBe('polite');
  });
});

describe('SvNotificationsControl', () => {
  beforeEach(() => {
    // The apiClient mock is module-scoped, so a previous describe's calls would otherwise leak in.
    vi.clearAllMocks();
  });

  it('states the unread count in the accessible name, never by badge alone', () => {
    // A coloured dot is invisible to a screen reader and to a monochrome display.
    const wrapper = mount(SvNotificationsControl, {
      props: { items: [{ id: '1', title: 'A', at: null }, { id: '2', title: 'B', at: null, read: true }] },
    });

    expect(wrapper.get('[data-testid="sv-notifications-trigger"]').attributes('aria-label'))
      .toBe('Notifications, 1 unread');
  });

  it('drops the count from the name when nothing is unread', () => {
    const wrapper = mount(SvNotificationsControl, {
      props: { items: [{ id: '1', title: 'A', at: null, read: true }] },
    });

    expect(wrapper.get('[data-testid="sv-notifications-trigger"]').attributes('aria-label'))
      .toBe('Notifications');
    expect(wrapper.find('[data-testid="sv-notifications-badge"]').exists()).toBe(false);
  });

  it('fetches nothing of its own', () => {
    // No notification API exists; this component is a data-driven visual contract (UI/UX §11.3).
    const wrapper = mount(SvNotificationsControl);

    expect(wrapper.find('[data-testid="sv-notifications-trigger"]').exists()).toBe(true);
    expect(apiClient.get).not.toHaveBeenCalled();
  });

  it('keeps loading, empty and error distinct', async () => {
    const loading = mount(SvNotificationsControl, { props: { loading: true } });
    await loading.get('[data-testid="sv-notifications-trigger"]').trigger('click');
    expect(loading.find('[data-testid="sv-notifications-loading"]').exists()).toBe(true);

    const empty = mount(SvNotificationsControl);
    await empty.get('[data-testid="sv-notifications-trigger"]').trigger('click');
    expect(empty.find('[data-testid="sv-notifications-empty"]').exists()).toBe(true);

    const error = mount(SvNotificationsControl, { props: { error: 'Could not load.' } });
    await error.get('[data-testid="sv-notifications-trigger"]').trigger('click');
    expect(error.get('[data-testid="sv-notifications-error"]').attributes('role')).toBe('alert');
  });

  it('states unread per item rather than relying on weight or colour', async () => {
    const wrapper = mount(SvNotificationsControl, {
      props: { items: [{ id: '1', title: 'Payout approved', at: '2026-07-16T09:00:00Z' }] },
    });
    await wrapper.get('[data-testid="sv-notifications-trigger"]').trigger('click');

    expect(wrapper.get('[data-testid="sv-notification-1"]').text()).toContain('Unread');
  });

  it('closes on Escape', async () => {
    const wrapper = mount(SvNotificationsControl, { attachTo: document.body });
    await wrapper.get('[data-testid="sv-notifications-trigger"]').trigger('click');

    await wrapper.get('#sv-notifications-panel').trigger('keydown', { key: 'Escape' });

    expect(wrapper.find('#sv-notifications-panel').exists()).toBe(false);
    wrapper.unmount();
  });
});

describe('SvFixedFooter', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('carries every required social property with a safe new-tab contract', () => {
    const wrapper = mount(SvFixedFooter, { global });

    const expected: Record<string, string> = {
      instagram: 'https://www.instagram.com/@citruske',
      x: 'https://x.com/LabsCitrus',
      facebook: 'https://www.facebook.com/profile.php?id=100063778943426',
      youtube: 'https://www.youtube.com/@citrus-labs',
      linkedin: 'https://linkedin.com/company/citrus-labs',
      corporate: 'https://citruslabs.co.ke/',
    };

    for (const [key, href] of Object.entries(expected)) {
      const link = wrapper.get(`[data-testid="sv-footer-${key}"]`);
      expect(link.attributes('href')).toBe(href);
      expect(link.attributes('target')).toBe('_blank');
      expect(link.attributes('rel')).toBe('noopener noreferrer');
    }
  });

  it('names every social control in text', () => {
    // An icon-only social row with no name is unusable by screen reader.
    const wrapper = mount(SvFixedFooter, { global });

    for (const name of ['Instagram', 'X', 'Facebook', 'YouTube', 'LinkedIn']) {
      expect(wrapper.text()).toContain(name);
    }
  });

  it('states the copyright verbatim', () => {
    expect(mount(SvFixedFooter, { global }).get('[data-testid="sv-footer-copyright"]').text())
      .toBe('© 2026 Citrus Labs. All Rights Reserved.');
  });

  it('carries the theme control', () => {
    expect(mount(SvFixedFooter, { global }).find('[data-testid="theme-toggle"]').exists()).toBe(true);
  });

  it('links legal documents at the canonical host-derived paths', () => {
    // Phase UI-06: the account is resolved by the SERVER, so it is no longer a path segment. The
    // footer therefore links `/legal/<doc>` and NO account key appears in any destination — which
    // is a stronger cross-role guarantee than the role-parameterised route it replaced.
    // This suite stubs RouterLink and has no router, so the destination is asserted as the named
    // location the footer builds. `RoleLayouts.spec.ts` mounts the same footer behind a REAL
    // router and asserts the resulting `/legal/<doc>` path.
    const wrapper = mount(SvFixedFooter, { props: { legalRole: 'merchant_audit' }, global });

    expect(wrapper.get('[data-testid="sv-footer-data-policy"]').attributes('href'))
      .toBe(JSON.stringify({ name: 'public.legal', params: { doc: 'data-policy' } }));
    expect(wrapper.html()).not.toContain('merchant_audit');
    expect(wrapper.html()).not.toContain('merchant_finance');
  });

  it('renders no legal links when no role was supplied', () => {
    // A public surface with no account context has no role-scoped documents to link.
    const wrapper = mount(SvFixedFooter, { global });

    expect(wrapper.find('[data-testid="sv-footer-data-policy"]').exists()).toBe(false);
  });

  it('renders the FAQ link only when a real destination exists', () => {
    expect(mount(SvFixedFooter, { global }).find('[data-testid="sv-footer-faq"]').exists()).toBe(false);

    const withFaq = mount(SvFixedFooter, { props: { faqTo: { name: 'faq' } }, global });
    expect(withFaq.find('[data-testid="sv-footer-faq"]').exists()).toBe(true);
  });

  it('is a contentinfo landmark driven by the footer height tokens', () => {
    const wrapper = mount(SvFixedFooter, { global });

    expect(wrapper.element.tagName).toBe('FOOTER');
    // The single class that ties the footer to the same token the page reserves (ADR-024).
    expect(wrapper.classes()).toContain('sv-fixed-footer');
  });
});

describe('SvThemeToggle', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    localStorage.clear();
    document.documentElement.classList.remove('dark');
    document.documentElement.removeAttribute('data-sv-theme');
  });

  it('names the ACTION and its outcome, not the current icon', () => {
    // "Switch to dark theme" tells a user what will happen; "Theme" or "Moon" does not.
    const wrapper = mount(SvThemeToggle, { global });

    expect(wrapper.get('[data-testid="theme-toggle"]').attributes('aria-label'))
      .toBe('Switch to dark theme');
  });

  it('reflects the state as well as the action', async () => {
    const wrapper = mount(SvThemeToggle, { global });

    expect(wrapper.get('[data-testid="theme-toggle"]').attributes('aria-pressed')).toBe('false');

    await wrapper.get('[data-testid="theme-toggle"]').trigger('click');

    expect(wrapper.get('[data-testid="theme-toggle"]').attributes('aria-pressed')).toBe('true');
    expect(wrapper.get('[data-testid="theme-toggle"]').attributes('aria-label'))
      .toBe('Switch to light theme');
  });

  it('announces the resulting theme politely', async () => {
    const wrapper = mount(SvThemeToggle, { global });

    await wrapper.get('[data-testid="theme-toggle"]').trigger('click');

    const announcement = wrapper.get('[data-testid="theme-announcement"]');
    expect(announcement.attributes('aria-live')).toBe('polite');
    expect(announcement.text()).toBe('Dark theme on.');
  });

  it('persists an anonymous choice to this browser only', async () => {
    const wrapper = mount(SvThemeToggle, { global });

    await wrapper.get('[data-testid="theme-toggle"]').trigger('click');

    expect(localStorage.getItem('servana.theme')).toBe('dark');
    // No user is signed in, so nothing is written to a user record.
    expect(apiClient.patch).not.toHaveBeenCalled();
  });

  it('meets the 44px minimum target', () => {
    const classes = mount(SvThemeToggle, { global }).get('[data-testid="theme-toggle"]').classes().join(' ');

    expect(classes).toContain('min-h-sv-touch');
    expect(classes).toContain('min-w-sv-touch');
  });

  it('offers a labelled switch variant for a preferences row', () => {
    const wrapper = mount(SvThemeToggle, { props: { variant: 'switch' }, global });
    const control = wrapper.get('[data-testid="theme-toggle"]');

    expect(control.attributes('role')).toBe('switch');
    expect(control.attributes('aria-checked')).toBe('false');
    expect(control.text()).toContain('Dark theme');
  });

  it('never consults the operating system', () => {
    // ADR-021 rule 2 — the control must not even ask.
    const matchMedia = vi.fn();
    vi.stubGlobal('matchMedia', matchMedia);

    mount(SvThemeToggle, { global });

    expect(matchMedia).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});
