/**
 * LOCAL-UAT-001 — LUAT-D01 closure in the REAL browser: the five Front Office screens that choose a
 * service and eligible personnel, driven through their actual controls by the seeded Front Office
 * automation identity on office.servana.test, against the real Laravel/PostgreSQL stack.
 *
 * Each step asserts the screen read its choices from the narrow Front Office selection endpoint with
 * its own purpose (never the Branch Manager catalogue `/services`), that no request was refused, and
 * that the resulting record persisted (the page re-reads it after navigation). Runs after
 * 10-role-workflows, which opens the branch day and queue.
 */
import { expect, test, type Locator, type Page, type Response } from '@playwright/test';

import { appendResult, businessDate, origin, statePath, watchPage, writeRuntime } from './support/localUat';

test.describe.configure({ mode: 'serial' });
test.use({ storageState: statePath('merchant_front_office') });

const base = origin('merchant_front_office');
const state: Record<string, string> = {};

/** Every API response the page sees, for "no catalogue read, no refusal" assertions. */
function recordApi(page: Page): { url: string; status: number }[] {
  const seen: { url: string; status: number }[] = [];
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.pathname.startsWith('/api/')) seen.push({ url: url.pathname + url.search, status: response.status() });
  });

  return seen;
}

/** A native select by its accessible name (the shell navigation also has "Clients" regions). */
function combo(page: Page, name: string): Locator {
  return page.getByRole('combobox', { name: new RegExp(`^${escapeRegExp(name)}`) });
}

function field(page: Page, name: string): Locator {
  return page.getByRole('textbox', { name: new RegExp(`^${escapeRegExp(name)}`) });
}

function escapeRegExp(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function selectionResponse(page: Page, kind: 'service-options' | 'personnel-options', purpose: string): Promise<Response> {
  return page.waitForResponse((r) => r.url().includes(`/api/v1/front-office/`) && r.url().includes(kind) && r.url().includes(`purpose=${purpose}`));
}

function assertNoCatalogueOrRefusal(seen: { url: string; status: number }[], step: string): void {
  expect(seen.filter((s) => s.url.startsWith('/api/v1/services')), `${step}: no catalogue read`).toEqual([]);
  expect(seen.filter((s) => s.status === 401 || s.status === 403), `${step}: nothing refused`).toEqual([]);
}

async function pickOtherThan(page: Page, label: string, current: string | null): Promise<string> {
  const options = await combo(page, label).locator('option').evaluateAll((els) =>
    (els as HTMLOptionElement[]).filter((o) => o.value !== '' && !o.disabled).map((o) => ({ value: o.value, text: o.textContent?.trim() ?? '' })),
  );
  const choice = options.find((o) => current === null || !current.includes(o.text)) ?? options[0];
  expect(choice, `${label} offers at least one eligible person`).toBeDefined();
  await combo(page, label).selectOption(choice?.value ?? '');

  return choice?.text ?? '';
}

test('WalkInCreate: chooses a service and an eligible person, then queues the walk-in', async ({ page }) => {
  const seen = recordApi(page);
  const health = watchPage(page);

  const services = selectionResponse(page, 'service-options', 'walk_in');
  await page.goto(`${base}/walk-ins`);
  expect((await services).status()).toBe(200);

  await expect(combo(page, 'Client').locator('option')).not.toHaveCount(1);
  await combo(page, 'Client').selectOption({ index: 1 });
  const personnel = selectionResponse(page, 'personnel-options', 'walk_in');
  await combo(page, 'Service').selectOption({ label: 'Classic haircut (30 min)' });
  expect((await personnel).status()).toBe(200);

  await combo(page, 'Assignment').selectOption('manual');
  const chosen = await pickOtherThan(page, 'Personnel', null);
  await page.getByRole('button', { name: 'Add to queue' }).click();

  await page.waitForURL(/\/queue\/[0-9A-Z]{26}$/);
  state['walkInQueueUlid'] = new URL(page.url()).pathname.split('/').pop() ?? '';
  await expect(page.getByText(chosen).first()).toBeVisible();
  assertNoCatalogueOrRefusal(seen, 'walk-in');
  expect(health.errors).toEqual([]);
  appendResult('front-office-selection', { screen: 'WalkInCreate', purpose: 'walk_in', persisted: state['walkInQueueUlid'], assigned: chosen, result: 'pass' });
});

test('QueueEntryDetail: the transfer dialog offers eligible personnel and transfers the entry', async ({ page }) => {
  const seen = recordApi(page);
  await page.goto(`${base}/queue/${state['walkInQueueUlid']}`);
  // Read the CURRENT assignee only after the record has loaded, so the test never picks the same person.
  await page.waitForLoadState('networkidle');
  await expect(page.getByText('Assigned personnel')).toBeVisible();
  const current = await page.locator('main').innerText();

  const personnel = selectionResponse(page, 'personnel-options', 'queue_transfer');
  await page.getByRole('button', { name: 'Transfer', exact: true }).click();
  expect((await personnel).status()).toBe(200);

  const chosen = await pickOtherThan(page, 'Personnel', current);
  await field(page, 'Reason').fill('Rebalancing the queue (local UAT).');
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByText(/transferred/i).first()).toBeVisible();

  await page.reload();
  await expect(page.getByText(chosen).first()).toBeVisible();
  assertNoCatalogueOrRefusal(seen, 'queue-entry transfer');
  appendResult('front-office-selection', { screen: 'QueueEntryDetail', purpose: 'queue_transfer', persisted: state['walkInQueueUlid'], assigned: chosen, result: 'pass' });
});

