import { readFileSync, readdirSync, statSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, test } from '@playwright/test';
import {
  AUDIT_BUSINESS_DATE,
  AUDIT_FUTURE_DATE,
  IDS,
  LONG_MERCHANT_NAME,
  SCREENS,
  THEMES,
  VIEWPORTS,
  assertAxeClean,
  assertNoElementOverflow,
  assertNoHorizontalOverflow,
  assertShellUsable,
  assertZoomEnabled,
  baseFixtures,
  isShellScreen,
  liveInventoryScreens,
  open,
  prepare,
  readyLocator,
} from './support/releaseAudit';

/*
 | Phase 23 whole-product release audit (Plan §28 responsive, §29 theming, §30 accessibility).
 |
 | Increment 6 — every implemented screen is responsive at 360 / 768 / 1280.
 | Increment 7 — every implemented screen is correct in light AND dark.
 | Increment 8 — every implemented screen has zero serious/critical axe violations, and the
 |               keyboard, focus, zoom and reduced-motion behaviours hold.
 | Increment 9 — the audit is deterministic: fixed clock, fixed identifiers, fixed fixtures.
 |
 | The matrix is data-driven from docs/frontend/screens/inventory.json, so a screen delivered
 | later cannot escape the audit — the coverage guard below fails until it is enrolled.
 */

test.describe.configure({ mode: 'parallel' });

// --- Coverage guard -----------------------------------------------------------

test.describe('release-audit coverage', () => {
  test('audits every live inventory screen, and no invented one', () => {
    const live = liveInventoryScreens().map((s) => s.key).sort();
    const audited = SCREENS.map((s) => s.key).sort();

    const missing = live.filter((k) => !audited.includes(k));
    const invented = audited.filter((k) => !live.includes(k));

    expect(
      missing,
      `Live screens with NO Phase 23 release-audit coverage:\n${missing.join('\n')}`,
    ).toEqual([]);
    expect(
      invented,
      `Audited keys that are not live inventory screens:\n${invented.join('\n')}`,
    ).toEqual([]);
  });

  test('has no duplicate audit key and every screen declares a path', () => {
    expect(new Set(SCREENS.map((s) => s.key)).size).toBe(SCREENS.length);
    for (const s of SCREENS) expect(s.path, `${s.key} path`).toMatch(/^\//);
  });

  test('never uses JavaScript device detection (guardrail 1)', () => {
    // Responsiveness is CSS-media-query only. A user-agent sniff would make the layout lie about
    // the viewport and is forbidden outright, so it is proven over the source, not the DOM.
    const SRC = resolve(import.meta.dirname, '../../resources/spa/src');
    const offenders: string[] = [];
    const walk = (dir: string): void => {
      for (const entry of readdirSync(dir)) {
        const full = resolve(dir, entry);
        if (statSync(full).isDirectory()) {
          walk(full);
          continue;
        }
        if (!/\.(ts|vue|js)$/.test(entry) || entry.endsWith('.spec.ts')) continue;
        const source = readFileSync(full, 'utf8');
        if (/navigator\s*\.\s*(userAgent|platform|vendor|maxTouchPoints)|\bisMobileDevice\b|jQuery|\$\(document\)/.test(source)) {
          offenders.push(full);
        }
      }
    };
    walk(SRC);
    expect(offenders, `Device detection / jQuery in the SPA:\n${offenders.join('\n')}`).toEqual([]);
  });
});

// --- Increment 6 — responsive -------------------------------------------------

for (const screen of SCREENS) {
  test(`responsive: ${screen.key}`, async ({ page }) => {
    await prepare(page, screen);
    await page.setViewportSize({ width: VIEWPORTS[2]!.width, height: VIEWPORTS[2]!.height });
    await open(page, screen);
    await assertZoomEnabled(page);

    // One navigation, then RESIZE through the matrix: this also proves requirement §9.2(18),
    // that a live resize re-lays-out correctly rather than only a fresh load doing so.
    for (const viewport of VIEWPORTS) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      const label = `${screen.key} @ ${viewport.name} ${viewport.width}px`;

      await expect(
        page.locator(readyLocator(screen)).first(),
        `${label}: screen content visible`,
      ).toBeVisible();
      await assertNoHorizontalOverflow(page, label);
      await assertNoElementOverflow(page, label);
      await assertShellUsable(page, screen, viewport);
    }
  });
}

