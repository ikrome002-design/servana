import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { useMerchantStore } from '@/stores/merchantStore';
import type { Merchant } from '@/types/models';

const activeMerchant: Merchant = {
  id: '01J000000000000000000MERCH',
  name: 'Glow Salon',
  slug: 'glow-salon',
  status: 'active',
  service_fee_tier: 'split_tier',
  setup_completed_at: '2026-06-14T00:00:00+00:00',
};

describe('merchantStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('starts empty', () => {
    const store = useMerchantStore();
    expect(store.merchant).toBeNull();
    expect(store.isActive()).toBe(false);
    expect(store.name).toBeNull();
  });

  it('bootstraps an active merchant', () => {
    const store = useMerchantStore();
    store.setMerchant(activeMerchant);

    expect(store.merchant?.id).toBe('01J000000000000000000MERCH');
    expect(store.name).toBe('Glow Salon');
    expect(store.isActive()).toBe(true);
    expect(store.isPendingSetup()).toBe(false);
  });

  it('recognises a pending_setup merchant', () => {
    const store = useMerchantStore();
    store.setMerchant({ ...activeMerchant, status: 'pending_setup', setup_completed_at: null });

    expect(store.isPendingSetup()).toBe(true);
    expect(store.isActive()).toBe(false);
  });

  it('clears on reset', () => {
    const store = useMerchantStore();
    store.setMerchant(activeMerchant);
    store.$reset();
    expect(store.merchant).toBeNull();
  });
});
