import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();

vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import FeatureFlags from '@/pages/platform/FeatureFlags.vue';
import { useAuthStore } from '@/stores/authStore';

const VIEW = 'platform.settings.view';
const UPDATE = 'platform.settings.update';

/** The real shipped state: the code allowlist is empty, and the endpoint says so. */
function respondEmptyCatalogue(): void {
  get.mockImplementation((url: string) => {
    if (url === '/platform/feature-flags') {
      return Promise.resolve({
        data: {
          data: [],
          meta: {
            environment: 'testing',
            catalogue_size: 0,
            catalogue_is_empty: true,
            catalogue_source: 'config/platform-feature-flags.php',
            note: 'A flag exists only after it is added to the code allowlist.',
          },
        },
      });
    }
    return Promise.resolve({ data: { data: [] } });
  });
}

function respondPopulatedCatalogue(): void {
  get.mockImplementation((url: string) => {
    if (url === '/platform/feature-flags') {
      return Promise.resolve({
        data: {
          data: [
            {
              definition: 'billing.new_invoice_layout',
              state: {
                id: '01JFLAG0000000000000000001',
                environment: 'testing',
                state: 'paused',
                rollout_basis_points: 2500,
                effective_from: '2026-08-01T00:00:00Z',
                effective_to: null,
                version: 2,
                approved_configuration_hash: 'abc123',
                targets: [],
              },
              effective_state: 'paused',
            },
          ],
          meta: {
            environment: 'testing',
            catalogue_size: 1,
            catalogue_is_empty: false,
            catalogue_source: 'config/platform-feature-flags.php',
            note: '',
          },
        },
      });
    }
    return Promise.resolve({ data: { data: [], meta: { append_only: true } } });
  });
}

async function mountWith(permissions: string[]) {
  const auth = useAuthStore();
  auth.permissions = permissions;
  const wrapper = mount(FeatureFlags, { global: { stubs: { Teleport: true } } });
  await flushPromises();
  return wrapper;
}

describe('FeatureFlags.vue — contract page §5.4.20', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    respondEmptyCatalogue();
  });

  it('renders its own page title as the single h1', async () => {
    const wrapper = await mountWith([VIEW]);
    const headings = wrapper.findAll('h1');
    expect(headings).toHaveLength(1);
    expect(headings[0].text()).toBe('Feature flags');
  });

  it('reads the shipped feature-flag endpoint', async () => {
    await mountWith([VIEW]);
    expect(get.mock.calls.map((c) => c[0] as string)).toContain('/platform/feature-flags');
  });

  it('renders the permission boundary and issues no request without the key', async () => {
    const wrapper = await mountWith([]);
    expect(wrapper.find('[data-testid="flags-empty"]').exists()).toBe(false);
    expect(get).not.toHaveBeenCalled();
  });

  /**
   * The catalogue is TRUTHFULLY empty today. The page must say so and explain why, not invent a
   * placeholder flag to look complete — a seeded example would misrepresent what the platform is
   * actually running.
   */
  it('renders a truthful empty state that explains why the catalogue is empty', async () => {
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="flags-empty"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('No feature flag is currently allowlisted');
    expect(wrapper.text()).toContain('added to the platform');
  });

  it('seeds no example flag and fabricates no health figure when empty', async () => {
    const wrapper = await mountWith([VIEW, UPDATE]);
    expect(wrapper.find('[data-testid="flags-list"]').exists()).toBe(false);
    const text = wrapper.text().toLowerCase();
    expect(text).not.toContain('healthy');
    expect(text).not.toContain('example');
  });

  it('reports the real catalogue size and its source', async () => {
    const wrapper = await mountWith([VIEW]);
    const meta = wrapper.find('[data-testid="flags-catalogue-meta"]');
    expect(meta.text()).toContain('config/platform-feature-flags.php');
    expect(meta.text()).toContain('0');
  });

  /**
   * A flag must never read as an access grant. The evaluator has no access to permissions,
   * entitlements, billing state or account context, and cannot open External Gate W.
   */
  it('describes a flag as restrictive and never as a grant of access', async () => {
    const wrapper = await mountWith([VIEW]);
    const text = wrapper.text();
    expect(text).toContain('can never grant access');
    expect(text.toLowerCase()).not.toContain('grant access to');
  });

  it('offers no create-flag control, because a flag requires a code change first', async () => {
    const wrapper = await mountWith([VIEW, UPDATE]);
    const text = wrapper.text().toLowerCase();
    expect(text).not.toContain('create flag');
    expect(text).not.toContain('new flag');
    expect(text).not.toContain('add flag');
  });

  it('renders an allowlisted flag with its rollout state when the catalogue is populated', async () => {
    respondPopulatedCatalogue();
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="flags-list"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('billing.new_invoice_layout');
    expect(wrapper.text()).toContain('2500 basis points');
  });

  it('offers no change or pause control to a view-only user', async () => {
    respondPopulatedCatalogue();
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="flags-change-billing.new_invoice_layout"]').exists()).toBe(false);
    expect(wrapper.find('[data-testid="flags-pause-billing.new_invoice_layout"]').exists()).toBe(false);
  });

  it('states that a change is proposed for approval and cannot be self-approved', async () => {
    respondPopulatedCatalogue();
    const wrapper = await mountWith([VIEW, UPDATE]);
    await wrapper.find('[data-testid="flags-change-billing.new_invoice_layout"]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('A change is proposed, not applied');
    expect(wrapper.text()).toContain('You cannot approve');
  });

  it('requires impact, rollback and a health criterion before a change can be proposed', async () => {
    respondPopulatedCatalogue();
    const wrapper = await mountWith([VIEW, UPDATE]);
    await wrapper.find('[data-testid="flags-change-billing.new_invoice_layout"]').trigger('click');
    await flushPromises();

    for (const id of ['#flags-impact', '#flags-rollback', '#flags-health']) {
      expect(wrapper.find(id).attributes('required')).toBeDefined();
    }
  });

  it('rejects an invalid configuration in the browser without calling the API', async () => {
    respondPopulatedCatalogue();
    const wrapper = await mountWith([VIEW, UPDATE]);
    await wrapper.find('[data-testid="flags-change-billing.new_invoice_layout"]').trigger('click');
    await flushPromises();

    await wrapper.find('#flags-configuration').setValue('{ not json');
    await wrapper.find('[data-testid="flags-change-submit"]').trigger('click');
    await flushPromises();

    expect(wrapper.find('[data-testid="flags-change-error"]').text()).toContain('not valid JSON');
    expect(post).not.toHaveBeenCalled();
  });

  it('sends a dotted flag key without splitting it into path segments', async () => {
    respondPopulatedCatalogue();
    post.mockResolvedValue({ data: { data: {} } });
    const wrapper = await mountWith([VIEW, UPDATE]);

    await wrapper.find('[data-testid="flags-change-billing.new_invoice_layout"]').trigger('click');
    await flushPromises();
    await wrapper.find('[data-testid="flags-change-submit"]').trigger('click');
    await flushPromises();

    expect(post.mock.calls[0][0]).toBe('/platform/feature-flags/billing.new_invoice_layout/change-requests');
  });

  it('surfaces a retryable error state', async () => {
    get.mockRejectedValue(new Error('network'));
    const wrapper = await mountWith([VIEW]);
    expect(wrapper.find('[data-testid="flags-retry"]').exists()).toBe(true);
  });
});