// --- Increment 7 — light / dark ------------------------------------------------

for (const screen of SCREENS) {
  test(`theme: ${screen.key}`, async ({ page }) => {
    for (const theme of THEMES) {
      const context = page.context();
      await context.clearCookies();
      await prepare(page, screen, { theme });
      await page.setViewportSize({ width: 1280, height: 900 });
      await open(page, screen);

      const label = `${screen.key} (${theme})`;

      // The theme actually applied — a screen that silently stays light proves nothing.
      const isDark = await page.evaluate(() => document.documentElement.classList.contains('dark'));
      expect(isDark, `${label}: html.dark class`).toBe(theme === 'dark');

      // Body text must not collapse into the background in either theme.
      await assertReadableText(page, label);
      await assertNoHorizontalOverflow(page, label);
      await page.unrouteAll({ behavior: 'ignoreErrors' });
    }
  });
}

/**
 * Every rendered text node has a non-transparent colour that differs from the surface behind it.
 * Precise ratio checking is axe's job (Increment 8 runs it in BOTH themes); this catches the
 * dark-mode failure axe cannot see — a token that resolves to the same colour as its background.
 */
async function assertReadableText(page: import('@playwright/test').Page, label: string): Promise<void> {
  const offenders = await page.evaluate(() => {
    const parse = (value: string): [number, number, number, number] => {
      const m = value.match(/rgba?\(([^)]+)\)/);
      if (!m) return [0, 0, 0, 1];
      const parts = m[1]!.split(',').map((p) => parseFloat(p.trim()));
      return [parts[0] ?? 0, parts[1] ?? 0, parts[2] ?? 0, parts[3] ?? 1];
    };
    /**
     * The EFFECTIVE background behind an element: every translucent layer composited over its
     * ancestors. Taking the first non-transparent background instead would misread a legitimate
     * `bg-white/15` overlay on the dark brand header as solid white.
     */
    const backdrop = (el: HTMLElement): [number, number, number] => {
      const layers: [number, number, number, number][] = [];
      let node: HTMLElement | null = el;
      while (node) {
        const bg = parse(getComputedStyle(node).backgroundColor);
        if (bg[3] > 0) {
          layers.push(bg);
          if (bg[3] >= 1) break;
        }
        node = node.parentElement;
      }
      // Composite from the furthest (opaque) layer forward.
      let [r, g, b] = layers.length > 0 ? [layers.at(-1)![0], layers.at(-1)![1], layers.at(-1)![2]] : [255, 255, 255];
      for (let i = layers.length - 2; i >= 0; i -= 1) {
        const [lr, lg, lb, la] = layers[i]!;
        r = lr * la + r * (1 - la);
        g = lg * la + g * (1 - la);
        b = lb * la + b * (1 - la);
      }
      return [Math.round(r), Math.round(g), Math.round(b)];
    };

    const out: string[] = [];
    for (const el of Array.from(document.body.querySelectorAll<HTMLElement>('*'))) {
      const text = Array.from(el.childNodes)
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => n.textContent?.trim() ?? '')
        .join('');
      if (text === '') continue;
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) continue;
      const style = getComputedStyle(el);
      if (style.visibility === 'hidden' || style.opacity === '0') continue;

      const fg = parse(style.color);
      if (fg[3] === 0) {
        out.push(`transparent text: "${text.slice(0, 40)}"`);
        continue;
      }
      const bg = backdrop(el);
      // Composited text and background within 8/255 per channel is indistinguishable.
      if (Math.abs(fg[0] - bg[0]) < 8 && Math.abs(fg[1] - bg[1]) < 8 && Math.abs(fg[2] - bg[2]) < 8) {
        out.push(
          `text matches its background: "${text.slice(0, 40)}" (${style.color} on rgb(${bg.join(', ')}))`,
        );
      }
      if (out.length >= 5) break;
    }
    return out;
  });

  expect(offenders, `${label}: unreadable text:\n${offenders.join('\n')}`).toEqual([]);
}

