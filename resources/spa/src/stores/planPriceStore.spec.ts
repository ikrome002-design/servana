import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import { BILLING_INTERVALS, usePlanPriceStore } from '@/stores/planPriceStore';

const price = {
  id: '01PRICE',
  amount_minor: 250000,
  currency: 'KES',
  billing_interval: 'monthly',
  effective_from: '2026-07-10',
  effective_to: null,
  lifecycle: 'current' as const,
};

describe('planPriceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('exposes the five canonical billing intervals in order', () => {
    expect(BILLING_INTERVALS.map((i) => i.value)).toEqual([
      'weekly',
      'bi_weekly',
      'monthly',
      'quarterly',
      'annual',
    ]);
  });

  it('fetches prices for a plan', async () => {
    get.mockResolvedValueOnce({ data: { data: [price] } });
    const store = usePlanPriceStore();
    await store.fetchPrices('01PLAN');
    expect(get).toHaveBeenCalledWith('/platform/plans/01PLAN/prices');
    expect(store.prices).toHaveLength(1);
  });

  it('creates a price with an idempotency key (integer minor units)', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...price, id: '01NEW' } } });
    const store = usePlanPriceStore();
    await store.createPrice('01PLAN', {
      amount_minor: 250000,
      currency: 'KES',
      billing_interval: 'monthly',
      effective_from: '2026-08-01',
    });
    expect(post).toHaveBeenCalledWith(
      '/platform/plans/01PLAN/prices',
      expect.objectContaining({ amount_minor: 250000, billing_interval: 'monthly' }),
      { headers: { 'Idempotency-Key': expect.any(String) } },
    );
    expect(store.prices.some((p) => p.id === '01NEW')).toBe(true);
  });

  it('cancels only a future price and drops it locally', async () => {
    const store = usePlanPriceStore();
    store.prices = [price, { ...price, id: '01FUT', lifecycle: 'future' }];
    post.mockResolvedValueOnce({ data: { data: { ...price, id: '01FUT', lifecycle: 'historical' } } });
    await store.cancelFuturePrice('01FUT');
    expect(post).toHaveBeenCalledWith('/platform/plan-prices/01FUT/cancel', {});
    expect(store.prices.find((p) => p.id === '01FUT')).toBeUndefined();
  });
});
