import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import SvBreadcrumbs from '@/components/ui/SvBreadcrumbs.vue';
import SvButton from '@/components/ui/SvButton.vue';
import SvCard from '@/components/ui/SvCard.vue';
import SvDateTime from '@/components/ui/SvDateTime.vue';
import SvIconButton from '@/components/ui/SvIconButton.vue';
import SvLink from '@/components/ui/SvLink.vue';
import SvLogo from '@/components/ui/SvLogo.vue';
import SvMetricCard from '@/components/ui/SvMetricCard.vue';
import SvMoney from '@/components/ui/SvMoney.vue';
import SvPageHeader from '@/components/ui/SvPageHeader.vue';
import SvStatusBadge from '@/components/ui/SvStatusBadge.vue';
import { SvIconClose } from '@/design-system/icons';

/**
 * Phase UI-04 — shared primitive contract (UI/UX plan §10).
 *
 * These assert BEHAVIOUR that matters, not markup shape: the properties a later phase could
 * plausibly regress. Class-name snapshots are deliberately avoided — they fail on every visual
 * tweak and prove nothing about the contract.
 */

const RouterLinkStub = {
  props: ['to'],
  template: '<a :href="typeof to === \'string\' ? to : \'#\'"><slot /></a>',
};

const global = { stubs: { RouterLink: RouterLinkStub } };

describe('SvButton', () => {
  it('is a real button element, not a styled div', () => {
    expect(mount(SvButton, { slots: { default: 'Save' } }).element.tagName).toBe('BUTTON');
  });

  it('defaults to type=button so it cannot submit a form by accident', () => {
    expect(mount(SvButton).attributes('type')).toBe('button');
  });

  it('blocks activation while loading, so a double click cannot submit twice', async () => {
    const wrapper = mount(SvButton, { props: { loading: true } });

    expect(wrapper.attributes('disabled')).toBeDefined();
    expect(wrapper.attributes('aria-busy')).toBe('true');

    await wrapper.trigger('click');
    // The DOM suppresses the event on a disabled button; assert no emission either way.
    expect(wrapper.emitted('click')).toBeUndefined();
  });

  it('announces the loading state once, not twice', () => {
    const wrapper = mount(SvButton, { props: { loading: true }, slots: { default: 'Save' } });

    // The spinner is decorative; the announcement is the sr-only label plus aria-busy.
    expect(wrapper.find('[aria-hidden="true"].animate-spin').exists()).toBe(true);
    expect(wrapper.find('.sr-only').text()).toBe('Working…');
  });

  it('is genuinely non-interactive when disabled', async () => {
    const wrapper = mount(SvButton, { props: { disabled: true } });

    expect(wrapper.attributes('disabled')).toBeDefined();
    expect(wrapper.attributes('aria-disabled')).toBe('true');
    await wrapper.trigger('click');
    expect(wrapper.emitted('click')).toBeUndefined();
  });

  it('emits click when enabled', async () => {
    const wrapper = mount(SvButton);

    await wrapper.trigger('click');
    expect(wrapper.emitted('click')).toHaveLength(1);
  });

  it('keeps a 44px minimum target at every size', () => {
    for (const size of ['sm', 'md', 'lg'] as const) {
      const classes = mount(SvButton, { props: { size } }).classes().join(' ');
      expect(classes).toContain('min-h-sv-touch');
      expect(classes).toContain('min-w-sv-touch');
    }
  });

  it('uses semantic tokens rather than raw palette classes', () => {
    // The pre-UI-04 component carried `bg-red-700` and `hover:bg-orange-400`.
    const classes = mount(SvButton, { props: { variant: 'destructive' } }).classes().join(' ');
    expect(classes).not.toMatch(/\b(bg|text|border)-(red|orange|green|blue|amber)-\d{3}\b/);
    expect(classes).toContain('bg-sv-error-border');
  });
});

