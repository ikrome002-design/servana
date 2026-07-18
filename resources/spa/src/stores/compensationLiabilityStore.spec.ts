import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AxiosError } from 'axios';

const get = vi.fn();
const post = vi.fn();
vi.mock('@/services/apiClient', () => ({
  apiClient: {
    get: (...a: unknown[]) => get(...a),
    post: (...a: unknown[]) => post(...a),
  },
}));

import { useCompensationLiabilityStore } from '@/stores/compensationLiabilityStore';

const summaryRow = {
  currency: 'KES',
  gross_salary_accrual_minor: 500000,
  salary_reversal_minor: 0,
  net_salary_liability_minor: 500000,
  gross_earned_commission_minor: 120000,
  commission_reversal_minor: -20000,
  net_commission_liability_minor: 100000,
  compensation_adjustment_minor: -5000,
  combined_net_liability_minor: 595000,
};
const usdRow = { ...summaryRow, currency: 'USD', combined_net_liability_minor: 42000 };

const entry = {
  id: '01ENTRY0000000000000000000',
  liability_type: 'commission',
  entry_type: 'earned',
  status: 'earned',
  amount_minor: 12500,
  currency: 'KES',
  business_date: '2026-07-05',
  staff_profile_id: '01STAFF000000000000000000',
  staff_display_name: 'A. Stylist',
  branch_id: '01BRANCH00000000000000000',
  compensation_plan_id: null,
  commission_rule_id: '01RULE0000000000000000000',
  pay_period_start: null,
  pay_period_end: null,
  invoice_reference: 'INV-000123',
  source_entry_id: null,
  created_at: '2026-07-05T10:00:00+00:00',
};

const adjustment = {
  id: '01ADJ00000000000000000000',
  adjustment_type: 'manual',
  amount_minor: -5000,
  currency: 'KES',
  reason: 'Agreed goodwill correction',
  staff_profile_id: '01STAFF000000000000000000',
  staff_display_name: 'A. Stylist',
  branch_id: '01BRANCH00000000000000000',
  created_at: '2026-07-06T10:00:00+00:00',
};

function paged<T>(rows: T[], page = 1, lastPage = 1) {
  return { data: { data: rows, meta: { current_page: page, last_page: lastPage, per_page: 25, total: rows.length } } };
}

function forbidden(): AxiosError {
  const err = new AxiosError('Forbidden');
  err.response = { status: 403, data: {}, statusText: 'Forbidden', headers: {}, config: {} as never };
  return err;
}

function apiError(code: string, fields: Record<string, string[]> = {}): AxiosError {
  const err = new AxiosError('failed');
  err.response = { status: 422, data: { error: { code, message: 'x', fields } }, statusText: '', headers: {}, config: {} as never };
  err.apiError = { code, message: 'x', fields, meta: {} };
  return err;
}