test('QueueTransfer: the transfer page offers eligible personnel and records the transfer', async ({ page }) => {
  const seen = recordApi(page);
  await page.goto(`${base}/queue/${state['walkInQueueUlid']}`);
  // Read the CURRENT assignee only after the record has loaded, so the test never picks the same person.
  await page.waitForLoadState('networkidle');
  await expect(page.getByText('Assigned personnel')).toBeVisible();
  const current = await page.locator('main').innerText();

  const personnel = selectionResponse(page, 'personnel-options', 'queue_transfer');
  await page.goto(`${base}/queue/${state['walkInQueueUlid']}/transfer`);
  expect((await personnel).status()).toBe(200);

  const chosen = await pickOtherThan(page, 'Transfer to', current);
  await field(page, 'Transfer reason').fill('Client asked for another stylist (local UAT).');
  await page.getByRole('button', { name: 'Confirm transfer' }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/transfer'));

  await page.goto(`${base}/queue/${state['walkInQueueUlid']}`);
  await expect(page.getByText(chosen).first()).toBeVisible();
  assertNoCatalogueOrRefusal(seen, 'queue transfer page');
  appendResult('front-office-selection', { screen: 'QueueTransfer', purpose: 'queue_transfer', persisted: state['walkInQueueUlid'], assigned: chosen, result: 'pass' });
});

test('AppointmentCreate: chooses a service, a time and an eligible person, then books', async ({ page }) => {
  const seen = recordApi(page);
  const health = watchPage(page);

  const services = selectionResponse(page, 'service-options', 'appointment');
  await page.goto(`${base}/appointments/create`);
  expect((await services).status()).toBe(200);

  await expect(combo(page, 'Client').locator('option')).not.toHaveCount(0);
  await combo(page, 'Client').selectOption({ index: 1 });
  const personnel = selectionResponse(page, 'personnel-options', 'appointment');
  await combo(page, 'Service').selectOption({ label: 'Swedish massage (60 min)' });
  expect((await personnel).status()).toBe(200);

  // A slot that differs run to run, so repeated local runs do not collide with their own bookings.
  const minute = Math.floor(Date.now() / 60_000);
  const hour = String(7 + (minute % 12)).padStart(2, '0');
  const slot = String((Math.floor(minute / 12) % 4) * 15).padStart(2, '0');
  await page.locator('input[type="datetime-local"]').fill(`${businessDate(2)}T${hour}:${slot}`);
  const chosen = await pickOtherThan(page, 'Assign personnel (optional)', null);
  await page.getByRole('button', { name: 'Book appointment' }).click();

  await page.waitForURL(/\/appointments\/[0-9A-Z]{26}$/);
  state['appointmentUlid'] = new URL(page.url()).pathname.split('/').pop() ?? '';
  await expect(page.getByText(chosen).first()).toBeVisible();
  assertNoCatalogueOrRefusal(seen, 'appointment create');
  expect(health.errors).toEqual([]);
  appendResult('front-office-selection', { screen: 'AppointmentCreate', purpose: 'appointment', persisted: state['appointmentUlid'], assigned: chosen, result: 'pass' });
});

test('AppointmentDetail: the transfer dialog offers eligible personnel and transfers the appointment', async ({ page }) => {
  const seen = recordApi(page);
  await page.goto(`${base}/appointments/${state['appointmentUlid']}`);
  // Read the CURRENT assignee only after the record has loaded, so the test never picks the same person.
  await page.waitForLoadState('networkidle');
  await expect(page.getByText('Assigned personnel')).toBeVisible();
  const current = await page.locator('main').innerText();

  const personnel = selectionResponse(page, 'personnel-options', 'appointment_transfer');
  await page.getByRole('button', { name: 'Transfer', exact: true }).click();
  expect((await personnel).status()).toBe(200);

  const chosen = await pickOtherThan(page, 'Personnel', current);
  await field(page, 'Reason (optional)').fill('Stylist availability changed (local UAT).');
  await page.getByRole('button', { name: 'Confirm' }).click();
  await expect(page.getByText(/transferred/i).first()).toBeVisible();

  await page.reload();
  await expect(page.getByText(chosen).first()).toBeVisible();
  assertNoCatalogueOrRefusal(seen, 'appointment transfer');
  writeRuntime({ appointmentUlid: state['appointmentUlid'] ?? '', walkInQueueUlid: state['walkInQueueUlid'] ?? '' });
  appendResult('front-office-selection', { screen: 'AppointmentDetail', purpose: 'appointment_transfer', persisted: state['appointmentUlid'], assigned: chosen, result: 'pass' });
});
