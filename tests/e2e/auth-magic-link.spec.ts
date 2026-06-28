import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

/*
 | UI E2E coverage for the Magic Link flow (Plan §9.1).
 |
 | The SPA preview has no live backend, so the Sanctum and /api/v1 endpoints are
 | stubbed. These tests exercise the real frontend behavior for:
 |
 | - requesting a magic link;
 | - showing the check-email screen;
 | - consuming a valid token;
 | - routing an authenticated Merchant Administrator to the Phase 11 role landing;
 | - presenting a uniform invalid/expired-token error;
 | - preventing horizontal overflow at supported viewport widths; and
 | - meeting WCAG 2.0 A/AA automated accessibility checks.
 |
 | The genuine backend and Mailpit flow remains covered by the backend feature
 | suite and the API transcript in docs/proof/phase-5.md.
 */

const AUTH_USER = {
    user: {
        id: "01J0000000000000000000USER",
        email: "owner@salon.co.ke",
        name: "Owner",
        status: "active",
        email_verified_at: "2026-06-14T00:00:00+00:00",
        is_platform_staff: false,
    },
    merchant: {
        id: "01J000000000000000000MERCH",
        name: "Servana Demo Salon",
        slug: "servana-demo-salon",
        status: "active",
        service_fee_tier: "split_tier",
        setup_completed_at: "2026-06-14T00:00:00+00:00",
    },
    membership: {
        id: "01J00000000000000000MEMBER",
        role: "merchant_admin",
        status: "active",
    },
    memberships: [
        {
            id: "01J00000000000000000MEMBER",
            role: "merchant_admin",
            status: "active",
        },
    ],
    permissions: [],
    branch_ids: [],
    setup: {
        required: false,
        current_step: "done",
        completed_at: "2026-06-14T00:00:00+00:00",
    },
} as const;

const MAGIC_LINK_ACCEPTED_MESSAGE =
    "If the email exists and is active, a link was sent.";

const INVALID_TOKEN_MESSAGE =
    "This sign-in link is invalid or has expired. Please request a new one.";

async function fulfillJson(
    route: Route,
    status: number,
    body: unknown,
): Promise<void> {
    await route.fulfill({
        status,
        contentType: "application/json",
        body: JSON.stringify(body),
    });
}

/**
 * Stubs the requests made during unauthenticated SPA bootstrap.
 *
 * Individual tests register their endpoint-specific routes after this helper.
 * Playwright gives the most recently registered matching route precedence.
 */
async function stubLoggedOutBootstrap(page: Page): Promise<void> {
    await page.route("**/sanctum/csrf-cookie", async (route) => {
        await route.fulfill({
            status: 204,
            body: "",
        });
    });

    await page.route("**/api/v1/me", async (route) => {
        await fulfillJson(route, 401, {
            error: {
                code: "unauthenticated",
                message: "Unauthenticated.",
                fields: {},
                meta: {},
            },
        });
    });
}

async function assertNoWcagViolations(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa"])
        .analyze();

    expect(
        results.violations,
        results.violations
            .map(
                (violation) =>
                    `${violation.id}: ${violation.help} (${violation.nodes.length} node(s))`,
            )
            .join("\n"),
    ).toEqual([]);
}

test.describe("Magic Link UI", () => {
    test("login submits the email and shows the check-email screen", async ({
        page,
    }) => {
        await stubLoggedOutBootstrap(page);

        await page.route("**/api/v1/auth/magic-link", async (route) => {
            await fulfillJson(route, 202, {
                message: MAGIC_LINK_ACCEPTED_MESSAGE,
            });
        });

        await page.goto("/auth/login");

        await page.locator("#email").fill("owner@salon.co.ke");
        await page.locator('button[type="submit"]').click();

        await expect(page.getByText("Check your email")).toBeVisible();
        await expect(page).toHaveURL(/\/auth\/check-email(?:\?.*)?$/);
    });

    test("verify consumes the token and redirects to the role landing on success", async ({
        page,
    }) => {
        await stubLoggedOutBootstrap(page);

        await page.route("**/api/v1/auth/magic-link/verify", async (route) => {
            await fulfillJson(route, 200, {
                data: AUTH_USER,
            });
        });

        await page.goto("/auth/verify?token=good-token");

        await expect(page).toHaveURL(/\/merchant\/?$/);
    });

    test("verify shows a uniform error for an invalid or expired token", async ({
        page,
    }) => {
        await stubLoggedOutBootstrap(page);

        await page.route("**/api/v1/auth/magic-link/verify", async (route) => {
            await fulfillJson(route, 422, {
                error: {
                    code: "invalid_or_expired_token",
                    message: INVALID_TOKEN_MESSAGE,
                    fields: {},
                    meta: {},
                },
            });
        });

        await page.goto("/auth/verify?token=dead-token");

        await expect(page.getByText(/invalid or has expired/i)).toBeVisible();
        await expect(page).toHaveURL(/\/auth\/verify\?token=dead-token$/);
    });

    test("login page has no horizontal scroll at 360, 768, and 1280 pixels", async ({
        page,
    }) => {
        await stubLoggedOutBootstrap(page);

        for (const width of [360, 768, 1280]) {
            await test.step(`${width}px viewport`, async () => {
                await page.setViewportSize({
                    width,
                    height: 800,
                });

                await page.goto("/auth/login");

                const dimensions = await page.evaluate(() => ({
                    scrollWidth: document.documentElement.scrollWidth,
                    clientWidth: document.documentElement.clientWidth,
                }));

                expect(
                    dimensions.scrollWidth,
                    `Expected no horizontal overflow at ${width}px, but scrollWidth was ` +
                        `${dimensions.scrollWidth}px and clientWidth was ` +
                        `${dimensions.clientWidth}px.`,
                ).toBeLessThanOrEqual(dimensions.clientWidth);
            });
        }
    });

    test("login, check-email, and invalid-token pages have no WCAG A/AA violations", async ({
        page,
    }) => {
        await stubLoggedOutBootstrap(page);

        await page.route("**/api/v1/auth/magic-link/verify", async (route) => {
            await fulfillJson(route, 422, {
                error: {
                    code: "invalid_or_expired_token",
                    message: INVALID_TOKEN_MESSAGE,
                    fields: {},
                    meta: {},
                },
            });
        });

        const paths = [
            "/auth/login",
            "/auth/check-email?email=owner%40salon.co.ke",
            "/auth/verify?token=dead-token",
        ];

        for (const path of paths) {
            await test.step(`axe scan: ${path}`, async () => {
                await page.goto(path);
                await expect(page.locator("body")).toBeVisible();
                await assertNoWcagViolations(page);
            });
        }
    });
});
