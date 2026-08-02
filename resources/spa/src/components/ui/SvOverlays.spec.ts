import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SvAccordion from '@/components/ui/SvAccordion.vue';
import SvConfirmDialog from '@/components/ui/SvConfirmDialog.vue';
import SvDialog from '@/components/ui/SvDialog.vue';
import SvDrawer from '@/components/ui/SvDrawer.vue';
import SvMenu from '@/components/ui/SvMenu.vue';
import SvPopover from '@/components/ui/SvPopover.vue';
import SvTabs from '@/components/ui/SvTabs.vue';
import SvTooltip from '@/components/ui/SvTooltip.vue';

/**
 * Phase UI-04 — overlay and disclosure contract (UI/UX plan §10, §19).
 *
 * The properties under test are the ones a keyboard or screen-reader user depends on and a
 * sighted mouse user never notices: focus containment, focus RESTORATION, Escape policy, and the
 * scroll lock preserving the page position.
 */

/** Teleported overlays render into document.body, so assertions read from there. */
function body(): HTMLElement {
  return document.body;
}

describe('SvDialog', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
  });

  it('renders nothing until it is open', () => {
    mount(SvDialog, { props: { open: false, title: 'Confirm' }, attachTo: document.body });

    expect(body().querySelector('[data-testid="sv-dialog"]')).toBeNull();
  });

  it('is a labelled modal dialog', async () => {
    const wrapper = mount(SvDialog, {
      props: { open: true, title: 'Confirm action', description: 'This cannot be undone.' },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();

    const dialog = body().querySelector('[data-testid="sv-dialog"]') as HTMLElement;
    expect(dialog.getAttribute('role')).toBe('dialog');
    expect(dialog.getAttribute('aria-modal')).toBe('true');

    const labelId = dialog.getAttribute('aria-labelledby');
    expect(document.getElementById(labelId ?? '')?.textContent?.trim()).toBe('Confirm action');

    const describedId = dialog.getAttribute('aria-describedby');
    expect(document.getElementById(describedId ?? '')?.textContent?.trim()).toBe('This cannot be undone.');
  });

  it('omits aria-describedby when there is no description to point at', async () => {
    const wrapper = mount(SvDialog, { props: { open: true, title: 'T' }, attachTo: document.body });
    await wrapper.vm.$nextTick();

    expect(body().querySelector('[data-testid="sv-dialog"]')?.getAttribute('aria-describedby')).toBeNull();
  });

  it('moves focus into the dialog on open', async () => {
    const wrapper = mount(SvDialog, { props: { open: false, title: 'T' }, attachTo: document.body });
    await wrapper.setProps({ open: true });
    await new Promise((resolve) => setTimeout(resolve, 0));

    const dialog = body().querySelector('[data-testid="sv-dialog"]') as HTMLElement;
    expect(dialog.contains(document.activeElement)).toBe(true);
  });

  it('restores focus to the invoking control on close', async () => {
    // Losing focus to <body> strands a keyboard user at the top of the document.
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();
    expect(document.activeElement).toBe(trigger);

    const wrapper = mount(SvDialog, { props: { open: false, title: 'T' }, attachTo: document.body });
    await wrapper.setProps({ open: true });
    await new Promise((resolve) => setTimeout(resolve, 0));

    await wrapper.setProps({ open: false });
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(document.activeElement).toBe(trigger);
  });

  it('locks page scroll while open and releases it on close', async () => {
    const wrapper = mount(SvDialog, { props: { open: false, title: 'T' }, attachTo: document.body });

    await wrapper.setProps({ open: true });
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(document.body.style.overflow).toBe('hidden');

    await wrapper.setProps({ open: false });
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(document.body.style.overflow).toBe('');
  });

  it('closes on Escape', async () => {
    const wrapper = mount(SvDialog, { props: { open: true, title: 'T' }, attachTo: document.body });
    await wrapper.vm.$nextTick();

    const dialog = body().querySelector('[data-testid="sv-dialog"]') as HTMLElement;
    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

    expect(wrapper.emitted('close')).toHaveLength(1);
  });

  it('ignores Escape and outside click when persistent', async () => {
    // A dialog mid-submission must not vanish and leave the outcome unknown.
    const wrapper = mount(SvDialog, {
      props: { open: true, title: 'T', persistent: true },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();

    const dialog = body().querySelector('[data-testid="sv-dialog"]') as HTMLElement;
    dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
    (body().querySelector('[data-testid="sv-dialog-scrim"]') as HTMLElement).click();

    expect(wrapper.emitted('close')).toBeUndefined();
  });

  it('closes on outside click by default and not when opted out', async () => {
    const dismissible = mount(SvDialog, { props: { open: true, title: 'T' }, attachTo: document.body });
    await dismissible.vm.$nextTick();
    (body().querySelector('[data-testid="sv-dialog-scrim"]') as HTMLElement).click();
    expect(dismissible.emitted('close')).toHaveLength(1);

    document.body.innerHTML = '';

    const sticky = mount(SvDialog, {
      props: { open: true, title: 'T', dismissOnOutsideClick: false },
      attachTo: document.body,
    });
    await sticky.vm.$nextTick();
    (body().querySelector('[data-testid="sv-dialog-scrim"]') as HTMLElement).click();
    expect(sticky.emitted('close')).toBeUndefined();
  });

  it('sits above the fixed footer, so the footer can never cover its controls', async () => {
    const wrapper = mount(SvDialog, { props: { open: true, title: 'T' }, attachTo: document.body });
    await wrapper.vm.$nextTick();

    // ADR-024: dialog z-index token is above the footer token.
    expect((body().querySelector('[data-testid="sv-dialog-root"]') as HTMLElement).className)
      .toContain('z-sv-dialog');
  });
});

describe('SvConfirmDialog', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
  });

  it('focuses Cancel for a destructive action', async () => {
    // Focusing "Delete" means a stray Enter destroys the record.
    const wrapper = mount(SvConfirmDialog, {
      props: { open: false, title: 'Delete run', message: 'This cannot be undone.', destructive: true },
      attachTo: document.body,
    });
    await wrapper.setProps({ open: true });
    await new Promise((resolve) => setTimeout(resolve, 10));

    expect((document.activeElement as HTMLElement | null)?.dataset.testid).toBe('sv-confirm-cancel');
  });

  it('prevents a duplicate confirmation while the request is in flight', async () => {
    const wrapper = mount(SvConfirmDialog, {
      props: { open: true, title: 'T', message: 'M', loading: true },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();

    const confirm = body().querySelector('[data-testid="sv-confirm-accept"]') as HTMLButtonElement;
    expect(confirm.disabled).toBe(true);
    confirm.click();
    expect(wrapper.emitted('confirm')).toBeUndefined();
  });

  it('keeps a server error visible instead of closing on failure', async () => {
    const wrapper = mount(SvConfirmDialog, {
      props: { open: true, title: 'T', message: 'M', error: 'The period is locked.' },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();

    expect(body().querySelector('[data-testid="sv-confirm-error"]')?.textContent).toContain('The period is locked.');
    expect(wrapper.emitted('cancel')).toBeUndefined();
  });
});

describe('SvDrawer', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    document.body.style.overflow = '';
  });

  it('is a labelled dialog', async () => {
    const wrapper = mount(SvDrawer, { props: { open: true, title: 'Filters' }, attachTo: document.body });
    await wrapper.vm.$nextTick();

    const drawer = body().querySelector('[data-testid="sv-drawer"]') as HTMLElement;
    expect(drawer.getAttribute('role')).toBe('dialog');
    const labelId = drawer.getAttribute('aria-labelledby');
    expect(document.getElementById(labelId ?? '')?.textContent?.trim()).toBe('Filters');
  });

  it('resolves responsive placement by CSS class, never by measuring the viewport', async () => {
    // CLAUDE.md guardrail 1. `md:` prefixes mean the browser decides on resize, with no listener.
    const wrapper = mount(SvDrawer, {
      props: { open: true, title: 'Filters', placement: 'responsive' },
      attachTo: document.body,
    });
    await wrapper.vm.$nextTick();

    const drawer = body().querySelector('[data-testid="sv-drawer"]') as HTMLElement;
    expect(drawer.className).toContain('bottom-0');
    expect(drawer.className).toContain('md:inset-y-0');
    expect(drawer.dataset.placement).toBe('responsive');
  });

  it('shares the one focus trap, restoring focus on close', async () => {
    const trigger = document.createElement('button');
    document.body.appendChild(trigger);
    trigger.focus();

    const wrapper = mount(SvDrawer, { props: { open: false, title: 'Filters' }, attachTo: document.body });
    await wrapper.setProps({ open: true });
    await new Promise((resolve) => setTimeout(resolve, 0));
    await wrapper.setProps({ open: false });
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(document.activeElement).toBe(trigger);
  });
});

