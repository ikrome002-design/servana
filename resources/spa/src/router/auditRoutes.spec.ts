import { describe, expect, it } from 'vitest';
import { createAppRouter } from '@/router';
import { NAVIGATION_CONTRACT, contractForAccount } from '@/navigation/navigationRegistry.generated';

/*
 | Phase UI-15 — the Audit account's canonical host-relative route tree (audit.servana.ke;
 | Appendix A §12). Routes are UX only; the API enforces permission, tenant and branch scope.
 */
const router = createAppRouter('merchant_audit');
const EVENT = '01JB0000000000000000E00001';
const FLAG = '01JB0000000000000000F00002';
const EXPORT = '01JB0000000000000000X00003';

const canonical = new Map([
  ['audit.dashboard', '/dashboard'],
  ['audit.get-started', '/get-started'],
  ['audit.audit', '/audit'],
  ['audit.audit-flags', '/audit/flags'],
  ['audit.audit-operations', '/audit/operations'],
  ['audit.audit-finance', '/audit/finance'],
  ['audit.audit-compensation', '/audit/compensation'],
  ['audit.audit-access', '/audit/access'],
  ['audit.audit-privacy-preferred', '/audit/privacy-preferred'],
  ['audit.audit-integrity', '/audit/integrity'],
  ['audit.exports', '/exports'],
  ['audit.exports-history', '/exports/history'],
  ['audit.account', '/account'],
]);

describe('Phase UI-15 Audit route contract', () => {
  it('resolves every primary canonical destination to its contract route name', () => {
    for (const [name, path] of canonical) {
      expect(router.resolve(path).name, path).toBe(name);
    }
  });

  it('resolves parameterised evidence only for a ULID, so words are never read as ids', () => {
    expect(router.resolve(`/audit/${EVENT}`).name).toBe('audit.audit-event-detail');
    expect(router.resolve(`/audit/${EVENT}`).params.eventUlid).toBe(EVENT);
    expect(router.resolve(`/audit/flags/${FLAG}`).name).toBe('audit.flag-detail');
    expect(router.resolve(`/exports/${EXPORT}`).name).toBe('audit.export-detail');
    expect(router.resolve('/audit/flags').name).toBe('audit.audit-flags');
    expect(router.resolve('/exports/history').name).toBe('audit.exports-history');
    expect(router.resolve('/audit/not-an-event').name).toBe('not-found');
  });

  it('accounts for all fifteen contract pages: fourteen live, Notifications gated with no route', () => {
    const pages = contractForAccount('merchant_audit');
    expect(pages).toHaveLength(15);

    const counts = pages.reduce<Record<string, number>>((totals, page) => {
      totals[page.implementationStatus] = (totals[page.implementationStatus] ?? 0) + 1;
      return totals;
    }, {});
    expect(counts).toEqual({ implemented: 14, disabled_by_gate: 1 });

    const names = new Set(router.getRoutes().map((route) => String(route.name)));
    for (const page of pages.filter((p) => p.implementationStatus === 'implemented')) {
      expect(names.has(String(page.runtimeRouteName)), page.key).toBe(true);
    }

    const notifications = NAVIGATION_CONTRACT.find((entry) => entry.key === 'merchant_audit.notifications');
    expect(notifications?.gate).toBe('phase_21n_blocked_by_external_gate_w');
    expect(notifications?.runtimeRouteName).toBeNull();
    expect(names.has('audit.notifications')).toBe(false);
    expect(router.resolve('/notifications').name).toBe('not-found');
  });

  it('retires the non-contract Audit landing in favour of the dashboard', () => {
    const names = router.getRoutes().map((route) => String(route.name));
    expect(names).not.toContain('audit.landing');
    expect(names).not.toContain('audit.branch-events');
  });

  it('keeps every Phase 19 path as a same-host redirect that preserves filters and ids', async () => {
    const legacy: Array<[string, string, Record<string, string>?]> = [
      ['/audit/events?severity=high', 'audit.audit'],
      [`/audit/events/${EVENT}`, 'audit.audit-event-detail', { eventUlid: EVENT }],
      ['/audit/flagged', 'audit.audit-flags'],
      [`/audit/flagged/${FLAG}`, 'audit.flag-detail', { flagUlid: FLAG }],
      ['/audit/exports', 'audit.exports'],
      [`/audit/exports/${EXPORT}`, 'audit.export-detail', { exportUlid: EXPORT }],
      ['/audit/get-started', 'audit.get-started'],
      ['/audit/dashboard', 'audit.dashboard'],
    ];

    for (const [path, name, params] of legacy) {
      const record = router.resolve(path).matched.at(-1);
      expect(record?.redirect, path).toBeTypeOf('function');
      const target = (record?.redirect as (to: ReturnType<typeof router.resolve>) => { name: string; query: unknown; params?: Record<string, string> })(router.resolve(path));
      expect(target.name, path).toBe(name);
      if (params) expect(target.params).toEqual(params);
      if (path.includes('?')) expect(target.query).toEqual({ severity: 'high' });
    }
  });

  it('references only existing Audit read/export keys and adds none (UX discoverability only)', () => {
    const keys = new Set(contractForAccount('merchant_audit').flatMap((page) => page.permissionAll));
    expect([...keys].sort()).toEqual([
      'audit.branch_events.view',
      'audit.compensation.view',
      'audit.export',
      'audit.finance.view',
    ]);
  });
});
