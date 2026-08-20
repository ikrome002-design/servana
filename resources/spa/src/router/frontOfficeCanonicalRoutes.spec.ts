import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';

const LIVE_FRONT_OFFICE_ROUTES = {
  'front-office.dashboard': '/dashboard',
  'front-office.get-started': '/get-started',
  'front-office.clients': '/clients',
  'front-office.clients-create': '/clients/create',
  'front-office.client-detail': '/clients/:clientUlid',
  'front-office.appointments': '/appointments',
  'front-office.walk-ins': '/walk-ins',
  'front-office.queue': '/queue',
  'front-office.queue-transfer': '/queue/:queueUlid/transfer',
  'front-office.sessions': '/sessions',
  'front-office.invoices': '/invoices',
  'front-office.invoices-create': '/invoices/create',
  'front-office.invoice-payment-create': '/invoices/:invoiceUlid/payments/create',
  'front-office.payments-status': '/payments/status',
  'front-office.activity': '/activity',
  'front-office.account': '/account',
} as const;

describe('UI-13 Front Office canonical routes', () => {
  it('registers the sixteen account-tree routes plus the cross-account search destination', () => {
    const router = createAppRouter('merchant_front_office');
    for (const [name, path] of Object.entries(LIVE_FRONT_OFFICE_ROUTES)) {
      expect(router.hasRoute(name), name).toBe(true);
      const route = router.getRoutes().find((candidate) => candidate.name === name);
      expect(route?.path, name).toBe(path);
      expect(route?.meta.screenKey, name).toBeTruthy();
    }
    expect(router.hasRoute('search')).toBe(true);
    expect(router.getRoutes().find((route) => route.name === 'search')?.path).toBe('/search');
  });

  it('keeps the two unavailable destinations unregistered', () => {
    const router = createAppRouter('merchant_front_office');
    expect(router.hasRoute('front-office.subscription-payment')).toBe(false);
    expect(router.hasRoute('front-office.notifications')).toBe(false);
  });

  it('removes superseded legacy route identities and cross-account exposure', () => {
    const router = createAppRouter('merchant_front_office');
    for (const name of [
      'front-office.landing',
      'front-office.clients.create',
      'front-office.clients.detail',
      'front-office.walk-in',
      'front-office.queue.detail',
      'front-office.invoices.create',
      'front-office.payments.record',
      'front-office.receipts',
    ]) expect(router.hasRoute(name), name).toBe(false);

    expect(createAppRouter('merchant_finance').hasRoute('front-office.dashboard')).toBe(false);
  });
});
