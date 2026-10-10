import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { assertNoHorizontalScroll } from './support/roleBootstrap';
import { prepare, SCREENS } from './support/releaseAudit';
import { stubPlatformApi, stubSuperAdmin } from './support/ui08Platform';
import { stubMerchant, stubMerchantApi } from './support/ui09Merchant';
import { stubBranch, stubBranchApi } from './support/ui10Branch';

const REPRESENTATIVES = ['platform-dashboard', 'merchant-dashboard', 'branch-dashboard',
  'hr-dashboard', 'finance-dashboard', 'front-office-dashboard', 'personnel-appointments', 'audit-event-list'];
const ROLES = REPRESENTATIVES.map((key) => {
  const screen = SCREENS.find((entry) => entry.key === key);
  if (!screen) throw new Error(`Missing implemented representative: ${key}`);
  return screen;
});
// The legacy UI08 helper predates the current aggregate response. Reuse the
// exact synthetic response already verified by PlatformDashboard.spec.ts.
const platformDashboard = JSON.parse(readFileSync(new URL('./support/remdep003-platform-dashboard.json', import.meta.url), 'utf8'));

// Each case loads two font families and runs six viewport/axe/capture checks.
test.setTimeout(120_000);

// This is build-migration coverage: real account shells and compiled utilities, not backend
// authorization proof. Existing domain suites retain their own positive and negative contracts.
for (const role of ROLES) {
  for (const theme of ['light', 'dark'] as const) {
    test(`REM-DEP-003 ${role.role} ${theme} preserves shell and compiled utilities`, async ({ page }, testInfo) => {
      const diagnostics: string[] = [];
      page.on('pageerror', (error) => diagnostics.push(`pageerror: ${error.message}`));
      page.on('response', (response) => {
        if (response.url().includes('/api/')) diagnostics.push(`api: ${response.status()} ${new URL(response.url()).pathname}`);
      });
      page.on('requestfailed', (request) => diagnostics.push(`requestfailed: ${new URL(request.url()).pathname} ${request.failure()?.errorText}`));
      await prepare(page, role, { theme });
      if (role.role === 'super_administrator') {
        await stubSuperAdmin(page);
        await stubPlatformApi(page);
        await page.route('**/api/v1/platform/dashboard', (route) => route.fulfill({ json: platformDashboard }));
      } else if (role.role === 'merchant_administrator') {
        await stubMerchant(page);
        await stubMerchantApi(page);
      } else if (role.role === 'merchant_branch') {
        await stubBranch(page);
        await stubBranchApi(page);
      }
      await page.emulateMedia({ reducedMotion: 'reduce' });
      await page.goto(role.path);
      try {
        await expect(page.locator('main').first()).toBeVisible();
      } catch (error) {
        await testInfo.attach('shell-diagnostics', { body: JSON.stringify({ diagnostics, path: new URL(page.url()).pathname, body: await page.locator('body').innerText() }, null, 2), contentType: 'application/json' });
        throw error;
      }
      await expect(page.getByRole('heading', { name: /access denied|page not found/i })).toHaveCount(0);
      await expect(page.locator('main').first()).not.toContainText(/couldn't load|could not load|unable to load/i);
      if (role.role === 'super_administrator') await expect(page.getByTestId('dashboard-lifecycle').getByText('42', { exact: true })).toBeVisible();
      await page.evaluate(() => document.fonts.ready);
      const fonts = await page.evaluate(async () => {
        const loaded = await Promise.all([
          document.fonts.load('400 16px Inter'),
          document.fonts.load('700 24px Manrope'),
        ]);
        return loaded.map((faces) => faces.filter((face) => face.status === 'loaded').length);
      });
      expect(fonts.every((count) => count > 0)).toBe(true);
      await expect(page.locator('html')).toHaveClass(theme === 'dark' ? /dark/ : /^(?!.*\bdark\b)/);

      for (const width of [360, 767, 768, 1024, 1025, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await assertNoHorizontalScroll(page);

        // Every class here is used by production components. Test the browser's compiled
        // result, which catches config/source-discovery regressions that string guards miss.
        await page.evaluate(() => {
          document.getElementById('rem-dep-003-css-probe')?.remove();
          const probe = document.createElement('button');
          probe.id = 'rem-dep-003-css-probe';
          probe.textContent = 'Build compatibility probe';
          probe.className = 'sv-focus-ring hidden md:flex lg:block min-h-sv-touch min-w-sv-touch rounded-pill bg-sv-surface-raised text-sv-text';
          document.body.appendChild(probe);
        });
        await expect(page.locator('#rem-dep-003-css-probe')).toHaveCSS('display', width < 768 ? 'none' : width < 1025 ? 'flex' : 'block');
        const computed = await page.locator('#rem-dep-003-css-probe').evaluate((probe) => {
          const style = getComputedStyle(probe);
          return { display: style.display, minHeight: style.minHeight, minWidth: style.minWidth, radius: style.borderRadius, background: style.backgroundColor };
        });
        expect(computed.display).toBe(width < 768 ? 'none' : width < 1025 ? 'flex' : 'block');
        expect(computed.minHeight).toBe('44px');
        expect(computed.minWidth).toBe('44px');
        expect(computed.radius).toBe('9999px');
        expect(computed.background).not.toBe('rgba(0, 0, 0, 0)');
        await page.locator('#rem-dep-003-css-probe').evaluate((probe) => probe.remove());

        await page.evaluate(() => {
          const probe = document.createElement('div');
          probe.id = 'rem-dep-003-focus-probe';
          probe.className = 'shadow-card backdrop-blur rounded focus:outline-none';
          probe.tabIndex = -1;
          document.body.appendChild(probe);
          probe.focus();
        });
        // Reduced motion still uses a nonzero transition duration. Wait for the
        // actual computed focus state with Playwright's unchanged assertion budget.
        const focusProbe = page.locator('#rem-dep-003-focus-probe');
        await expect(focusProbe).toBeFocused();
        await expect(focusProbe).toHaveCSS('outline-width', '2px');
        await expect(focusProbe).toHaveCSS('outline-offset', '2px');
        const compatibility = await focusProbe.evaluate((probe) => {
          const style = getComputedStyle(probe);
          const result = { shadow: style.boxShadow, backdrop: style.backdropFilter, radius: style.borderRadius, outline: style.outlineWidth, outlineStyle: style.outlineStyle, offset: style.outlineOffset, focused: probe.matches(':focus') };
          probe.remove();
          return result;
        });
        expect(compatibility.shadow).not.toBe('none');
        expect(compatibility.backdrop).toBe('blur(8px)');
        expect(compatibility.radius).toBe('4px');
        expect(compatibility.outline, JSON.stringify(compatibility)).toBe('2px');
        expect(compatibility.offset).toBe('2px');
        await page.evaluate(() => window.scrollTo(0, 0));

        const axe = await new AxeBuilder({ page }).analyze();
        expect(axe.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact ?? ''))).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath(`${role.role}-${theme}-${width}.png`), fullPage: false });
      }
      expect(diagnostics.filter((entry) => entry.startsWith('pageerror:') || entry.startsWith('requestfailed:'))).toEqual([]);
    });
  }
}

test('REM-DEP-003 serves the unchanged approved images and derivatives', async ({ request }) => {
  const manifest = JSON.parse(readFileSync(new URL('../../public/assets/landing_page_images/manifest.json', import.meta.url), 'utf8')) as {
    total_selected: number;
    total_derivatives: number;
    images: { source_public_path: string; source_sha256: string; source_mime_type: string; derivatives: { public_path: string; sha256: string; mime_type: string }[] }[];
  };
  expect(manifest.total_selected).toBe(32);
  expect(manifest.total_derivatives).toBe(192);
  const assets = manifest.images.flatMap((image) => [
    { path: image.source_public_path, sha256: image.source_sha256, mime: image.source_mime_type },
    ...image.derivatives.map((derivative) => ({ path: derivative.public_path, sha256: derivative.sha256, mime: derivative.mime_type })),
  ]);
  expect(assets).toHaveLength(224);
  for (const asset of assets) {
    const response = await request.get(asset.path);
    expect(response.status(), asset.path).toBe(200);
    expect(response.headers()['content-type'], asset.path).toContain(asset.mime);
    expect(createHash('sha256').update(await response.body()).digest('hex'), asset.path).toBe(asset.sha256);
    await response.dispose();
  }
});
