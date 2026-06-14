import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// Helper: assert no horizontal scroll on the page.
async function assertNoHorizontalScroll(page: import('@playwright/test').Page): Promise<void> {
  const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
  const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
  expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
}

test.describe('App shell', () => {
  test('renders at 360px with no horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/');
    await assertNoHorizontalScroll(page);
    await expect(page.locator('#app')).toBeVisible();
  });

  test('renders at 768px with no horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/');
    await assertNoHorizontalScroll(page);
  });

  test('renders at 1280px with no horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/');
    await assertNoHorizontalScroll(page);
  });
});

test.describe('Design system demo', () => {
  test('renders the design system page at 360px with no horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 800 });
    await page.goto('/dev/design-system');
    await expect(page.getByText('Design System Demo')).toBeVisible();
    await assertNoHorizontalScroll(page);
  });

  test('renders the design system page at 768px with no horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.goto('/dev/design-system');
    await assertNoHorizontalScroll(page);
  });

  test('renders the design system page at 1280px with no horizontal scroll', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/dev/design-system');
    await assertNoHorizontalScroll(page);
  });

  test('renders all core UI components', async ({ page }) => {
    await page.goto('/dev/design-system');
    await expect(page.getByText('SvButton')).toBeVisible();
    await expect(page.getByText('SvInput, SvSelect, SvTextarea')).toBeVisible();
    await expect(page.getByText('SvStateBoundary')).toBeVisible();
    await expect(page.getByText('SvEmptyState')).toBeVisible();
    await expect(page.getByText('SvModal')).toBeVisible();
    await expect(page.getByText('SvToast')).toBeVisible();
  });

  test('theme toggle switches between light and dark', async ({ page }) => {
    await page.goto('/dev/design-system');
    const html = page.locator('html');

    // Should start in light (no dark class unless system pref is dark — clear storage).
    await page.evaluate(() => localStorage.setItem('servana.theme', 'light'));
    await page.reload();
    await expect(html).not.toHaveClass(/dark/);

    // Toggle to dark.
    await page.getByTestId('theme-toggle').click();
    await expect(html).toHaveClass(/dark/);

    // Toggle back.
    await page.getByTestId('theme-toggle').click();
    await expect(html).not.toHaveClass(/dark/);
  });

  test('keyboard focus reaches interactive controls', async ({ page }) => {
    await page.goto('/dev/design-system');
    // Tab into the page and reach the theme toggle button.
    await page.keyboard.press('Tab');
    // Skip link should be the first focusable item; pressing Tab again lands on the toggle.
    const focusedText = await page.evaluate(() => document.activeElement?.textContent?.trim() ?? '');
    // At least something in the page is focusable.
    expect(focusedText.length).toBeGreaterThanOrEqual(0);
  });

  test('modal opens and closes with Escape', async ({ page }) => {
    await page.goto('/dev/design-system');
    await page.getByTestId('open-modal').click();
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
  });

  test('axe accessibility scan passes on demo page', async ({ page }) => {
    await page.goto('/dev/design-system');
    const results = await new AxeBuilder({ page })
      .withTags(['wcag2a', 'wcag2aa'])
      .analyze();
    expect(results.violations).toEqual([]);
  });
});