describe('SvIconButton', () => {
  it('always carries the required accessible name', () => {
    const wrapper = mount(SvIconButton, { props: { icon: SvIconClose, label: 'Close dialog' } });

    expect(wrapper.attributes('aria-label')).toBe('Close dialog');
  });

  it('hides the glyph from assistive technology so the name is announced once', () => {
    const wrapper = mount(SvIconButton, { props: { icon: SvIconClose, label: 'Close dialog' } });

    expect(wrapper.find('svg').attributes('aria-hidden')).toBe('true');
  });

  it('exposes disclosure state when used as a trigger', () => {
    const wrapper = mount(SvIconButton, {
      props: { icon: SvIconClose, label: 'Open menu', expanded: true, controls: 'menu-1' },
    });

    expect(wrapper.attributes('aria-expanded')).toBe('true');
    expect(wrapper.attributes('aria-controls')).toBe('menu-1');
  });

  it('meets the 44px minimum target', () => {
    const classes = mount(SvIconButton, { props: { icon: SvIconClose, label: 'x' } }).classes().join(' ');

    expect(classes).toContain('min-h-sv-touch');
    expect(classes).toContain('min-w-sv-touch');
  });
});

describe('SvLink', () => {
  it('renders an anchor for an external destination', () => {
    const wrapper = mount(SvLink, { props: { href: 'https://citruslabs.co.ke/' }, global });

    expect(wrapper.element.tagName).toBe('A');
    expect(wrapper.attributes('href')).toBe('https://citruslabs.co.ke/');
  });

  it('applies BOTH noopener and noreferrer when opening a new tab', () => {
    // `noopener` alone still leaks the referrer; the pair is the security control (ADR-024).
    const wrapper = mount(SvLink, {
      props: { href: 'https://x.com/LabsCitrus', newTab: true },
      global,
    });

    expect(wrapper.attributes('target')).toBe('_blank');
    expect(wrapper.attributes('rel')).toBe('noopener noreferrer');
  });

  it('states in text that a link opens a new tab', () => {
    const wrapper = mount(SvLink, { props: { href: 'https://x.com/', newTab: true }, global });

    expect(wrapper.find('.sr-only').text()).toContain('opens in a new tab');
  });

  it('does not set target or rel for a same-tab external link', () => {
    const wrapper = mount(SvLink, { props: { href: 'https://citruslabs.co.ke/' }, global });

    expect(wrapper.attributes('target')).toBeUndefined();
    expect(wrapper.attributes('rel')).toBeUndefined();
  });

  it('renders a disabled link as a span so it is neither focusable nor announced as a link', () => {
    const wrapper = mount(SvLink, { props: { href: 'https://x.com/', disabled: true }, global });

    expect(wrapper.element.tagName).toBe('SPAN');
    expect(wrapper.attributes('aria-disabled')).toBe('true');
    expect(wrapper.attributes('href')).toBeUndefined();
  });
});

describe('SvLogo', () => {
  it('uses the approved exact-case PNG and never the deleted SVG', () => {
    const wrapper = mount(SvLogo);

    expect(wrapper.attributes('src')).toBe('/assets/brand/Logo.png');
    expect(wrapper.html()).not.toContain('Logo.svg');
  });

  it('declares dimensions so the shell does not shift while it loads', () => {
    const wrapper = mount(SvLogo, { props: { size: 'md' } });

    expect(wrapper.attributes('width')).toBe('32');
    expect(wrapper.attributes('height')).toBe('32');
  });

  it('names itself by default and goes decorative only on request', () => {
    expect(mount(SvLogo).attributes('alt')).toBe('Servana by Citrus');
    expect(mount(SvLogo, { props: { decorative: true } }).attributes('alt')).toBe('');
  });
});

describe('SvPageHeader', () => {
  it('renders exactly one h1 for a page', () => {
    const wrapper = mount(SvPageHeader, { props: { title: 'Payout runs' } });

    expect(wrapper.findAll('h1')).toHaveLength(1);
    expect(wrapper.get('h1').text()).toBe('Payout runs');
  });

  it('can drop to h2 inside a dialog so the outline stays coherent', () => {
    const wrapper = mount(SvPageHeader, { props: { title: 'Confirm', headingLevel: 'h2' } });

    expect(wrapper.findAll('h1')).toHaveLength(0);
    expect(wrapper.get('h2').text()).toBe('Confirm');
  });

  it('renders the actions region only when actions are supplied', () => {
    expect(mount(SvPageHeader, { props: { title: 'T' } }).find('[data-testid="sv-page-actions"]').exists()).toBe(false);
    expect(
      mount(SvPageHeader, { props: { title: 'T' }, slots: { actions: '<button>Add</button>' } })
        .find('[data-testid="sv-page-actions"]')
        .exists(),
    ).toBe(true);
  });
});