describe('compensationLiabilityStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    get.mockReset();
    post.mockReset();
  });

  it('loads the server per-currency summary verbatim, keeping currencies separate', async () => {
    get.mockResolvedValueOnce({ data: { data: [summaryRow, usdRow] } });
    const store = useCompensationLiabilityStore();
    await store.fetchSummary();
    expect(get).toHaveBeenCalledWith('/compensation/liabilities/summary', { params: {} });
    expect(store.summary).toHaveLength(2);
    // The store never recomputes a combined total across currencies — it renders what the server returned.
    expect(store.summary[0].combined_net_liability_minor).toBe(595000);
    expect(store.summary[1].currency).toBe('USD');
  });

  it('loads liability entries with pagination metadata', async () => {
    get.mockResolvedValueOnce(paged([entry], 1, 3));
    const store = useCompensationLiabilityStore();
    await store.fetchEntries();
    expect(get).toHaveBeenCalledWith('/compensation/liabilities', { params: { page: 1 } });
    expect(store.entries[0].id).toBe(entry.id);
    expect(store.entriesMeta.last_page).toBe(3);
  });

  it('loads adjustments', async () => {
    get.mockResolvedValueOnce(paged([adjustment]));
    const store = useCompensationLiabilityStore();
    await store.fetchAdjustments();
    expect(get).toHaveBeenCalledWith('/compensation/adjustments', { params: { page: 1 } });
    expect(store.adjustments[0].id).toBe(adjustment.id);
  });

  it('loads an adjustment detail', async () => {
    get.mockResolvedValueOnce({ data: { data: adjustment } });
    const store = useCompensationLiabilityStore();
    const fetched = await store.fetchAdjustment(adjustment.id);
    expect(get).toHaveBeenCalledWith(`/compensation/adjustments/${adjustment.id}`);
    expect(fetched.reason).toBe('Agreed goodwill correction');
  });

  it('only sends non-empty, contract-declared filters and resets to page 1 on apply', async () => {
    const store = useCompensationLiabilityStore();
    store.filters.liability_type = 'commission';
    store.filters.currency = 'KES';
    store.filters.status = 'earned';
    store.entriesMeta.current_page = 4;
    get.mockResolvedValue(paged([entry]));
    await store.applyFilters();
    // page reset to 1
    expect(store.entriesMeta.current_page).toBe(1);
    const entriesCall = get.mock.calls.find((c) => c[0] === '/compensation/liabilities');
    expect(entriesCall?.[1].params).toMatchObject({ liability_type: 'commission', currency: 'KES', status: 'earned', page: 1 });
    // No blank/unknown fields sent.
    expect(entriesCall?.[1].params.staff_profile_ulid).toBeUndefined();
    expect(entriesCall?.[1].params.branch_id).toBeUndefined();
  });

  it('creates a positive adjustment, sends integer minor units and NO prohibited fields', async () => {
    post.mockResolvedValueOnce({ data: { data: adjustment } });
    const store = useCompensationLiabilityStore();
    await store.createAdjustment({ staff_profile_ulid: '01STAFF000000000000000000', amount_minor: 15000, currency: 'KES', reason: 'Bonus true-up' });
    const [url, body, config] = post.mock.calls[0];
    expect(url).toBe('/compensation/adjustments');
    expect(body).toEqual({ staff_profile_ulid: '01STAFF000000000000000000', amount_minor: 15000, currency: 'KES', reason: 'Bonus true-up' });
    // The signed integer is sent as-is; never a float, never a server-owned field.
    expect(Number.isInteger(body.amount_minor)).toBe(true);
    for (const forbiddenField of ['merchant_id', 'branch_id', 'status', 'adjustment_type', 'created_by', 'source_ledger_ulid', 'payout_item_id']) {
      expect(body).not.toHaveProperty(forbiddenField);
    }
    // Every financial mutation carries an Idempotency-Key header.
    expect(typeof config.headers['Idempotency-Key']).toBe('string');
  });

  it('creates a negative adjustment (reduces liability)', async () => {
    post.mockResolvedValueOnce({ data: { data: adjustment } });
    const store = useCompensationLiabilityStore();
    await store.createAdjustment({ staff_profile_ulid: '01STAFF000000000000000000', amount_minor: -5000, currency: 'KES', reason: 'Goodwill' });
    expect(post.mock.calls[0][1].amount_minor).toBe(-5000);
  });

  it('reuses the Idempotency-Key on a same-payload retry and mints a new one when the payload changes', async () => {
    const store = useCompensationLiabilityStore();
    const payload = { staff_profile_ulid: '01STAFF000000000000000000', amount_minor: 15000, currency: 'KES', reason: 'Bonus' };
    // First attempt fails at the network → key retained for retry.
    post.mockRejectedValueOnce(new AxiosError('network'));
    await expect(store.createAdjustment(payload)).rejects.toBeTruthy();
    const firstKey = post.mock.calls[0][2].headers['Idempotency-Key'];
    // Retry same payload → same key.
    post.mockResolvedValueOnce({ data: { data: adjustment } });
    await store.createAdjustment(payload);
    expect(post.mock.calls[1][2].headers['Idempotency-Key']).toBe(firstKey);
    // A materially changed payload → new key.
    post.mockResolvedValueOnce({ data: { data: adjustment } });
    await store.createAdjustment({ ...payload, amount_minor: 20000 });
    expect(post.mock.calls[2][2].headers['Idempotency-Key']).not.toBe(firstKey);
  });

  it('does not append a phantom row when the create is rejected (step-up / period lock / validation)', async () => {
    const store = useCompensationLiabilityStore();
    for (const code of ['step_up_required', 'financial_period_locked', 'validation_failed']) {
      post.mockRejectedValueOnce(apiError(code, code === 'validation_failed' ? { amount_minor: ['bad'] } : {}));
      await expect(
        store.createAdjustment({ staff_profile_ulid: '01STAFF000000000000000000', amount_minor: 1, currency: 'KES', reason: `probe-${code}` }),
      ).rejects.toBeTruthy();
    }
    expect(store.adjustments).toHaveLength(0);
    expect(store.creating).toBe(false);
  });

  it('marks a forbidden read without leaking internals', async () => {
    get.mockRejectedValueOnce(forbidden());
    const store = useCompensationLiabilityStore();
    await store.fetchSummary();
    expect(store.forbidden).toBe(true);
    expect(store.summaryError).toBeNull();
  });

  it('surfaces a friendly error and never leaks a constraint name', async () => {
    get.mockRejectedValueOnce(new Error('duplicate key value violates unique constraint "commission_ledger_pk"'));
    const store = useCompensationLiabilityStore();
    await store.fetchEntries();
    expect(store.entriesError).toBe('Unable to load liability entries.');
    expect(store.entriesError).not.toContain('constraint');
  });

  it('clears stale context data on reset', async () => {
    get.mockResolvedValue(paged([entry]));
    const store = useCompensationLiabilityStore();
    await store.fetchEntries();
    store.filters.currency = 'KES';
    store.$reset();
    expect(store.entries).toHaveLength(0);
    expect(store.summary).toHaveLength(0);
    expect(store.adjustments).toHaveLength(0);
    expect(store.filters.currency).toBe('');
    expect(store.forbidden).toBe(false);
  });
});
