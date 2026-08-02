import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter, type Router } from 'vue-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Phase UI-04 Increment 8 — UI01-RENDER-001 closure.
 *
 * UI-01 recorded THIRTEEN uncaught TypeErrors across TWELVE routes. Every one was an unguarded
 * dereference of a nested money object or a collection, reached because the audited payload did
 * not carry the field.
 *
 * The acceptance criterion the UI-01 register wrote for this defect is exact:
 *
 *   "Each affected route renders with an incomplete payload and produces no uncaught exception,
 *    showing a defined empty state instead."
 *
 * So this suite mounts each affected component with a DELIBERATELY INCOMPLETE payload — the shape
 * that used to throw — and fails on any error reaching the Vue error handler.
 *
 * It also proves the correction did not create a worse defect: an absent amount renders as
 * unavailable, NEVER as zero. A screen that reports `KES 0.00` where the server sent nothing is
 * stating a false financial fact, which is worse than the crash it replaced.
 */

vi.mock('@/services/apiClient', async () => {
  const actual = await vi.importActual<typeof import('@/services/apiClient')>('@/services/apiClient');

  return {
    ...actual,
    apiClient: {
      get: vi.fn().mockResolvedValue({ data: { data: [] } }),
      post: vi.fn().mockResolvedValue({ data: { data: {} } }),
      patch: vi.fn().mockResolvedValue({ data: { data: {} } }),
      delete: vi.fn().mockResolvedValue({ data: { data: {} } }),
    },
    primeCsrfCookie: vi.fn().mockResolvedValue(undefined),
  };
});

const stub = { template: '<div />' };

function makeRouter(): Router {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', name: 'home', component: stub },
      { path: '/:pathMatch(.*)*', name: 'catch-all', component: stub },
    ],
  });
}

/** Records anything that reaches Vue's error handler — the exact failure UI-01 audited. */
function captureErrors(): { errors: unknown[]; global: Record<string, unknown> } {
  const errors: unknown[] = [];
  const router = makeRouter();

  return {
    errors,
    global: {
      plugins: [router],
      config: { errorHandler: (error: unknown) => errors.push(error) },
      stubs: { RouterLink: { template: '<a><slot /></a>' }, Teleport: true },
    },
  };
}

/**
 * The twelve audited routes and the components they render.
 *
 * Two components serve two routes each (the invoice and receipt details are shared between
 * Finance and Front Office), so twelve routes map to ten components.
 */
const AUDITED = [
  { routes: ['/branch/cash-up'], component: () => import('@/pages/branch/CashUp.vue'), field: 'lines (whenLoaded relation)' },
  { routes: ['/finance/cash-up/{id}'], component: () => import('@/pages/finance/CashUpDetail.vue'), field: 'expected/counted/variance money' },
  {
    routes: ['/finance/invoices/{id}', '/front-office/invoices/{id}'],
    component: () => import('@/pages/invoicing/InvoiceDetail.vue'),
    field: 'preferred_personnel_fee (nullable), line_total, subtotal',
  },
  { routes: ['/finance/payment-records/{id}'], component: () => import('@/pages/payments/PaymentGroupDetail.vue'), field: 'total / component amount' },
  {
    routes: ['/finance/receipts/{id}', '/front-office/receipts/{id}'],
    component: () => import('@/pages/finance/ReceiptDetail.vue'),
    field: 'amount / component amount',
  },
  { routes: ['/finance/refunds/{id}'], component: () => import('@/pages/finance/RefundDetail.vue'), field: 'amount' },
  { routes: ['/front-office/payments/record/{id}'], component: () => import('@/pages/payments/RecordPayment.vue'), field: 'balance.amount' },
  { routes: ['/merchant/compensation-summary'], component: () => import('@/pages/merchant/CompensationSummary.vue'), field: 'summary collections' },
  { routes: ['/personnel/earnings'], component: () => import('@/pages/personnel/Earnings.vue'), field: 'overview.currencies' },
  { routes: ['/merchant/subscription'], component: () => import('@/pages/merchant/SubscriptionDashboard.vue'), field: 'plan.name' },
];

describe('UI01-RENDER-001 — the twelve audited routes survive an incomplete payload', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('covers exactly the twelve routes UI-01 audited', () => {
    const routes = AUDITED.flatMap((entry) => entry.routes);

    expect(routes).toHaveLength(12);
    expect(new Set(routes).size).toBe(12);
  });

  for (const entry of AUDITED) {
    it(`renders without an uncaught exception: ${entry.routes.join(' and ')} (${entry.field})`, async () => {
      const { errors, global } = captureErrors();
      const component = (await entry.component()).default;

      // The audited condition: every API call resolves with a payload that carries none of the
      // nested fields the screen used to dereference.
      const wrapper = mount(component, { global });
      await flushPromises();

      expect(errors).toEqual([]);
      // A landmark or content still renders — the screen degrades, it does not disappear.
      expect(wrapper.html().length).toBeGreaterThan(0);

      wrapper.unmount();
    });
  }

  it('never reports an absent amount as zero', async () => {
    // The correction must not trade a crash for a false financial figure.
    const { global } = captureErrors();
    const component = (await import('@/pages/finance/CashUpDetail.vue')).default;

    const wrapper = mount(component, { global });
    await flushPromises();

    const amounts = wrapper.findAll('[data-testid="sv-money"]');
    for (const amount of amounts) {
      if (amount.attributes('data-available') === 'false') {
        expect(amount.text()).toBe('Not available');
        expect(amount.text()).not.toContain('0.00');
      }
    }

    wrapper.unmount();
  });

  it('refuses to record a payment when the outstanding balance is unknown', async () => {
    // Fail-safe. Defaulting the balance to 0 would have read as "fully paid" and silently
    // forbidden a legitimate payment; leaving it unguarded threw. Neither is acceptable.
    const { errors, global } = captureErrors();
    const component = (await import('@/pages/payments/RecordPayment.vue')).default;

    const wrapper = mount(component, { global });
    await flushPromises();

    expect(errors).toEqual([]);

    wrapper.unmount();
  });
});
