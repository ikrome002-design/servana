import { defineConfig, devices } from '@playwright/test';

// LOCAL-UAT-001: the local browser acceptance run against the REAL local stack — nginx on the eight
// `*.servana.test` account hosts, Laravel, PostgreSQL, Redis, the queue workers and Mailpit — with no
// mocked API. Separate from playwright.config.ts (Vite preview + mocked API) and
// playwright.origin.config.ts (the built production image, unauthenticated).
//
// LOCAL_UAT_RESOLVER selects how the browser finds the hosts:
//   hosts (default) — the operating system resolver, i.e. the Windows hosts file. A pass in this mode
//                     is evidence that an ordinary browser on this machine can reach the hosts.
//   map             — Chromium `--host-resolver-rules` maps the hosts to loopback. DIAGNOSTIC ONLY: it
//                     proves the application, never ordinary-browser readiness.
const port = process.env['LOCAL_UAT_PORT'] ?? '8080';
const mapHosts = process.env['LOCAL_UAT_RESOLVER'] === 'map';

export default defineConfig({
  testDir: './tests/e2e-local-uat',
  fullyParallel: false,
  forbidOnly: !!process.env['CI'],
  retries: 0,
  workers: 1,
  timeout: 90_000,
  outputDir: 'test-results/local-uat/artifacts',
  reporter: [['list'], ['json', { outputFile: 'test-results/local-uat/report.json' }]],
  use: {
    // Traces would record the consumed sign-in URL; screenshots are enough to diagnose a failure.
    trace: 'off',
    screenshot: 'only-on-failure',
    launchOptions: {
      args: [
        ...(mapHosts ? ['--host-resolver-rules=MAP servana.test 127.0.0.1, MAP *.servana.test 127.0.0.1'] : []),
        // Local UAT is plain HTTP on a published port; never let Chromium try an HTTPS upgrade first.
        '--disable-features=HttpsUpgrades',
      ],
    },
  },
  metadata: { port, resolver: mapHosts ? 'map (diagnostic)' : 'hosts (ordinary browser)' },
  projects: [
    {
      name: 'sign-in',
      testMatch: /00-sign-in\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'acceptance',
      testMatch: /\d\d-.*\.spec\.ts/,
      dependencies: ['sign-in'],
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
