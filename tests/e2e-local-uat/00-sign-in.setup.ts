/**
 * LOCAL-UAT-001 — sign every account's AUTOMATION identity in through the real browser flow:
 * login form -> queued Magic Link mail -> Mailpit -> verify link on the same host -> MFA challenge
 * (mandatory-MFA accounts) -> authenticated landing. The resulting session cookies are saved per
 * account (test-results/local-uat/state/, git-ignored) for the acceptance specs.
 *
 * Eight Magic Link requests count against the 10-per-hour per-IP limiter (Plan §9.3), which every
 * request through the local Docker gateway shares. `scripts/dev/servana-local.ps1 uat` clears the
 * local rate-limit cache first; it never changes the limiter itself.
 */
import { expect, test as setup } from '@playwright/test';

import { ACCOUNT_KEYS, ACCOUNTS, api, signInThroughBrowser, statePath } from './support/localUat';

for (const account of ACCOUNT_KEYS) {
  setup(`sign in ${account} through the browser on ${ACCOUNTS[account].host}`, async ({ page }) => {
    await signInThroughBrowser(page, account);

    // The session is real: the authenticated identity endpoint answers on this host.
    const me = await api(page, account, 'GET', '/api/v1/me');
    expect(me.status).toBe(200);

    await page.context().storageState({ path: statePath(account) });
  });
}