describe('SvBreadcrumbs', () => {
  const items = [
    { label: 'Finance', to: '/finance' },
    { label: 'Payout runs', to: '/finance/payout-runs' },
    { label: 'PR-0042' },
  ];

  it('is a named nav landmark containing an ordered list', () => {
    const wrapper = mount(SvBreadcrumbs, { props: { items }, global });

    expect(wrapper.element.tagName).toBe('NAV');
    expect(wrapper.attributes('aria-label')).toBe('Breadcrumb');
    expect(wrapper.find('ol').exists()).toBe(true);
  });

  it('marks the current page and does not link it', () => {
    const wrapper = mount(SvBreadcrumbs, { props: { items }, global });
    const current = wrapper.get('[aria-current="page"]');

    expect(current.text()).toBe('PR-0042');
    expect(current.element.tagName).toBe('SPAN');
  });

  it('keeps collapsed items in the DOM so truncation is visual only', () => {
    const long = [
      { label: 'A', to: '/a' },
      { label: 'B', to: '/b' },
      { label: 'C', to: '/c' },
      { label: 'D', to: '/d' },
      { label: 'E' },
    ];
    const wrapper = mount(SvBreadcrumbs, { props: { items: long }, global });

    // All five remain present; the middle ones are merely hidden below the tablet breakpoint.
    expect(wrapper.findAll('li')).toHaveLength(5);
    expect(wrapper.html()).toContain('hidden md:flex');
  });
});

describe('SvCard', () => {
  it('is not interactive — a card never swallows the controls inside it', () => {
    const wrapper = mount(SvCard, { slots: { default: '<button>Open</button>' } });

    expect(wrapper.element.tagName).toBe('DIV');
    expect(wrapper.attributes('role')).toBeUndefined();
    expect(wrapper.attributes('tabindex')).toBeUndefined();
  });

  it('can render as a semantic element for its context', () => {
    expect(mount(SvCard, { props: { as: 'li' } }).element.tagName).toBe('LI');
  });

  it('renders header, actions and footer regions only when supplied', () => {
    const bare = mount(SvCard, { slots: { default: 'x' } });
    expect(bare.find('.mb-4').exists()).toBe(false);

    const full = mount(SvCard, {
      slots: { default: 'x', header: '<h2>H</h2>', actions: '<button>A</button>', footer: 'F' },
    });
    expect(full.text()).toContain('H');
    expect(full.text()).toContain('F');
  });
});

describe('SvMetricCard', () => {
  it('shows a skeleton while loading rather than a zero', () => {
    const wrapper = mount(SvMetricCard, { props: { label: 'Revenue', loading: true } });

    expect(wrapper.find('[data-testid="sv-skeleton"]').exists()).toBe(true);
    expect(wrapper.find('[data-testid="sv-metric-value"]').exists()).toBe(false);
  });

  it('states the trend direction in text, never colour alone', () => {
    const wrapper = mount(SvMetricCard, { props: { label: 'Revenue', trend: 'up' } });

    expect(wrapper.get('[data-testid="sv-metric-trend"]').text()).toContain('Increased');
  });

  it('treats an increase as bad when the caller says an increase is bad', () => {
    // Revenue up is good; refunds up is not. The component must not guess.
    const good = mount(SvMetricCard, { props: { label: 'Revenue', trend: 'up', increaseIsPositive: true } });
    const bad = mount(SvMetricCard, { props: { label: 'Refunds', trend: 'up', increaseIsPositive: false } });

    expect(good.get('[data-testid="sv-metric-trend"]').classes()).toContain('text-sv-success-fg');
    expect(bad.get('[data-testid="sv-metric-trend"]').classes()).toContain('text-sv-error-fg');
  });
});

describe('SvStatusBadge', () => {
  it('always renders the status as text', () => {
    expect(mount(SvStatusBadge, { props: { label: 'Paid', tone: 'success' } }).text()).toContain('Paid');
  });

  it('degrades an unrecognised tone to neutral, never to success', () => {
    const wrapper = mount(SvStatusBadge, {
      // Deliberately outside the typed vocabulary — this is the runtime fail-safe.
      props: { label: 'Weird', tone: 'chartreuse' as unknown as 'neutral' },
    });

    expect(wrapper.attributes('data-tone')).toBe('neutral');
  });

  it('gives assistive technology context for a bare status word', () => {
    expect(mount(SvStatusBadge, { props: { label: 'Paid' } }).find('.sr-only').text()).toBe('Status:');
  });
});