describe('SvMenu', () => {
  const items = [
    { id: 'edit', label: 'Edit' },
    { id: 'archive', label: 'Archive', disabled: true },
    { id: 'delete', label: 'Delete', destructive: true },
  ];

  it('exposes the menu-button relationship', () => {
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' } });
    const trigger = wrapper.get('[data-testid="sv-menu-trigger"]');

    expect(trigger.attributes('aria-haspopup')).toBe('menu');
    expect(trigger.attributes('aria-expanded')).toBe('false');
    expect(trigger.attributes('aria-controls')).toBeTruthy();
  });

  it('opens on ArrowDown and lands on the first item', async () => {
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' }, attachTo: document.body });

    await wrapper.get('[data-testid="sv-menu-trigger"]').trigger('keydown', { key: 'ArrowDown' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(wrapper.find('[data-testid="sv-menu"]').exists()).toBe(true);
    expect((document.activeElement as HTMLElement | null)?.textContent?.trim()).toBe('Edit');
    wrapper.unmount();
  });

  it('skips a disabled item when arrowing', async () => {
    // Focusing an item only to refuse activation is worse than not focusing it.
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' }, attachTo: document.body });

    await wrapper.get('[data-testid="sv-menu-trigger"]').trigger('keydown', { key: 'ArrowDown' });
    await new Promise((resolve) => setTimeout(resolve, 0));
    await wrapper.get('[data-testid="sv-menu"]').trigger('keydown', { key: 'ArrowDown' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect((document.activeElement as HTMLElement | null)?.textContent?.trim()).toBe('Delete');
    wrapper.unmount();
  });

  it('never activates a disabled item', async () => {
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' } });
    await wrapper.get('[data-testid="sv-menu-trigger"]').trigger('click');

    await wrapper.findAll('[role="menuitem"]')[1].trigger('click');

    expect(wrapper.emitted('select')).toBeUndefined();
  });

  it('emits the selected id and closes', async () => {
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' } });
    await wrapper.get('[data-testid="sv-menu-trigger"]').trigger('click');

    await wrapper.findAll('[role="menuitem"]')[0].trigger('click');

    expect(wrapper.emitted('select')?.[0]).toEqual(['edit']);
    expect(wrapper.find('[data-testid="sv-menu"]').exists()).toBe(false);
  });

  it('closes on Escape', async () => {
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' } });
    await wrapper.get('[data-testid="sv-menu-trigger"]').trigger('click');

    await wrapper.get('[data-testid="sv-menu"]').trigger('keydown', { key: 'Escape' });

    expect(wrapper.find('[data-testid="sv-menu"]').exists()).toBe(false);
  });

  it('uses roving tabindex so exactly one item is in the tab order', async () => {
    const wrapper = mount(SvMenu, { props: { items, label: 'Row actions' }, attachTo: document.body });
    await wrapper.get('[data-testid="sv-menu-trigger"]').trigger('keydown', { key: 'ArrowDown' });
    await new Promise((resolve) => setTimeout(resolve, 0));

    const tabbable = wrapper.findAll('[role="menuitem"]').filter((i) => i.attributes('tabindex') === '0');
    expect(tabbable).toHaveLength(1);
    wrapper.unmount();
  });
});

describe('SvTabs', () => {
  const tabs = [
    { id: 'overview', label: 'Overview' },
    { id: 'history', label: 'History' },
    { id: 'locked', label: 'Locked', disabled: true },
  ];

  it('implements the tablist/tab/tabpanel relationship', () => {
    const wrapper = mount(SvTabs, { props: { tabs, modelValue: 'overview', label: 'Sections' } });

    expect(wrapper.get('[role="tablist"]').attributes('aria-label')).toBe('Sections');
    const tab = wrapper.findAll('[role="tab"]')[0];
    expect(tab.attributes('aria-selected')).toBe('true');
    expect(tab.attributes('aria-controls')).toBe('sv-tabpanel-overview');
    expect(wrapper.get('[role="tabpanel"]').attributes('aria-labelledby')).toBe('sv-tab-overview');
  });

  it('renders only the selected panel, so a hidden panel is not reachable by Tab', () => {
    const wrapper = mount(SvTabs, { props: { tabs, modelValue: 'overview', label: 'Sections' } });

    expect(wrapper.findAll('[role="tabpanel"]')).toHaveLength(1);
  });

  it('selects as focus moves under automatic activation', async () => {
    const wrapper = mount(SvTabs, { props: { tabs, modelValue: 'overview', label: 'Sections' } });

    await wrapper.findAll('[role="tab"]')[0].trigger('keydown', { key: 'ArrowRight' });

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['history']);
  });

  it('does not select on arrow under manual activation', async () => {
    // Manual exists so arrowing past three tabs does not fire three requests.
    const wrapper = mount(SvTabs, {
      props: { tabs, modelValue: 'overview', label: 'Sections', activation: 'manual' },
    });

    await wrapper.findAll('[role="tab"]')[0].trigger('keydown', { key: 'ArrowRight' });
    expect(wrapper.emitted('update:modelValue')).toBeUndefined();

    await wrapper.findAll('[role="tab"]')[1].trigger('keydown', { key: 'Enter' });
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['history']);
  });

  it('never selects a disabled tab', async () => {
    const wrapper = mount(SvTabs, { props: { tabs, modelValue: 'overview', label: 'Sections' } });

    await wrapper.findAll('[role="tab"]')[2].trigger('click');

    expect(wrapper.emitted('update:modelValue')).toBeUndefined();
  });
});

describe('SvAccordion', () => {
  const items = [
    { id: 'a', label: 'Section A' },
    { id: 'b', label: 'Section B' },
  ];

  it('renders each header as a heading containing a button', () => {
    const wrapper = mount(SvAccordion, { props: { items, modelValue: [], headingLevel: 'h3' } });

    expect(wrapper.findAll('h3')).toHaveLength(2);
    expect(wrapper.find('h3 button').exists()).toBe(true);
  });

  it('exposes the expanded/controls relationship', () => {
    const wrapper = mount(SvAccordion, { props: { items, modelValue: ['a'] } });
    const buttons = wrapper.findAll('button');

    expect(buttons[0].attributes('aria-expanded')).toBe('true');
    expect(buttons[0].attributes('aria-controls')).toBe('sv-accordion-panel-a');
    expect(buttons[1].attributes('aria-expanded')).toBe('false');
  });

  it('closes the previous panel in single mode', async () => {
    const wrapper = mount(SvAccordion, { props: { items, modelValue: ['a'], multiple: false } });

    await wrapper.findAll('button')[1].trigger('click');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['b']]);
  });

  it('keeps both open in multiple mode', async () => {
    const wrapper = mount(SvAccordion, { props: { items, modelValue: ['a'], multiple: true } });

    await wrapper.findAll('button')[1].trigger('click');

    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['a', 'b']]);
  });
});

