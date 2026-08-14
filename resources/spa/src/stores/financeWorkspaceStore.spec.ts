import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { apiClient } from '@/services/apiClient';
import {
  useFinanceWorkspaceStore,
  type FinanceDuplicateReview,
  type FinancePartialSplitInvoice,
  type FinanceWorkspaceOverview,
} from './financeWorkspaceStore';

const MONEY = { amount: 125_000, currency: 'KES', formatted: 'KES 1,250.00' };
const OVERVIEW: FinanceWorkspaceOverview = {
  branch_context: { label: 'Westlands Studio', branches: [{ id: 'branch-1', name: 'Westlands Studio', code: 'WST', town: 'Nairobi' }] },
  payments: { pending_validation: 2, duplicate_risk: 1, pending_recorded: [MONEY] },
  invoices: { outstanding: 3, outstanding_balance: [MONEY], validated_payments: [MONEY] },
  controls: { original_receipts: 4, active_disputes: 1, refunds_requiring_action: 1, cash_ups_requiring_review: 2, open_periods: 1, reopen_requests: 0 },
  compensation: { salary_due: [MONEY], commission_due: [MONEY], payouts_requiring_action: 1, earnings_queries_requiring_action: 1 },
  tasks: [{ key: 'payment-validations', label: 'Payment groups awaiting validation', count: 2, severity: 'high', route_name: 'finance.payments-validations', step_up_required: false, maker_checker: 'Finance checker' }],
  subscription: { available: false, reason: 'External Gate W is closed.' },
  reports: { available: false, reason: 'Phase 21N is blocked.' },
  notifications: { available: false, reason: 'Phase 21N is blocked.' },
};

const DUPLICATE: FinanceDuplicateReview = {
  id: 'check-1',
  method: 'mpesa_offline',
  result: 'duplicate_suspected',
  match_type: 'exact_normalized_reference',
  risk: 'high',
  reference_masked: '••••••1ABC',
  amount: MONEY,
  checked_at: '2026-08-14T00:00:00Z',
  current: { group_id: 'group-2', group_status: 'recorded', invoice_id: 'invoice-1', invoice_number: 'INV-001', recorded_by: 'Finance maker', recorded_at: '2026-08-14T00:00:00Z' },
  conflict: { payment_id: 'payment-1', group_id: 'group-1', group_status: 'pending_validation', invoice_id: 'invoice-1', invoice_number: 'INV-001', amount: MONEY, paid_at: '2026-08-14T00:00:00Z' },
  can_override: true,
};

const PARTIAL: FinancePartialSplitInvoice = {
  invoice: { id: 'invoice-1', number: 'INV-001', status: 'issued', created_at: '2026-08-14T00:00:00Z' },
  balance: { total: MONEY, validated: { ...MONEY, amount: 0, formatted: 'KES 0.00' }, pending_recorded: MONEY, remaining: MONEY },
  group_count: 1,
  has_multiple_groups: false,
  has_multi_method_group: true,
  groups: [{ id: 'group-1', status: 'pending_validation', total: MONEY, recorded_at: '2026-08-14T00:00:00Z', maker: 'Front Office', receipt: null, components: [{ id: 'record-1', method: 'mpesa_offline', status: 'pending_validation', amount: MONEY, reference_masked: '••••••1ABC', duplicate_risk: false }] }],
};

describe('financeWorkspaceStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('loads only the server-owned Finance workspace read model', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: OVERVIEW } } });
    const store = useFinanceWorkspaceStore();

    await store.fetchOverview();

    expect(apiClient.get).toHaveBeenCalledWith('/finance/workspace');
    expect(store.overview?.branch_context.label).toBe('Westlands Studio');
    expect(store.overview?.payments.pending_recorded[0]?.amount).toBe(125_000);
    expect(store.overview?.subscription.available).toBe(false);
  });

  it('passes validated pagination and sorting to the masked duplicate queue', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [DUPLICATE], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
    const store = useFinanceWorkspaceStore();

    await store.fetchDuplicates({ method: 'mpesa_offline', sort: '-checked_at', per_page: 20 });

    expect(apiClient.get).toHaveBeenCalledWith('/finance/duplicate-references', { params: { method: 'mpesa_offline', sort: '-checked_at', per_page: 20 } });
    expect(store.duplicates[0]?.reference_masked).toBe('••••••1ABC');
    expect(store.duplicateMeta?.total).toBe(1);
  });

  it('does not recompute the server-owned partial payment waterfall', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [PARTIAL], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } } });
    const store = useFinanceWorkspaceStore();

    await store.fetchPartialSplit({ status: 'issued', sort: '-created_at', per_page: 15 });

    expect(apiClient.get).toHaveBeenCalledWith('/finance/partial-split-payments', { params: { status: 'issued', sort: '-created_at', per_page: 15 } });
    expect(store.partialSplitInvoices[0]?.balance.pending_recorded).toEqual(MONEY);
    expect(store.partialSplitInvoices[0]?.has_multi_method_group).toBe(true);
  });

  it('deduplicates the shell overview while isolating page-specific request state', async () => {
    let resolveOverview!: (value: { data: { data: { overview: FinanceWorkspaceOverview } } }) => void;
    let resolveDuplicates!: (value: { data: { data: FinanceDuplicateReview[]; meta: { current_page: number; last_page: number; per_page: number; total: number } } }) => void;
    const overviewResponse = new Promise<{ data: { data: { overview: FinanceWorkspaceOverview } } }>((resolve) => { resolveOverview = resolve; });
    const duplicateResponse = new Promise<{ data: { data: FinanceDuplicateReview[]; meta: { current_page: number; last_page: number; per_page: number; total: number } } }>((resolve) => { resolveDuplicates = resolve; });
    const get = vi.spyOn(apiClient, 'get').mockImplementation((url: string) => (
      url === '/finance/workspace' ? overviewResponse : duplicateResponse
    ));
    const store = useFinanceWorkspaceStore();

    const shell = store.fetchOverview();
    const pageOverview = store.fetchOverview();
    const pageData = store.fetchDuplicates();

    expect(get).toHaveBeenCalledTimes(2);
    expect(store.overviewLoading).toBe(true);
    expect(store.duplicatesLoading).toBe(true);

    resolveDuplicates({ data: { data: [DUPLICATE], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
    await pageData;
    expect(store.duplicatesLoading).toBe(false);
    expect(store.overviewLoading).toBe(true);

    resolveOverview({ data: { data: { overview: OVERVIEW } } });
    await Promise.all([shell, pageOverview]);
    expect(store.overviewLoading).toBe(false);
    expect(store.duplicates[0]?.id).toBe('check-1');
  });
});
