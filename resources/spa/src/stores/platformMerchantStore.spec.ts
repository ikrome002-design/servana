import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import { usePlatformMerchantStore } from '@/stores/platformMerchantStore';

const merchant = {
  id: '01MER',
  name: 'Acme Salon',
  operational_status: 'active',
  billing_status: 'suspended_billing',
  can: { suspend: true, reactivate: false, deactivate: true },
};

describe('platformMerchantStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('fetches registration monitoring rows', async () => {
    get.mockResolvedValueOnce({ data: { data: [{ id: '01MER', name: 'Acme', operational_status: 'active', billing_status: 'active', pending_setup: false }] } });
    const store = usePlatformMerchantStore();
    await store.fetchRegistrations();
    expect(get).toHaveBeenCalledWith('/platform/registration-monitor');
    expect(store.registrations).toHaveLength(1);
  });

  it('fetches merchants with an allowlisted status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [merchant] } });
    const store = usePlatformMerchantStore();
    store.filterStatus = 'suspended';
    await store.fetchMerchants();
    expect(get).toHaveBeenCalledWith('/platform/merchants', { params: { status: 'suspended' } });
    expect(store.merchants).toHaveLength(1);
  });

  it('suspends a merchant with a mandatory reason and updates local state', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...merchant, operational_status: 'suspended' } } });
    const store = usePlatformMerchantStore();
    store.merchants = [merchant] as never;
    store.selected = merchant as never;
    await store.suspend('01MER', 'Fraud investigation');
    expect(post).toHaveBeenCalledWith('/platform/merchants/01MER/suspend', { reason: 'Fraud investigation' });
    expect(store.merchants[0]).toMatchObject({ operational_status: 'suspended' });
    expect(store.selected).toMatchObject({ operational_status: 'suspended' });
  });

  it('reactivates and deactivates via their own endpoints', async () => {
    const store = usePlatformMerchantStore();
    post.mockResolvedValue({ data: { data: merchant } });
    await store.reactivate('01MER', 'cleared');
    expect(post).toHaveBeenCalledWith('/platform/merchants/01MER/reactivate', { reason: 'cleared' });
    await store.deactivate('01MER', 'closed');
    expect(post).toHaveBeenCalledWith('/platform/merchants/01MER/deactivate', { reason: 'closed' });
  });
});
