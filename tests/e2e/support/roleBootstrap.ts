import type { Page } from '@playwright/test';

/**
 * Shared /me bootstrap stub for Phase 11 role-entry e2e (Plan §27.2). The SPA
 * preview has no backend, so /api/v1/me + /sanctum are stubbed to drive the real
 * frontend: a role's landing, navigation, and get-started surfaces. The genuine
 * backend authorization is proven by the feature/auth suites; the frontend is
 * UX only.
 */
export interface RoleConfig {
  identity: string;
  path: string;
  label: string;
  role: string | null;
  isPlatformStaff: boolean;
}

export const ROLES: RoleConfig[] = [
  { identity: 'super_administrator', path: '/platform', label: 'Super Administrator', role: null, isPlatformStaff: true },
  { identity: 'merchant_administrator', path: '/merchant', label: 'Merchant Administrator', role: 'merchant_admin', isPlatformStaff: false },
  { identity: 'merchant_branch', path: '/branch', label: 'Branch Manager', role: 'branch_manager', isPlatformStaff: false },
  { identity: 'merchant_human_resource', path: '/hr', label: 'Human Resource', role: 'hr', isPlatformStaff: false },
  { identity: 'merchant_finance', path: '/finance', label: 'Finance', role: 'finance', isPlatformStaff: false },
  { identity: 'merchant_front_office', path: '/front-office', label: 'Front Office', role: 'front_office', isPlatformStaff: false },
  { identity: 'merchant_personnel', path: '/personnel', label: 'Personnel', role: 'personnel', isPlatformStaff: false },
  { identity: 'merchant_audit', path: '/audit', label: 'Audit', role: 'audit', isPlatformStaff: false },
];

/**
 * The account context the LARAVEL SHELL embeds into the document (Phase UI-02/UI-03).
 *
 * `vite preview` serves a static `index.html` with no shell, so this element is absent and
 * `currentAccountContext()` resolves to null. That is fine for a route with no owning account, but
 * UI-03 attached `requiresAccount('super_administrator')` to the `/platform` tree, and that guard
 * fails closed when the server established no host context — so every platform spec denied.
 *
 * Stubbing it is consistent with what this harness already does for `/api/v1/me` and `/sanctum`:
 * the backend is stubbed so the REAL frontend can be driven. The guard itself is untouched, and the
 * genuine server-side boundary is proven by the feature suites and by the UI-03 deployed-origin
 * browser proof, which exercises the guard against real account hosts.
 */
export async function stubAccountContextFor(page: Page, accountKey: string, displayName = accountKey): Promise<void> {
  await page.addInitScript(
    ([accountKey, displayName]) => {
      const inject = (): void => {
        if (document.getElementById('servana-account-context') !== null) return;
        const element = document.createElement('script');
        element.id = 'servana-account-context';
        element.type = 'application/json';
        element.textContent = JSON.stringify({
          account_key: accountKey,
          display_name: displayName,
          // `local` matches the preview origin's environment bucket; the hostname is `localhost`,
          // which maps to no account, so the context/address-bar consistency check does not fire.
          environment: 'local',
          host: 'localhost',
        });
        document.head.appendChild(element);
      };

      if (document.readyState === 'loading') {
        document.addEventListener('readystatechange', inject, { once: true });
      }
      inject();
    },
    [accountKey, displayName] as const,
  );
}

export async function stubBootstrap(page: Page, cfg: RoleConfig): Promise<void> {
  await stubAccountContextFor(page, cfg.identity, cfg.label);
  await page.route('**/sanctum/csrf-cookie', (route) => route.fulfill({ status: 204, body: '' }));
  await page.route('**/api/v1/me', (route) =>
    route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        data: {
          user: {
            id: '01JUSER0000000000000000000',
            email: 'user@salon.co.ke',
            name: 'Ada Test',
            status: 'active',
            email_verified_at: '2026-06-14T00:00:00+00:00',
            is_platform_staff: cfg.isPlatformStaff,
          },
          merchant: cfg.isPlatformStaff
            ? null
            : { id: 'm1', name: 'Glow Studio', slug: 'glow', status: 'active', service_fee_tier: null, setup_completed_at: '2026-01-01T00:00:00Z' },
          membership: cfg.role ? { id: 'mm1', role: cfg.role, status: 'active' } : null,
          memberships: cfg.role ? [{ id: 'mm1', role: cfg.role, status: 'active' }] : [],
          permissions: [],
          // UI-03 added `account_keys` to /me, derived server-side by AccountContextResolver, and
          // `requiresAccount` asks `holdsAccount()` for it. Without it the guard denies every
          // account surface it is attached to.
          account_keys: [cfg.identity],
          setup: { required: false, current_step: null, completed_at: null },
          branch_ids: cfg.isPlatformStaff ? [] : ['b1'],
          mfa: {
            required: false, enrolled: false, confirmed: false, verified: false,
            enrollment_required: false, challenge_required: false,
            step_up_fresh: false, step_up_fresh_until: null, recovery_codes_remaining: 0,
          },
        },
      }),
    }),
  );
}

export async function assertNoHorizontalScroll(page: Page): Promise<void> {
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
  if (scrollWidth > clientWidth) {
    throw new Error(`Horizontal overflow: scrollWidth ${scrollWidth} > clientWidth ${clientWidth}`);
  }
}