// --- Increment 8 — accessibility ------------------------------------------------

const AXE_COMBOS = [
  { theme: 'light' as const, width: 360, height: 780, name: 'mobile-light' },
  { theme: 'dark' as const, width: 360, height: 780, name: 'mobile-dark' },
  { theme: 'light' as const, width: 1280, height: 900, name: 'desktop-light' },
  { theme: 'dark' as const, width: 1280, height: 900, name: 'desktop-dark' },
];

for (const screen of SCREENS) {
  test(`axe: ${screen.key}`, async ({ page }) => {
    for (const combo of AXE_COMBOS) {
      await prepare(page, screen, { theme: combo.theme });
      await page.setViewportSize({ width: combo.width, height: combo.height });
      await open(page, screen);
      await assertAxeClean(page, `${screen.key} @ ${combo.name}`);
      await page.unrouteAll({ behavior: 'ignoreErrors' });
    }
  });
}

test.describe('accessibility behaviour', () => {
  const shellScreen = SCREENS.find((s) => s.key === 'merchant-profile')!;

  test('skip link is the first focus stop and moves focus to main', async ({ page }) => {
    await prepare(page, shellScreen);
    await open(page, shellScreen);

    await page.keyboard.press('Tab');
    const skip = page.locator('a[href="#main-content"]');
    await expect(skip).toBeFocused();
    await expect(skip).toBeVisible();

    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
  });

  test('landmarks are present and unique', async ({ page }) => {
    await prepare(page, shellScreen);
    await open(page, shellScreen);
    await expect(page.locator('header')).toHaveCount(1);
    await expect(page.locator('main#main-content')).toHaveCount(1);
    await expect(page.getByRole('navigation', { name: 'Primary navigation' })).toBeVisible();
  });

  test('mobile navigation drawer takes focus, traps Escape and restores focus', async ({ page }) => {
    await prepare(page, shellScreen);
    await page.setViewportSize({ width: 360, height: 780 });
    await open(page, shellScreen);

    const trigger = page.locator('[data-testid="nav-drawer-trigger"]');
    await trigger.click();

    const drawer = page.getByRole('dialog', { name: 'Navigation' });
    await expect(drawer).toBeVisible();
    // Initial focus lands inside the dialog, not behind it.
    await expect(drawer.locator(':focus')).toHaveCount(1);

    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(trigger).toBeFocused();
  });

  test('every keyboard focus stop shows a visible focus indicator', async ({ page }) => {
    await prepare(page, shellScreen);
    await open(page, shellScreen);

    // Real Tab traversal, not `element.focus()`: `:focus-visible` (which is what draws the ring)
    // only matches for keyboard-initiated focus, so a programmatic focus reports no ring and
    // would fail every control on the page.
    const invisible: string[] = [];
    const seen = new Set<string>();
    let firstKey: string | null = null;
    for (let stop = 0; stop < 40; stop += 1) {
      await page.keyboard.press('Tab');
      const info = await page.evaluate(() => {
        const el = document.activeElement as HTMLElement | null;
        if (!el || el === document.body) return null;
        const style = getComputedStyle(el);
        return {
          key: `${el.tagName.toLowerCase()}#${el.id || ''}|${(el.textContent ?? '').trim().slice(0, 30)}|${el.className?.toString().slice(0, 40)}`,
          outlineStyle: style.outlineStyle,
          outlineWidth: parseFloat(style.outlineWidth),
          boxShadow: style.boxShadow,
          matchesFocusVisible: el.matches(':focus-visible'),
        };
      });
      if (!info) break;
      if (info.key === firstKey) break; // wrapped back to the first stop
      firstKey ??= info.key;
      seen.add(info.key);

      const hasRing =
        (info.outlineStyle !== 'none' && info.outlineWidth > 0)
        || (info.boxShadow !== 'none' && info.boxShadow !== '');
      if (!hasRing) {
        invisible.push(
          `${info.key} → outline ${info.outlineStyle}/${info.outlineWidth}px, shadow ${info.boxShadow}, :focus-visible=${info.matchesFocusVisible}`,
        );
      }
    }

    expect(seen.size, 'keyboard traversal reached focusable controls').toBeGreaterThan(5);
    expect(invisible, `Keyboard focus stops with no visible indicator:\n${invisible.join('\n')}`).toEqual([]);
  });

  test('remains usable at 200% browser zoom with no horizontal overflow', async ({ page }) => {
    await prepare(page, shellScreen);
    // 200% zoom at a 1280px window is equivalent to a 640px CSS viewport.
    await page.setViewportSize({ width: 640, height: 900 });
    await open(page, shellScreen);
    await assertNoHorizontalOverflow(page, 'merchant-profile @ 200% zoom');
    await expect(page.getByLabel('Business category')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save changes' })).toBeVisible();
  });

  test('honours prefers-reduced-motion', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await prepare(page, shellScreen);
    await open(page, shellScreen);

    const animated = await page.evaluate(() => {
      const out: string[] = [];
      for (const el of Array.from(document.querySelectorAll<HTMLElement>('*'))) {
        const style = getComputedStyle(el);
        const duration = parseFloat(style.transitionDuration) + parseFloat(style.animationDuration);
        if (duration > 0.2) out.push(`${el.tagName.toLowerCase()}.${el.className?.toString().slice(0, 40)}`);
        if (out.length >= 5) break;
      }
      return out;
    });

    expect(animated, `Long animations under prefers-reduced-motion:\n${animated.join('\n')}`).toEqual([]);
  });
});

