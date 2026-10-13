import { defineConfig, devices } from '@playwright/test';

// Phase UI-16 (UI01-PROV-003): the release browser gate against the BUILT production image origin.
// Separate from playwright.config.ts on purpose — that suite runs against the Vite preview origin.
// SERVANA_ORIGIN is the local edge (host:port) every canonical account host is resolved to.
// Phase UI-17: SERVANA_ORIGIN=live drops the resolver mapping so the SAME specs run against the real
// production hosts through public DNS (the browser follows the edge's HTTP→HTTPS redirect).
const origin = process.env['SERVANA_ORIGIN'] ?? '127.0.0.1:8080';
const live = origin === 'live';

export default defineConfig({
  testDir: './tests/e2e-origin',
  fullyParallel: false,
  forbidOnly: !!process.env['CI'],
  retries: 0,
  workers: 1,
  reporter: 'list',
  use: {
    trace: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          args: live
            ? []
            : [
                `--host-resolver-rules=MAP servana.ke ${origin}, MAP *.servana.ke ${origin}`,
                // Keep plain-HTTP navigation to the local edge; live TLS is exercised with SERVANA_ORIGIN=live.
                '--disable-features=HttpsUpgrades',
              ],
        },
      },
    },
  ],
});