describe('SvTooltip', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('describes rather than labels, so it never becomes the only accessible name', () => {
    const wrapper = mount(SvTooltip, {
      props: { text: 'Verified by finance' },
      slots: { default: '<button>Info</button>' },
    });

    // The relationship the component offers is described-by; it exposes no labelling id.
    expect(wrapper.html()).not.toContain('aria-labelledby');
  });

  it('shows immediately on focus, without waiting for the hover delay', async () => {
    const wrapper = mount(SvTooltip, {
      props: { text: 'Verified by finance' },
      slots: { default: '<button>Info</button>' },
    });

    await wrapper.trigger('focusin');

    expect(wrapper.find('[data-testid="sv-tooltip"]').exists()).toBe(true);
  });

  it('waits the configured delay on hover', async () => {
    const wrapper = mount(SvTooltip, {
      props: { text: 'Verified', delayMs: 200 },
      slots: { default: '<button>Info</button>' },
    });

    await wrapper.trigger('mouseenter');
    expect(wrapper.find('[data-testid="sv-tooltip"]').exists()).toBe(false);

    vi.advanceTimersByTime(200);
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[data-testid="sv-tooltip"]').exists()).toBe(true);
  });

  it('is dismissible with Escape without moving focus (WCAG 1.4.13)', async () => {
    const wrapper = mount(SvTooltip, {
      props: { text: 'Verified' },
      slots: { default: '<button>Info</button>' },
    });
    await wrapper.trigger('focusin');

    await wrapper.trigger('keydown', { key: 'Escape' });

    expect(wrapper.find('[data-testid="sv-tooltip"]').exists()).toBe(false);
  });

  it('has role=tooltip', async () => {
    const wrapper = mount(SvTooltip, {
      props: { text: 'Verified' },
      slots: { default: '<button>Info</button>' },
    });
    await wrapper.trigger('focusin');

    expect(wrapper.get('[data-testid="sv-tooltip"]').attributes('role')).toBe('tooltip');
  });
});