// --- Increment 9 — deterministic workflows --------------------------------------

test.describe('REM-SCR-002A — merchant profile determinism', () => {
  const screen = SCREENS.find((s) => s.key === 'merchant-profile')!;

  test('updates one authorized field, persists it, and reloads it', async ({ page }) => {
    // A single server-side value that the PATCH updates, so the reload proves persistence rather
    // than a retained client-side form value. Registered AFTER prepare(): Playwright resolves the
    // most recently added matching route first, so this must outrank the catch-all.
    let town = 'Nairobi';
    const profileFixture = baseFixtures().find((f) => f.match.test('/merchant/profile'))!;
    const profile = (profileFixture.body as { data: Record<string, unknown> }).data;

    await prepare(page, screen);
    await page.route('**/api/v1/merchant/profile', async (route) => {
      const request = route.request();
      if (request.method() === 'PATCH') {
        const payload = request.postDataJSON() as { town?: string };
        town = payload.town ?? town;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: { ...profile, town } }),
      });
    });

    await open(page, screen);

    await expect(page.getByLabel('Town')).toHaveValue('Nairobi');
    await page.getByLabel('Town').fill('Mombasa');
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('Business profile saved.')).toBeVisible();

    await page.reload();
    await expect(page.getByLabel('Town')).toHaveValue('Mombasa');

    // Read-only context stays read-only and legible; no private object path is ever rendered.
    await expect(page.locator('#main-content').getByText(LONG_MERCHANT_NAME)).toBeVisible();
    const html = await page.content();
    expect(html).not.toMatch(/merchants\/[^"'<>\s]*\/logo|s3:\/\/|\/storage\/app\//);
  });
});

