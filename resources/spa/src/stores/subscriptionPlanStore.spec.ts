import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
const put = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
    put: (...a: unknown[]) => put(...a),
  },
}));

import { useSubscriptionPlanStore } from '@/stores/subscriptionPlanStore';

const plan = {
  id: '01PLAN',
  key: 'starter',
  name: 'Starter',
  description: null,
  tier: null,
  metadata: {},
  status: 'active',
  sort_order: 0,
};

describe('subscriptionPlanStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
    put.mockReset();
  });

  it('lists plans and applies the status filter', async () => {
    get.mockResolvedValueOnce({ data: { data: [plan] } });
    const store = useSubscriptionPlanStore();
    store.filterStatus = 'active';
    await store.fetchPlans();
    expect(get).toHaveBeenCalledWith('/platform/plans', { params: { status: 'active' } });
    expect(store.plans).toHaveLength(1);
  });

  it('creates a plan (no price fields in the payload)', async () => {
    post.mockResolvedValueOnce({ data: { data: plan } });
    const store = useSubscriptionPlanStore();
    await store.createPlan({ key: 'starter', name: 'Starter', sort_order: 0 });
    const [, payload] = post.mock.calls[0];
    expect(payload).not.toHaveProperty('amount_minor');
    expect(payload).not.toHaveProperty('price');
    expect(post).toHaveBeenCalledWith('/platform/plans', expect.objectContaining({ key: 'starter' }));
  });

  it('retires a plan via the named action, not a status patch', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...plan, status: 'retired' } } });
    const store = useSubscriptionPlanStore();
    const result = await store.retirePlan('01PLAN');
    expect(post).toHaveBeenCalledWith('/platform/plans/01PLAN/retire', {});
    expect(result.status).toBe('retired');
  });

  it('replaces the entitlement set with normalized rows (no merchant binding)', async () => {
    put.mockResolvedValueOnce({
      data: { data: [{ entitlement_key: 'branches.max', enabled: true, limit_int: 3 }] },
    });
    const store = useSubscriptionPlanStore();
    await store.updateEntitlements('01PLAN', [
      { entitlement_key: 'branches.max', enabled: true, limit_int: 3 },
    ]);
    expect(put).toHaveBeenCalledWith('/platform/plans/01PLAN/entitlements', {
      entitlements: [{ entitlement_key: 'branches.max', enabled: true, limit_int: 3 }],
    });
    expect(store.entitlements[0].limit_int).toBe(3);
  });
});