describe('SvPopover', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('renders nothing until it is open', () => {
    const wrapper = mount(SvPopover, { props: { open: false, label: 'Filters' } });

    expect(wrapper.find('[data-testid="sv-popover"]').exists()).toBe(false);
  });

  it('is a NAMED region, so a screen reader knows what the panel is', () => {
    const wrapper = mount(SvPopover, { props: { open: true, label: 'Filters' } });
    const panel = wrapper.get('[data-testid="sv-popover"]');

    expect(panel.attributes('role')).toBe('group');
    expect(panel.attributes('aria-label')).toBe('Filters');
  });

  it('is non-modal — it never locks the page behind it', () => {
    // The distinction from SvDialog: the rest of the page stays usable.
    mount(SvPopover, { props: { open: true, label: 'Filters' }, attachTo: document.body });

    expect(document.body.style.overflow).toBe('');
  });

  it('closes on Escape', async () => {
    const wrapper = mount(SvPopover, { props: { open: true, label: 'Filters' }, attachTo: document.body });

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    await wrapper.vm.$nextTick();

    expect(wrapper.emitted('close')).toHaveLength(1);
    wrapper.unmount();
  });

  it('closes on an outside pointer press', async () => {
    const wrapper = mount(SvPopover, { props: { open: true, label: 'Filters' }, attachTo: document.body });

    // jsdom does not implement the PointerEvent constructor, so the event is built generically.
    // The listener only reads `event.target`, which this supplies correctly.
    const outside = document.createElement('div');
    document.body.appendChild(outside);
    outside.dispatchEvent(new Event('pointerdown', { bubbles: true }));
    await wrapper.vm.$nextTick();

    expect(wrapper.emitted('close')).toHaveLength(1);
    wrapper.unmount();
  });

  it('caps its width to the viewport, so it cannot push the page sideways', () => {
    // Collision handling is CSS, not a JavaScript viewport measurement (CLAUDE.md guardrail 1).
    const wrapper = mount(SvPopover, { props: { open: true, label: 'Filters' } });

    expect(wrapper.get('[data-testid="sv-popover"]').classes().join(' ')).toContain('max-w-[calc(100vw-2rem)]');
  });

  it('flips its anchor by class rather than by measuring', () => {
    const bottom = mount(SvPopover, { props: { open: true, label: 'F', placement: 'bottom' } });
    const top = mount(SvPopover, { props: { open: true, label: 'F', placement: 'top' } });

    expect(bottom.get('[data-testid="sv-popover"]').classes()).toContain('top-full');
    expect(top.get('[data-testid="sv-popover"]').classes()).toContain('bottom-full');
  });
});
