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
  { identity: 'merchant_human_resource', path: '/hr', label: 'HR', role: 'hr', isPlatformStaff: false },
  { identity: 'merchant_finance', path: '/finance', label: 'Finance', role: 'finance', isPlatformStaff: false },
  { identity: 'merchant_front_office', path: '/front-office', label: 'Front Office', role: 'front_office', isPlatformStaff: false },
  { identity: 'merchant_personnel', path: '/personnel', label: 'Personnel', role: 'personnel', isPlatformStaff: false },
  { identity: 'merchant_audit', path: '/audit', label: 'Audit', role: 'audit', isPlatformStaff: false },
];

export async function stubBootstrap(page: Page, cfg: RoleConfig): Promise<void> {
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
