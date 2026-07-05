import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import AuditExportList from '@/pages/audit/AuditExportList.vue';
import { useAuthStore } from '@/stores/authStore';

const exp = {
  id: 'e1',
  branch: { id: 'b1', name: 'Westlands' },
  status: 'ready',
  reason: 'quarterly review',
  scope: { domains: [], severities: [], has_date_from: false, has_date_to: false },
  row_count: 5,
  download_count: 0,
  requested_at: null,
  generated_at: null,
  expires_at: null,
  first_downloaded_at: null,
  last_downloaded_at: null,
  failure_code: null,
  failure_message: null,
  created_at: null,
  can: { view: true, download: true, revoke: true },
};

const mountPage = () =>
  mount(AuditExportList, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } });

describe('AuditExportList.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('lists exports with status/rows/downloads and never renders file_id or paths', async () => {
    get.mockResolvedValueOnce({ data: { data: [exp] } });
    const auth = useAuthStore();
    auth.permissions = ['audit.export'];
    auth.branchIds = ['b1'];

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="audit-export-status"]').text()).toBe('ready');
    const html = wrapper.html();
    expect(html).not.toContain('file_id');
    expect(html).not.toContain('/storage/');
  });

  it('hides the request control for a user WITHOUT audit.export (permission-denied absent)', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = []; // no audit.export
    auth.branchIds = ['b1'];

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="audit-export-open"]').exists()).toBe(false);
  });

  it('shows a no-branch state and cannot request an export with no assigned branch', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = ['audit.export'];
    auth.branchIds = []; // no assigned branch

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="audit-export-no-branch"]').exists()).toBe(true);
    // The request button, if rendered, is disabled — no merchant-level/unassigned export.
    const btn = wrapper.find('[data-testid="audit-export-open"]');
    if (btn.exists()) expect(btn.attributes('disabled')).toBeDefined();
  });

  it('offers the request control to a permitted user with an assigned branch', async () => {
    get.mockResolvedValueOnce({ data: { data: [] } });
    const auth = useAuthStore();
    auth.permissions = ['audit.export'];
    auth.branchIds = ['b1'];

    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.find('[data-testid="audit-export-open"]').exists()).toBe(true);
  });
});
