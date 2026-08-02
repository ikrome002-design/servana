import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    patch: vi.fn(),
  },
}));

import ServiceCatalogue from '@/pages/branch/ServiceCatalogue.vue';
import { useAuthStore } from '@/stores/authStore';

const service = {
  id: 's1',
  category_id: 'c1',
  category_name: 'Hair',
  name: 'Gel manicure',
  description: null,
  price_minor: 250000,
  currency: 'KES',
  duration_minutes: 45,
  status: 'active',
};

function mockLoad(services: unknown[] = [service]): void {
  // Promise.all([fetchCategories, fetchServices]) → /service-categories then /services
  get.mockImplementation((url: string) => {
    if (url === '/service-categories') return Promise.resolve({ data: { data: [{ id: 'c1', name: 'Hair', sort_order: 0, archived: false }] } });
    return Promise.resolve({ data: { data: services } });
  });
}

const mountPage = () =>
  mount(ServiceCatalogue, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, SvDialog: { template: '<div v-if="open"><slot /></div>', props: ['open', 'title'] } } } });

describe('ServiceCatalogue.vue', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('renders services with masked-free catalogue data and price', async () => {
    mockLoad();
    const wrapper = mountPage();
    await flushPromises();

    expect(wrapper.text()).toContain('Gel manicure');
    expect(wrapper.text()).toContain('KES');
    expect(wrapper.find('[data-testid="service-status"]').text()).toBe('active');
  });

  it('shows the add-service action only with service.create', async () => {
    mockLoad([]);
    const auth = useAuthStore();
    auth.permissions = ['service.create'];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="add-service"]').exists()).toBe(true);
  });

  it('hides catalogue mutations without permission', async () => {
    mockLoad([service]);
    const auth = useAuthStore();
    auth.permissions = [];
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.find('[data-testid="add-service"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="archive-s1"]').exists()).toBe(false);
  });

  it('shows an empty state when there are no services', async () => {
    mockLoad([]);
    const wrapper = mountPage();
    await flushPromises();
    expect(wrapper.text()).toContain('No services in this branch yet');
  });
});
