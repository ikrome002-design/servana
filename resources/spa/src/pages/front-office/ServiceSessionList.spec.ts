import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const patch = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: (...a: unknown[]) => patch(...a),
  },
}));

import ServiceSessionList from '@/pages/front-office/ServiceSessionList.vue';

const completed = {
  id: 'ss1',
  status: 'completed',
  started_at: '2026-07-06T07:00:00+00:00',
  completed_at: '2026-07-06T07:30:00+00:00',
  cancelled_at: null,
  cancellation_reason: null,
  notes: null,
  preferred_personnel_honored: true,
  service: { id: 'sv1', name: 'Haircut', duration_minutes: 30 },
  client: { id: 'cl1', full_name: 'Amina Yusuf', phone_masked: '••• ••• 5678' },
  personnel: { id: 'st1', display_name: 'Joy W.' },
  commission_preview: { preview_status: 'not_configured', reason: 'compensation_not_configured', earned: false, payable: false, amount_minor: null, currency: null },
  can: { view: true, complete: false, cancel: false, update_notes: false },
};

const pending = {
  ...completed,
  id: 'ss2',
  status: 'pending',
  completed_at: null,
  commission_preview: null,
  can: { view: true, complete: false, cancel: true, update_notes: true },
};

const mountPage = () => mount(ServiceSessionList);

describe('ServiceSessionList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    patch.mockReset();
  });

  it('renders the non-payable preview wording for a completed session', async () => {
    get.mockResolvedValueOnce({ data: { data: [completed] } });
    const wrapper = mountPage();
    await flushPromises();

    const preview = wrapper.find('[data-testid="commission-preview"]');
    expect(preview.exists()).toBe(true);
    expect(preview.text()).toContain('Preview — not earned or payable');
    expect(preview.text()).toContain('Commission is not configured yet.');
    // The preview carries no monetary amount when not configured.
    expect(preview.text()).not.toMatch(/\bKES\b|\d+\.\d{2}/);
  });

  it('exposes cancel + notes controls only when the capability map allows them', async () => {
    get.mockResolvedValueOnce({ data: { data: [pending] } });
    const wrapper = mountPage();
    await flushPromises();

    const labels = wrapper.findAll('button').map((b) => b.text());
    expect(labels).toContain('Cancel');
    expect(labels).toContain('Notes');
  });

  it('requires a reason to cancel a pending session (modal teleported to body)', async () => {
    get.mockResolvedValue({ data: { data: [pending] } });
    post.mockResolvedValueOnce({ data: { data: { ...pending, status: 'cancelled' } } });
    const wrapper = mount(ServiceSessionList, { attachTo: document.body });
    await flushPromises();

    await wrapper.findAll('button').find((b) => b.text() === 'Cancel')!.trigger('click');
    await flushPromises();

    const bodyButtons = () => Array.from(document.body.querySelectorAll('button'));
    const confirm = () => bodyButtons().find((b) => b.textContent?.trim() === 'Cancel session');

    // Disabled with an empty reason.
    expect(confirm()?.hasAttribute('disabled')).toBe(true);

    const textarea = document.body.querySelector('textarea')!;
    textarea.value = 'Client left.';
    textarea.dispatchEvent(new Event('input'));
    await flushPromises();

    confirm()!.click();
    await flushPromises();

    expect(post).toHaveBeenCalledWith('/service-sessions/ss2/cancel', { reason: 'Client left.' });
    wrapper.unmount();
  });

  it('shows the empty state when there are no sessions', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('No service sessions match this filter.');
  });
});
