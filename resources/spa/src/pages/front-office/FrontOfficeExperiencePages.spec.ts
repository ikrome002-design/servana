import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import DailyActivity from './DailyActivity.vue';
import FrontOfficeDashboard from './FrontOfficeDashboard.vue';
import PaymentReceiptStatus from './PaymentReceiptStatus.vue';
import { apiClient } from '@/services/apiClient';
import type {
  FrontOfficeActivity,
  FrontOfficePaymentStatus,
  FrontOfficeWorkspaceOverview,
} from '@/stores/frontOfficeWorkspaceStore';

const OVERVIEW: FrontOfficeWorkspaceOverview = {
  observed_at: '2026-08-20T06:00:00Z',
  business_date: '2026-08-20',
  branch: { id: 'branch-1', name: 'Westlands Studio', code: 'WST', town: 'Nairobi' },
  appointments: { today: 8, arrivals: 3, by_status: { checked_in: 3 } },
  queue: { active: 4, waiting: 3, in_service: 1, by_status: { waiting: 3, in_service: 1 }, longest_estimated_wait_minutes: 24 },
  sessions: { today: 5, in_progress: 1, completed: 4, by_status: { in_progress: 1, completed: 4 } },
  invoices: { drafts: 2, awaiting_payment: 3, by_status: { draft: 2, issued: 3 } },
  payments: { pending_validation: 2, by_status: { pending_validation: 2 }, receipts_ready_today: 1 },
  tasks: [{ key: 'waiting', label: 'Clients waiting in queue', count: 3, route_name: 'front-office.queue' }],
  get_started: { client_created: true },
  subscription: { available: false, reason: 'External Gate W is closed. No Wallet recovery runtime exists.' },
  notifications: { available: false, reason: 'Phase 21N has no notification runtime.' },
};
const ACTIVITY: FrontOfficeActivity = {
  id: 'event-1',
  domain: 'queue',
  action: 'queue_entry.started',
  label: 'Service started from queue',
  occurred_at: '2026-08-20T06:10:00Z',
};
const PAYMENT: FrontOfficePaymentStatus = {
  id: 'group-1',
  status: 'pending_validation',
  total: { amount: 125_000, currency: 'KES', formatted: 'KES 1,250.00' },
  recorded_at: '2026-08-20T06:00:00Z',
  submitted_for_validation_at: '2026-08-20T06:00:00Z',
  invoice: { id: 'invoice-1', number: 'INV-001', status: 'issued' },
  receipt: { ready: false, id: null, number: null },
};

async function routerAt(path: string) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/dashboard', name: 'front-office.dashboard', component: { template: '<div />' } },
      { path: '/activity', name: 'front-office.activity', component: { template: '<div />' } },
      { path: '/status', name: 'front-office.payments-status', component: { template: '<div />' } },
      { path: '/walk-ins', name: 'front-office.walk-ins', component: { template: '<div />' } },
      { path: '/appointments', name: 'front-office.appointments', component: { template: '<div />' } },
      { path: '/queue', name: 'front-office.queue', component: { template: '<div />' } },
      { path: '/invoices', name: 'front-office.invoices', component: { template: '<div />' } },
    ],
  });
  await router.push(path);
  await router.isReady();
  return router;
}

describe('UI-13 Front Office experience pages', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.restoreAllMocks();
  });

  it('renders the server-owned service flow and names the closed recovery gate', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: { overview: OVERVIEW } } });
    const wrapper = mount(FrontOfficeDashboard, { global: { plugins: [await routerAt('/dashboard')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Westlands Studio');
    expect(wrapper.text()).toContain('Welcome → queue → service → billing');
    expect(wrapper.text()).toContain('External Gate W is closed');
    expect(wrapper.text()).not.toContain('Validate payment');
    expect(wrapper.text()).not.toContain('Issue receipt');
  });

  it('renders only the narrow operational activity fields', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [ACTIVITY], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
    const wrapper = mount(DailyActivity, { global: { plugins: [await routerAt('/activity')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('Service started from queue');
    expect(wrapper.text()).toContain('Queue');
    expect(wrapper.text()).toContain('not the raw Audit account');
    expect(wrapper.text()).not.toContain('correlation');
  });

  it('keeps recorded, Finance decision and automatic receipt truth distinct', async () => {
    vi.spyOn(apiClient, 'get').mockResolvedValue({ data: { data: [PAYMENT], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } } });
    const wrapper = mount(PaymentReceiptStatus, { global: { plugins: [await routerAt('/status')] } });
    await flushPromises();

    expect(wrapper.text()).toContain('INV-001');
    expect(wrapper.text()).toContain('Awaiting Finance');
    expect(wrapper.text()).toContain('Not available yet');
    expect(wrapper.text()).toContain('No manual issue control exists');
    const controls = wrapper.findAll('button, a').map((node) => node.text());
    expect(controls).not.toContain('Validate');
    expect(controls).not.toContain('Override duplicate');
  });
});
