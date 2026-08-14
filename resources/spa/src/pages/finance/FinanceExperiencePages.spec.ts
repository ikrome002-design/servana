import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DuplicateReferenceReview from './DuplicateReferenceReview.vue';
import FinanceDashboard from './FinanceDashboard.vue';
import PartialSplitPayments from './PartialSplitPayments.vue';
import { apiClient } from '@/services/apiClient';
import type {
  FinanceDuplicateReview,
  FinancePartialSplitInvoice,
  FinanceWorkspaceOverview,
} from '@/stores/financeWorkspaceStore';

const MONEY = { amount: 125_000, currency: 'KES', formatted: 'KES 1,250.00' };
const OVERVIEW: FinanceWorkspaceOverview = {
  branch_context: { label: 'Westlands Studio', branches: [{ id: 'branch-1', name: 'Westlands Studio', code: 'WST', town: 'Nairobi' }] },
  payments: { pending_validation: 2, duplicate_risk: 1, pending_recorded: [MONEY] },
  invoices: { outstanding: 3, outstanding_balance: [MONEY], validated_payments: [MONEY] },
  controls: { original_receipts: 4, active_disputes: 1, refunds_requiring_action: 1, cash_ups_requiring_review: 2, open_periods: 1, reopen_requests: 0 },
  compensation: { salary_due: [MONEY], commission_due: [MONEY], payouts_requiring_action: 1, earnings_queries_requiring_action: 1 },
  tasks: [{ key: 'payment-validations', label: 'Payment groups awaiting validation', count: 2, severity: 'high', route_name: 'finance.payments-validations', step_up_required: false, maker_checker: 'Finance checker' }],
  subscription: { available: false, reason: 'External Gate W is closed. No Wallet runtime exists.' },
  reports: { available: false, reason: 'Phase 21N is blocked.' },
  notifications: { available: false, reason: 'Phase 21N is blocked.' },
};

const DUPLICATE: FinanceDuplicateReview = {
  id: 'check-1', method: 'mpesa_offline', result: 'duplicate_suspected', match_type: 'exact_normalized_reference', risk: 'high', reference_masked: '••••••1ABC', amount: MONEY, checked_at: '2026-08-14T00:00:00Z',
  current: { group_id: 'group-2', group_status: 'recorded', invoice_id: 'invoice-1', invoice_number: 'INV-001', recorded_by: 'Front Office', recorded_at: '2026-08-14T00:00:00Z' },
  conflict: { payment_id: 'payment-1', group_id: 'group-1', group_status: 'pending_validation', invoice_id: 'invoice-1', invoice_number: 'INV-001', amount: MONEY, paid_at: '2026-08-14T00:00:00Z' },
  can_override: true,
};

const PARTIAL: FinancePartialSplitInvoice = {
  invoice: { id: 'invoice-1', number: 'INV-001', status: 'issued', created_at: '2026-08-14T00:00:00Z' },
  balance: { total: { ...MONEY, amount: 500_000, formatted: 'KES 5,000.00' }, validated: { ...MONEY, amount: 0, formatted: 'KES 0.00' }, pending_recorded: MONEY, remaining: { ...MONEY, amount: 500_000, formatted: 'KES 5,000.00' } },
  group_count: 1, has_multiple_groups: false, has_multi_method_group: true,
  groups: [{ id: 'group-1', status: 'pending_validation', total: MONEY, recorded_at: '2026-08-14T00:00:00Z', maker: 'Front Office', receipt: null, components: [{ id: 'record-1', method: 'mpesa_offline', status: 'pending_validation', amount: MONEY, reference_masked: '••••••1ABC', duplicate_risk: false }] }],
};

async function routerAt(path: string) {
  const names = [
    'finance.payments-validations',
    'finance.payments-validation-detail',
    'finance.payments',
    'finance.invoices',
    'finance.receipts',
    'finance.compensation-liabilities',
    'finance.tasks',
  ];
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', name: 'finance.dashboard', component: { template: '<div />' } },
      { path: '/duplicates', name: 'finance.payments-duplicates', component: { template: '<div />' } },
      { path: '/partial', name: 'finance.payments-partial-split', component: { template: '<div />' } },
      ...names.map((name, index) => ({
        path: name === 'finance.payments-validation-detail' ? '/group/:groupUlid' : `/target-${index}`,
        name,
        component: { template: '<div />' },
      })),
    ],
  });
  await router.push(path);
  await router.isReady();
  return router;
}

describe('UI-12 Finance experience pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('renders server-owned financial posture and names the closed Wallet gate', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: OVERVIEW } } });
    const wrapper = mount(FinanceDashboard, { global: { plugins: [await routerAt('/dashboard')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Westlands Studio');
    expect(wrapper.text()).toContain('KES 1,250.00');
    expect(wrapper.text()).toContain('Recorded money remains pending');
    expect(wrapper.text()).toContain('External Gate W is closed');
    expect(wrapper.text()).not.toContain('Provider balance');
    expect(wrapper.text()).not.toContain('Approve your own');
  });

  it('renders masked duplicate comparison and reuses the step-up protected override', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [DUPLICATE], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
    const wrapper = mount(DuplicateReferenceReview, { global: { plugins: [await routerAt('/duplicates')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Exact normalized-reference match');
    expect(wrapper.text()).toContain('••••••1ABC');
    expect(wrapper.text()).toContain('Review override');
    expect(wrapper.text()).not.toContain('QGX7YT1ABC');
  });

  it('renders the server-owned balance waterfall without inventing receipt state', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [PARTIAL], meta: { current_page: 1, last_page: 1, per_page: 15, total: 1 } } });
    const wrapper = mount(PartialSplitPayments, { global: { plugins: [await routerAt('/partial')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Balance waterfall');
    expect(wrapper.text()).toContain('KES 5,000.00');
    expect(wrapper.text()).toContain('Recorded, pending');
    expect(wrapper.text()).toContain('No receipt before validation');
    expect(wrapper.text()).not.toContain('Issue receipt');
  });
});
