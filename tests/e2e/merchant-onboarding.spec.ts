import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

/*
 | UI E2E coverage for merchant onboarding
 | (Scope §3.1/§3.2, Plan §27 Phase 6).
 |
 | The SPA preview has no live backend, so the Sanctum and /api/v1 endpoints are
 | stubbed. These tests exercise the real frontend behavior for:
 |
 | - merchant self-registration;
 | - the uniform registration success state;
 | - redirecting a pending_setup merchant owner to first-time setup;
 | - completing all first-time setup steps;
 | - refreshing the authenticated bootstrap state after setup;
 | - routing the active Merchant Administrator to the Phase 11 role landing; and
 | - meeting WCAG 2.0 A/AA automated accessibility checks.
 |
 | The genuine backend and Mailpit behavior remains covered by the backend
 | feature suite and docs/proof/phase-6.md.
 */

const CSRF_ROUTE = "**/sanctum/csrf-cookie";
const CURRENT_USER_ROUTE = "**/api/v1/me";
const SELF_REGISTER_ROUTE = "**/api/v1/merchant-registration/self-register";
const FIRST_TIME_SETUP_ROUTE =
    "**/api/v1/merchant-registration/first-time-setup";

const REGISTRATION_SUCCESS_MESSAGE =
    "If this is a new business, we have sent a sign-in link.";

const MERCHANT_LANDING_HEADING = "Serve Better. Run Smarter. Grow Steadily.";

const OWNER = {
    id: "01J0000000000000000000USER",
    email: "owner@example.com",
    name: "Paul Nderitu",
    status: "active",
    email_verified_at: null,
    is_platform_staff: false,
} as const;

const MERCHANT_PENDING = {
    id: "01J000000000000000000MERCH",
    name: "Servana Demo Salon",
    slug: "servana-demo-salon",
    status: "pending_setup",
    service_fee_tier: null,
    setup_completed_at: null,
} as const;

const MERCHANT_ACTIVE = {
    ...MERCHANT_PENDING,
    status: "active",
    service_fee_tier: "split_tier",
    setup_completed_at: "2026-06-14T00:00:00+00:00",
} as const;

const MEMBERSHIP = {
    id: "01J00000000000000000MEMBER",
    role: "merchant_admin",
    status: "active",
} as const;

const PENDING_SETUP = {
    required: true,
    current_step: "service_fee_tier",
    completed_at: null,
} as const;

const COMPLETED_SETUP = {
    required: false,
    current_step: "done",
    completed_at: "2026-06-14T00:00:00+00:00",
} as const;

const UNAUTHENTICATED_ERROR = {
    error: {
        code: "unauthenticated",
        message: "Unauthenticated.",
        fields: {},
        meta: {},
    },
} as const;

function bootstrap(merchant: unknown, setup: unknown): unknown {
    return {
        data: {
            user: OWNER,
            merchant,
            membership: MEMBERSHIP,
            memberships: [MEMBERSHIP],
            permissions: [],
            branch_ids: [],
            setup,
        },
    };
}

async function fulfillJson(
    route: Route,
    body: unknown,
    status = 200,
): Promise<void> {
    await route.fulfill({
        status,
        contentType: "application/json",
        body: JSON.stringify(body),
    });
}

/**
 * Removes bootstrap handlers before switching the simulated authentication
 * state. This keeps the registration and setup accessibility scans isolated.
 */
async function clearBootstrapRoutes(page: Page): Promise<void> {
    await page.unroute(CSRF_ROUTE);
    await page.unroute(CURRENT_USER_ROUTE);
}

async function stubCsrfCookie(page: Page): Promise<void> {
    await page.route(CSRF_ROUTE, async (route) => {
        await route.fulfill({
            status: 204,
            body: "",
        });
    });
}

async function stubLoggedOut(page: Page): Promise<void> {
    await clearBootstrapRoutes(page);
    await stubCsrfCookie(page);

    await page.route(CURRENT_USER_ROUTE, async (route) => {
        await fulfillJson(route, UNAUTHENTICATED_ERROR, 401);
    });
}