test.describe('REM-SCR-002B — branch calendar determinism', () => {
  const screen = SCREENS.find((s) => s.key === 'branch-calendar')!;

  test('creates a future full-day closure, reloads, and still shows it', async ({ page }) => {
    await prepare(page, screen);
    await routeCalendar(page, []);
    await open(page, screen);

    await page.getByLabel('Date').fill(AUDIT_FUTURE_DATE);
    await page.getByLabel('Type').selectOption('special_closure');
    await page.getByLabel('Reason (optional)').fill('Annual staff training day');
    await page.getByRole('button', { name: 'Add exception' }).click();

    await expect(page.getByText('Calendar exception saved.')).toBeVisible();
    await page.reload();
    const row = page.getByRole('row').filter({ hasText: AUDIT_FUTURE_DATE });
    await expect(row).toHaveCount(1);
    await expect(row.getByRole('cell', { name: 'Closed all day', exact: true })).toBeVisible();
    await expect(row.getByRole('cell', { name: 'Special closure', exact: true })).toBeVisible();
    // The fixed clock guarantees the created date is genuinely in the future.
    expect(AUDIT_FUTURE_DATE > AUDIT_BUSINESS_DATE).toBe(true);
  });

  test('creates a modified-hours exception, reloads, and shows normalized hours', async ({ page }) => {
    await prepare(page, screen);
    await routeCalendar(page, []);
    await open(page, screen);

    await page.getByLabel('Date').fill(AUDIT_FUTURE_DATE);
    await page.getByLabel('Type').selectOption('modified_hours');
    await page.getByLabel('Opens at').fill('10:00');
    await page.getByLabel('Closes at').fill('15:30');
    await page.getByRole('button', { name: 'Add exception' }).click();

    await expect(page.getByText('Calendar exception saved.')).toBeVisible();
    await page.reload();
    const row = page.getByRole('row').filter({ hasText: AUDIT_FUTURE_DATE });
    await expect(row).toHaveCount(1);
    await expect(row.getByRole('cell', { name: '10:00 – 15:30', exact: true })).toBeVisible();
  });

  /**
   * A single stateful calendar endpoint so create → reload → read proves persistence.
   * The server normalizes `HH:MM:SS` to `HH:MM` and derives `closes_branch` from the type,
   * exactly as BranchCalendarExceptionResource does.
   */
  async function routeCalendar(
    page: import('@playwright/test').Page,
    rows: Record<string, unknown>[],
  ): Promise<void> {
    await page.route('**/api/v1/branches/*/calendar-exceptions**', async (route) => {
      const request = route.request();
      if (request.method() === 'POST') {
        const body = request.postDataJSON() as {
          date: string;
          type: string;
          opens_at: string | null;
          closes_at: string | null;
          reason: string | null;
        };
        rows.push({
          date: body.date,
          type: body.type,
          closes_branch: body.type !== 'modified_hours',
          opens_at: body.type === 'modified_hours' ? body.opens_at?.slice(0, 5) ?? null : null,
          closes_at: body.type === 'modified_hours' ? body.closes_at?.slice(0, 5) ?? null : null,
          reason: body.reason,
          created_at: '2026-07-15T09:00:00+00:00',
        });
        return route.fulfill({ status: 201, contentType: 'application/json', body: JSON.stringify({ data: rows.at(-1) }) });
      }
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ data: rows, meta: { from: AUDIT_BUSINESS_DATE, to: '2026-10-13' } }),
      });
    });
  }
});

test.describe('audit fixtures are deterministic', () => {
  test('uses fixed identifiers and a pinned Nairobi clock, never the wall clock', async ({ page }) => {
    const screen = SCREENS.find((s) => s.key === 'branch-calendar')!;
    await prepare(page, screen);
    await open(page, screen);

    const now = await page.evaluate(() => new Date().toISOString());
    expect(now).toBe('2026-07-15T09:00:00.000Z');
    expect(IDS.branch).toHaveLength(26);
    expect(isShellScreen(screen)).toBe(true);
  });
});
