import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: { get: (...a: unknown[]) => get(...a), post: (...a: unknown[]) => post(...a) },
}));

import {
  isTerminalFeeStatus,
  usePreferredPersonnelFeeStore,
} from '@/stores/preferredPersonnelFeeStore';

const rule = {
  id: '01FEE',
  calculation_type: 'fixed_amount',
  fixed_amount_minor: 5000,
  percentage_basis_points: null,
  currency: 'KES',
  calculation_basis: 'service_item_net_amount',
  scope: 'platform_default',
  service_id: null,
  effective_from: '2026-07-10',
  effective_to: null,
  status: 'draft',
  approved_at: null,
  change_reason: 'launch default',
};

describe('preferredPersonnelFeeStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('classifies terminal statuses (no further controls)', () => {
    expect(isTerminalFeeStatus('superseded')).toBe(true);
    expect(isTerminalFeeStatus('expired')).toBe(true);
    expect(isTerminalFeeStatus('cancelled')).toBe(true);
    expect(isTerminalFeeStatus('draft')).toBe(false);
    expect(isTerminalFeeStatus('active')).toBe(false);
  });

  it('lists rules with scope and status filters', async () => {
    get.mockResolvedValueOnce({ data: { data: [rule] } });
    const store = usePreferredPersonnelFeeStore();
    store.filterScope = 'platform_default';
    store.filterStatus = 'draft';
    await store.fetchRules();
    expect(get).toHaveBeenCalledWith('/platform/preferred-personnel-fee-rules', {
      params: { scope: 'platform_default', status: 'draft' },
    });
  });

  it('creates a rule with an idempotency key', async () => {
    post.mockResolvedValueOnce({ data: { data: rule } });
    const store = usePreferredPersonnelFeeStore();
    await store.createRule({
      calculation_type: 'fixed_amount',
      fixed_amount_minor: 5000,
      currency: 'KES',
      percentage_basis_points: null,
      calculation_basis: 'service_item_net_amount',
      scope: 'platform_default',
      service_id: null,
      effective_from: '2026-07-10',
      change_reason: 'launch default',
    });
    expect(post).toHaveBeenCalledWith(
      '/platform/preferred-personnel-fee-rules',
      expect.objectContaining({ calculation_type: 'fixed_amount' }),
      { headers: { 'Idempotency-Key': expect.any(String) } },
    );
  });

  it('supersedes via a named action with an idempotency key (never edits in place)', async () => {
    post.mockResolvedValueOnce({ data: { data: { ...rule, id: '01NEW', status: 'scheduled' } } });
    const store = usePreferredPersonnelFeeStore();
    await store.supersedeRule('01FEE', {
      calculation_type: 'percentage',
      percentage_basis_points: 500,
      calculation_basis: 'service_item_net_amount',
      effective_from: '2026-09-01',
      change_reason: 'move to percentage',
    });
    expect(post).toHaveBeenCalledWith(
      '/platform/preferred-personnel-fee-rules/01FEE/supersede',
      expect.objectContaining({ calculation_type: 'percentage', percentage_basis_points: 500 }),
      { headers: { 'Idempotency-Key': expect.any(String) } },
    );
  });

  it('approves and cancels via named actions', async () => {
    post.mockResolvedValue({ data: { data: rule } });
    const store = usePreferredPersonnelFeeStore();
    await store.approveRule('01FEE');
    await store.cancelRule('01FEE');
    expect(post).toHaveBeenCalledWith('/platform/preferred-personnel-fee-rules/01FEE/approve', {});
    expect(post).toHaveBeenCalledWith('/platform/preferred-personnel-fee-rules/01FEE/cancel', {});
  });
});