async function stubPendingSetupOwner(page: Page): Promise<void> {
    await clearBootstrapRoutes(page);
    await stubCsrfCookie(page);

    await page.route(CURRENT_USER_ROUTE, async (route) => {
        await fulfillJson(route, bootstrap(MERCHANT_PENDING, PENDING_SETUP));
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

test.describe("Merchant onboarding UI", () => {
    test("self-registration submits and shows the uniform success state", async ({
        page,
    }) => {
        await stubLoggedOut(page);

        await page.route(SELF_REGISTER_ROUTE, async (route) => {
            await fulfillJson(
                route,
                {
                    message: REGISTRATION_SUCCESS_MESSAGE,
                },
                202,
            );
        });

        await page.goto("/auth/register");

        await page.locator("#owner_name").fill("Paul Nderitu");
        await page.locator("#business_name").fill("Servana Demo Salon");
        await page.locator("#email").fill("owner@example.com");

        const registrationResponse = page.waitForResponse((response) => {
            const pathname = new URL(response.url()).pathname;

            return (
                response.request().method() === "POST" &&
                pathname === "/api/v1/merchant-registration/self-register"
            );
        });

        await page.locator('button[type="submit"]').click();

        await registrationResponse;

        await expect(page.getByTestId("register-success")).toBeVisible();
    });

    test("pending_setup owner is routed to the first-time setup wizard", async ({
        page,
    }) => {
        await stubPendingSetupOwner(page);

        await page.goto("/merchant");

        await expect(page).toHaveURL(/\/onboarding\/first-time-setup\/?$/);

        await expect(
            page.getByRole("heading", {
                name: "Set up your business",
            }),
        ).toBeVisible();
    });

    test("completing the wizard activates the merchant and lands on the role landing", async ({
        page,
    }) => {
        let completed = false;

        await clearBootstrapRoutes(page);
        await stubCsrfCookie(page);

        await page.route(CURRENT_USER_ROUTE, async (route) => {
            const payload = completed
                ? bootstrap(MERCHANT_ACTIVE, COMPLETED_SETUP)
                : bootstrap(MERCHANT_PENDING, PENDING_SETUP);

            await fulfillJson(route, payload);
        });

        await page.route(FIRST_TIME_SETUP_ROUTE, async (route) => {
            completed = true;

            await fulfillJson(route, {
                data: {
                    merchant: MERCHANT_ACTIVE,
                    redirect: "merchant.landing",
                },
            });
        });

        await page.goto("/onboarding/first-time-setup");

        await expect(
            page.getByRole("heading", {
                name: "Set up your business",
            }),
        ).toBeVisible();

        await test.step("select the service fee tier", async () => {
            await page.locator("#service_fee_tier").selectOption("split_tier");

            await page
                .getByRole("button", {
                    name: "Continue",
                })
                .click();
        });

        await test.step("complete the merchant profile", async () => {
            await page.locator("#business_category").fill("Salon");

            await page.locator("#contact_phone").fill("+254700000000");

            await page
                .getByRole("button", {
                    name: "Continue",
                })
                .click();
        });

        await test.step("create the first branch", async () => {
            await page.locator("#branch_name").fill("Main Branch");

            await page.locator("#branch_code").fill("MAIN");

            await page
                .getByRole("button", {
                    name: "Continue",
                })
                .click();
        });

        await test.step("invite the initial staff members", async () => {
            await page.locator("#branch_manager_email").fill("bm@demo.co.ke");

            await page.locator("#hr_email").fill("hr@demo.co.ke");

            const setupResponse = page.waitForResponse((response) => {
                const pathname = new URL(response.url()).pathname;

                return (
                    response.request().method() === "POST" &&
                    pathname ===
                        "/api/v1/merchant-registration/first-time-setup"
                );
            });

            await page
                .getByRole("button", {
                    name: "Finish setup",
                })
                .click();

            await setupResponse;
        });

        await expect(page).toHaveURL(/\/merchant\/?$/);

        await expect(
            page.getByRole("heading", {
                name: MERCHANT_LANDING_HEADING,
            }),
        ).toBeVisible();
    });

    test("registration and setup wizard pages have no WCAG A/AA violations", async ({
        page,
    }) => {
        await stubLoggedOut(page);

        await test.step("axe scan: /auth/register", async () => {
            await page.goto("/auth/register");
            await expect(page.locator("body")).toBeVisible();
            await assertNoWcagViolations(page);
        });

        await stubPendingSetupOwner(page);

        await test.step("axe scan: /onboarding/first-time-setup", async () => {
            await page.goto("/onboarding/first-time-setup");
            await expect(page.locator("body")).toBeVisible();
            await assertNoWcagViolations(page);
        });
    });
});
