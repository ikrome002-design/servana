import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import { useSubscriptionStore } from '@/stores/subscriptionStore';

const subscription = {
  id: '01SUB',
  status: 'active',
  billing_status: 'active',
  billing_read_only: false,
  billing_interval: 'monthly',
  current_period_end: '2026-08-01',
  plan: { id: '01PLAN', key: 'starter', name: 'Starter', tier: null },
  price: { id: '01PRICE', amount_minor: 500000, currency: 'KES', billing_interval: 'monthly' },
  scheduled_plan_change: null,
};

describe('subscriptionStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('fetches the subscription and hydrates the scheduled change', async () => {
    get.mockResolvedValueOnce({ data: { data: { ...subscription, scheduled_plan_change: { id: '01SC', status: 'scheduled' } } } });
    const store = useSubscriptionStore();
    await store.fetchSubscription();
    expect(get).toHaveBeenCalledWith('/subscription');
    expect(store.subscription?.id).toBe('01SUB');
    expect(store.scheduledChange?.id).toBe('01SC');
  });

  it('schedules a plan change with the target plan + price ULIDs', async () => {
    post.mockResolvedValueOnce({ data: { data: { id: '01SC', status: 'scheduled' } } });
    const store = useSubscriptionStore();
    const change = await store.schedulePlanChange('01PLAN', '01PRICE');
    expect(post).toHaveBeenCalledWith('/subscription/scheduled-plan-change', {
      subscription_plan_ulid: '01PLAN',
      subscription_plan_price_ulid: '01PRICE',
    });
    expect(change.status).toBe('scheduled');
    expect(store.scheduledChange?.id).toBe('01SC');
  });

  it('cancels the scheduled change and clears local state', async () => {
    post.mockResolvedValueOnce({ data: { data: { id: '01SC', status: 'cancelled' } } });
    const store = useSubscriptionStore();
    store.scheduledChange = { id: '01SC', status: 'scheduled' } as never;
    await store.cancelScheduledChange();
    expect(post).toHaveBeenCalledWith('/subscription/scheduled-plan-change/cancel');
    expect(store.scheduledChange).toBeNull();
  });

  it('surfaces a load failure without throwing', async () => {
    get.mockRejectedValueOnce(new Error('boom'));
    const store = useSubscriptionStore();
    await store.fetchSubscription();
    expect(store.error).not.toBeNull();
  });
});