describe('SvMoney', () => {
  it('formats integer minor units through the shared formatter', () => {
    const wrapper = mount(SvMoney, { props: { minorUnits: 123456 } });

    expect(wrapper.text()).toContain('1,234.56');
    expect(wrapper.attributes('data-available')).toBe('true');
  });

  it('renders an unavailable amount as unavailable, NOT as zero', () => {
    // The direct lesson of UI01-RENDER-001: coercing missing money to zero states a false fact.
    const wrapper = mount(SvMoney, { props: { minorUnits: null } });

    expect(wrapper.text()).toBe('Not available');
    expect(wrapper.text()).not.toContain('0.00');
    expect(wrapper.attributes('data-available')).toBe('false');
  });

  it('distinguishes an unavailable amount from a genuine zero', () => {
    const zero = mount(SvMoney, { props: { minorUnits: 0 } });

    expect(zero.text()).toContain('0.00');
    expect(zero.attributes('data-available')).toBe('true');
  });

  it('prefers the server-formatted string when supplied', () => {
    const wrapper = mount(SvMoney, { props: { minorUnits: 100, formatted: 'KES 1.00' } });

    expect(wrapper.text()).toBe('KES 1.00');
  });

  it('refuses a non-integer minor-unit value instead of rounding it away', () => {
    // A fractional minor unit means float arithmetic happened upstream; hiding it hides the defect.
    const wrapper = mount(SvMoney, { props: { minorUnits: 1234.5 } });

    expect(wrapper.attributes('data-available')).toBe('false');
    expect(wrapper.text()).toBe('Not available');
  });

  it('honours a non-KES currency rather than assuming KES', () => {
    // 5000 minor units is 50.00, and the currency must be carried through, not defaulted to KES.
    const text = mount(SvMoney, { props: { minorUnits: 5000, currency: 'USD' } }).text();

    expect(text).toContain('50.00');
    expect(text).not.toContain('KES');
  });

  it('uses tabular numerals so a column of amounts aligns', () => {
    expect(mount(SvMoney, { props: { minorUnits: 1 } }).classes()).toContain('sv-numeric');
  });
});

describe('SvDateTime', () => {
  it('renders a machine-readable time element', () => {
    const wrapper = mount(SvDateTime, { props: { value: '2026-07-15T09:00:00Z' } });

    expect(wrapper.element.tagName).toBe('TIME');
    expect(wrapper.attributes('datetime')).toBe('2026-07-15T09:00:00Z');
  });

  it('keeps a date-only business value date-only', () => {
    // Adding a time would invent precision the record does not have.
    const wrapper = mount(SvDateTime, { props: { value: '2026-07-31', mode: 'date' } });

    expect(wrapper.attributes('datetime')).toBe('2026-07-31');
    expect(wrapper.text()).not.toMatch(/\d{2}:\d{2}/);
  });

  it('renders a missing value as unavailable, never as now or the epoch', () => {
    const wrapper = mount(SvDateTime, { props: { value: null } });

    expect(wrapper.text()).toBe('Not available');
    expect(wrapper.attributes('data-available')).toBe('false');
    expect(wrapper.attributes('datetime')).toBeUndefined();
  });

  it('renders an unparseable value as unavailable rather than "Invalid Date"', () => {
    expect(mount(SvDateTime, { props: { value: 'not-a-date' } }).text()).toBe('Not available');
  });

  it('formats in Africa/Nairobi regardless of the browser timezone', () => {
    // 2026-07-15T21:30:00Z is 2026-07-16 00:30 in Nairobi (UTC+3).
    const wrapper = mount(SvDateTime, { props: { value: '2026-07-15T21:30:00Z' } });

    expect(wrapper.text()).toContain('16');
  });
});

describe('no component reaches for device detection', () => {
  it('never calls matchMedia during render', () => {
    // CLAUDE.md guardrail 1: responsive behaviour is CSS media queries only.
    const matchMedia = vi.fn();
    vi.stubGlobal('matchMedia', matchMedia);

    mount(SvButton);
    mount(SvCard);
    mount(SvPageHeader, { props: { title: 'T' } });
    mount(SvMoney, { props: { minorUnits: 1 } });

    expect(matchMedia).not.toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});
